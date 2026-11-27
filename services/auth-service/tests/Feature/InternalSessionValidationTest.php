<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class InternalSessionValidationTest extends TestCase
{
    use RefreshDatabase;

    private string $signingKeyFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signingKeyFile = tempnam(sys_get_temp_dir(), 'auth-signing-key-');
        file_put_contents($this->signingKeyFile, str_repeat('k', 48));
        config()->set('internal_auth.callers.clients-service.key_file', $this->signingKeyFile);
        config()->set('internal_auth.require_mtls', false);
    }

    protected function tearDown(): void
    {
        unlink($this->signingKeyFile);

        parent::tearDown();
    }

    public function test_a_signed_request_can_validate_a_permitted_authenticated_subject(): void
    {
        $user = User::factory()->create();
        $uri = '/api/internal/v1/session/validate?permission=clients.read';

        $this->actingAs($user)
            ->withHeaders($this->signedHeaders('GET', $uri))
            ->get($uri, ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('subject', (string) $user->id)
            ->assertJsonPath('permission', 'clients.read')
            ->assertJsonMissingPath('email');
    }

    public function test_a_caller_supplied_user_header_cannot_change_the_authenticated_subject(): void
    {
        $otherUser = User::factory()->create();
        $authenticatedUser = User::factory()->create();
        $uri = '/api/internal/v1/session/validate?permission=clients.read';
        $headers = array_merge($this->signedHeaders('GET', $uri), [
            'X-User-ID' => (string) $otherUser->id,
        ]);

        $this->actingAs($authenticatedUser)
            ->withHeaders($headers)
            ->get($uri, ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('subject', (string) $authenticatedUser->id);
    }

    public function test_a_caller_cannot_request_a_permission_owned_by_another_service(): void
    {
        $user = User::factory()->create();
        $uri = '/api/internal/v1/session/validate?permission=appointments.write';

        $this->actingAs($user)
            ->withHeaders($this->signedHeaders('GET', $uri))
            ->get($uri, ['Accept' => 'application/json'])
            ->assertForbidden();
    }

    public function test_a_valid_machine_signature_does_not_replace_a_user_session(): void
    {
        $uri = '/api/internal/v1/session/validate?permission=clients.read';

        $this->withHeaders($this->signedHeaders('GET', $uri))
            ->get($uri, ['Accept' => 'application/json'])
            ->assertUnauthorized();
    }

    public function test_an_unauthenticated_internal_request_without_accept_header_returns_401(): void
    {
        $uri = '/api/internal/v1/session/validate?permission=clients.read';

        $this->withHeaders($this->signedHeaders('GET', $uri))
            ->get($uri)
            ->assertUnauthorized();
    }

    public function test_invalid_and_replayed_machine_signatures_are_rejected(): void
    {
        $user = User::factory()->create();
        $uri = '/api/internal/v1/session/validate?permission=clients.read';
        $headers = $this->signedHeaders('GET', $uri);

        $this->actingAs($user)
            ->withHeaders(array_merge($headers, ['X-Internal-Signature' => str_repeat('0', 64)]))
            ->get($uri, ['Accept' => 'application/json'])
            ->assertUnauthorized();

        $this->withHeaders($headers)->get($uri, ['Accept' => 'application/json'])->assertOk();
        $this->withHeaders($headers)->get($uri, ['Accept' => 'application/json'])->assertUnauthorized();
    }

    public function test_internal_validation_requires_mutual_tls_even_with_a_valid_signature(): void
    {
        $user = User::factory()->create();
        $uri = '/api/internal/v1/session/validate?permission=clients.read';
        config()->set('internal_auth.require_mtls', true);

        $this->actingAs($user)
            ->withHeaders($this->signedHeaders('GET', $uri))
            ->get($uri, ['Accept' => 'application/json'])
            ->assertUnauthorized()
            ->assertJsonPath('error', 'MUTUAL_TLS_REQUIRED');
    }

    public function test_changing_a_signed_permission_invalidates_the_signature(): void
    {
        $user = User::factory()->create();
        $signedUri = '/api/internal/v1/session/validate?permission=clients.read';
        $changedUri = '/api/internal/v1/session/validate?permission=clients.write';

        $this->actingAs($user)
            ->withHeaders($this->signedHeaders('GET', $signedUri))
            ->get($changedUri, ['Accept' => 'application/json'])
            ->assertUnauthorized();
    }

    public function test_a_missing_configured_machine_key_reports_service_unavailability(): void
    {
        $user = User::factory()->create();
        $uri = '/api/internal/v1/session/validate?permission=clients.read';
        config()->set('internal_auth.callers.clients-service.key_file', '/missing/internal-machine-key');

        $this->actingAs($user)
            ->withHeaders($this->signedHeaders('GET', $uri))
            ->get($uri, ['Accept' => 'application/json'])
            ->assertStatus(503)
            ->assertJsonPath('error', 'INTERNAL_AUTH_UNAVAILABLE');
    }

    public function test_expired_machine_signatures_are_rejected(): void
    {
        $user = User::factory()->create();
        $uri = '/api/internal/v1/session/validate?permission=clients.read';

        $this->actingAs($user)
            ->withHeaders($this->signedHeaders('GET', $uri, now()->timestamp - 120))
            ->get($uri, ['Accept' => 'application/json'])
            ->assertUnauthorized();
    }

    public function test_write_permission_cannot_be_validated_with_a_read_request(): void
    {
        $user = User::factory()->create();
        $uri = '/api/internal/v1/session/validate?permission=clients.write';

        $this->actingAs($user)
            ->withHeaders($this->signedHeaders('GET', $uri))
            ->get($uri, ['Accept' => 'application/json'])
            ->assertForbidden();
    }

    /** @return array<string, string> */
    private function signedHeaders(string $method, string $uri, ?int $timestamp = null): array
    {
        $requestTimestamp = (string) ($timestamp ?? now()->timestamp);
        $nonce = Str::random(32);
        $canonicalRequest = implode("\n", [
            $method,
            $uri,
            $requestTimestamp,
            $nonce,
            hash('sha256', ''),
        ]);

        return [
            'X-Internal-Service' => 'clients-service',
            'X-Internal-Timestamp' => $requestTimestamp,
            'X-Internal-Nonce' => $nonce,
            'X-Internal-Signature' => hash_hmac('sha256', $canonicalRequest, str_repeat('k', 48)),
        ];
    }
}
