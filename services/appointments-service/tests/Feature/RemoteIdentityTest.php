<?php

namespace Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as OutgoingRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RemoteIdentityTest extends TestCase
{
    private string $configurationDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withCredentials();

        $this->configurationDirectory = sys_get_temp_dir().'/identity-test-'.bin2hex(random_bytes(8));
        mkdir($this->configurationDirectory);
        foreach (['ca', 'client-cert', 'client-key', 'signing-key'] as $fileName) {
            file_put_contents($this->configurationDirectory.'/'.$fileName, str_repeat('k', 48));
        }

        config()->set('remote_auth.url', 'https://auth-service:8443');
        config()->set('remote_auth.ca_certificate_file', $this->configurationDirectory.'/ca');
        config()->set('remote_auth.client_certificate_file', $this->configurationDirectory.'/client-cert');
        config()->set('remote_auth.client_key_file', $this->configurationDirectory.'/client-key');
        config()->set('remote_auth.signing_key_file', $this->configurationDirectory.'/signing-key');
    }

    protected function tearDown(): void
    {
        foreach (['ca', 'client-cert', 'client-key', 'signing-key'] as $fileName) {
            unlink($this->configurationDirectory.'/'.$fileName);
        }
        rmdir($this->configurationDirectory);

        parent::tearDown();
    }

    public function test_a_missing_auth_cookie_is_rejected_without_contacting_auth(): void
    {
        Http::fake();

        $this->getJson('/api/internal/identity-check')->assertUnauthorized();

        Http::assertNothingSent();
    }

    public function test_a_valid_remote_identity_is_attached_without_trusting_a_user_header(): void
    {
        Http::fake(['*' => Http::response(['subject' => '17', 'permission' => 'appointments.read', 'allowed' => true], 200)]);

        $this->withUnencryptedCookie('vicartech-auth-session', 'opaque-session')
            ->withHeader('X-User-ID', '999')
            ->getJson('/api/internal/identity-check')
            ->assertOk()
            ->assertJsonPath('subject', '17');

        Http::assertSent(function (OutgoingRequest $request): bool {
            return $request->method() === 'GET'
                && str_contains($request->url(), '?permission=appointments.read')
                && $request->hasHeader('X-Internal-Service', 'appointments-service')
                && $request->hasHeader('Cookie', 'vicartech-auth-session=opaque-session')
                && ! $request->hasHeader('X-User-ID');
        });
    }

    public function test_a_write_forwards_the_csrf_token_and_uses_the_write_permission(): void
    {
        Http::fake(['*' => Http::response(['subject' => '17', 'permission' => 'appointments.write', 'allowed' => true], 200)]);

        $this->withUnencryptedCookie('vicartech-auth-session', 'opaque-session')
            ->withHeader('X-XSRF-TOKEN', 'browser-csrf-token')
            ->postJson('/api/internal/identity-check')
            ->assertOk();

        Http::assertSent(fn (OutgoingRequest $request): bool => $request->method() === 'POST'
            && $request->hasHeader('X-XSRF-TOKEN', 'browser-csrf-token')
            && $request->body() === '{"permission":"appointments.write"}'
        );
    }

    public function test_authentication_and_authorization_failures_remain_distinct(): void
    {
        Http::fakeSequence()
            ->push(['error' => 'UNAUTHENTICATED'], 401)
            ->push(['error' => 'FORBIDDEN'], 403)
            ->push(['error' => 'CSRF_FAILED'], 419);

        foreach ([401, 403, 419] as $status) {
            $this->withUnencryptedCookie('vicartech-auth-session', 'opaque-session')
                ->getJson('/api/internal/identity-check')
                ->assertStatus($status);
        }
    }

    public function test_server_failure_and_invalid_success_responses_fail_closed(): void
    {
        Http::fakeSequence()
            ->push(['error' => 'SERVER_ERROR'], 500)
            ->push(['subject' => '17', 'permission' => 'appointments.read', 'allowed' => false], 200)
            ->push('invalid-json', 200);

        for ($caseNumber = 0; $caseNumber < 3; $caseNumber++) {
            $this->withUnencryptedCookie('vicartech-auth-session', 'opaque-session')
                ->getJson('/api/internal/identity-check')
                ->assertStatus(503);
        }
    }

    public function test_connection_failure_and_missing_tls_configuration_fail_closed(): void
    {
        Http::fake(fn (): never => throw new ConnectionException('Connection refused.'));

        $this->withUnencryptedCookie('vicartech-auth-session', 'opaque-session')
            ->getJson('/api/internal/identity-check')
            ->assertStatus(503);

        config()->set('remote_auth.client_key_file', '/missing/client-key');
        $this->withUnencryptedCookie('vicartech-auth-session', 'opaque-session')
            ->getJson('/api/internal/identity-check')
            ->assertStatus(503);
    }

    public function test_renewed_auth_cookies_are_relayed_as_separate_headers(): void
    {
        Http::fake(['*' => Http::response(
            ['subject' => '17', 'permission' => 'appointments.read', 'allowed' => true],
            200,
            ['Set-Cookie' => [
                'vicartech-auth-session=renewed-session; Path=/; HttpOnly; SameSite=Lax',
                'XSRF-TOKEN=renewed-csrf; Path=/; SameSite=Lax',
            ]],
        )]);

        $response = $this->withUnencryptedCookie('vicartech-auth-session', 'opaque-session')
            ->getJson('/api/internal/identity-check')->assertOk();

        $this->assertCount(2, $response->headers->getCookies());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_cookies_with_an_unexpected_domain_fail_closed(): void
    {
        Http::fake(['*' => Http::response(
            ['subject' => '17', 'permission' => 'appointments.read', 'allowed' => true],
            200,
            ['Set-Cookie' => 'vicartech-auth-session=value; Domain=example.com; Path=/; HttpOnly'],
        )]);

        $this->withUnencryptedCookie('vicartech-auth-session', 'opaque-session')
            ->getJson('/api/internal/identity-check')->assertStatus(503);
    }

    public function test_a_timeout_never_reuses_a_previous_successful_identity(): void
    {
        Http::fakeSequence()
            ->push(['subject' => '17', 'permission' => 'appointments.read', 'allowed' => true], 200)
            ->pushResponse(Http::failedConnection('Operation timed out.'));

        $this->withUnencryptedCookie('vicartech-auth-session', 'opaque-session')
            ->getJson('/api/internal/identity-check')->assertOk();
        $this->withUnencryptedCookie('vicartech-auth-session', 'opaque-session')
            ->getJson('/api/internal/identity-check')->assertStatus(503);
    }

    public function test_a_machine_authentication_failure_is_not_reported_as_a_browser_session_failure(): void
    {
        Http::fake(['*' => Http::response(['error' => 'INVALID_REQUEST_SIGNATURE'], 401)]);

        $this->withUnencryptedCookie('vicartech-auth-session', 'opaque-session')
            ->getJson('/api/internal/identity-check')->assertStatus(503);
    }
}
