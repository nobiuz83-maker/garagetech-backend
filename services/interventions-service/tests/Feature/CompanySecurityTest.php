<?php

namespace Tests\Feature;

use App\Auth\CompanyActor;
use App\Auth\SecurityFailure;
use App\Policies\CompanyResourceAccess;
use App\Services\CompanyRequestSignature;
use App\Services\SensitiveOperationAuthorization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class CompanySecurityTest extends TestCase
{
    private string $keyFile;

    private string $companyId;

    private string $requestId;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('database.connections.sqlite.url', null);
        DB::purge('sqlite');
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        config()->set('company_security.enabled', true);
        $this->prefix = config('company_security.permission_prefix');
        $this->companyId = (string) Str::uuid();
        $this->requestId = (string) Str::uuid();
        DB::table('company_subjects')->insert(['company_id' => $this->companyId, 'status' => 'active', 'directory_version' => 1]);
        $this->keyFile = tempnam(sys_get_temp_dir(), 'company-security-');
        file_put_contents($this->keyFile, str_repeat('k', 48));
        config()->set('company_security.auth_url', 'https://auth-service:8443');
        foreach (['ca_certificate_file', 'client_certificate_file', 'client_key_file', 'signing_key_file'] as $setting) {
            config()->set('company_security.'.$setting, $this->keyFile);
        }
        Http::preventStrayRequests();
        Route::middleware(['company.boundary', 'company.permission:'.$this->prefix.'.read'])->get('/security-test/read', fn (Request $request): array => ['subject' => $request->user()->subject, 'companyId' => $request->user()->companyId]);
        Route::middleware(['company.boundary', 'company.permission:'.$this->writePermission()])->post('/security-test/write', fn (): array => ['accepted' => true]);
        config()->set('company_security.callers.test-source', ['key_file' => $this->keyFile, 'capabilities' => ['test.receive']]);
        Route::middleware(['company.boundary', 'company.machine:test.receive'])->get('/security-test/machine', fn (): array => ['accepted' => true]);
        $this->withHeaders(['X-Company-ID' => $this->companyId, 'X-Request-ID' => $this->requestId])->withCredentials();
    }

    protected function tearDown(): void
    {
        unlink($this->keyFile);
        parent::tearDown();
    }

    public function test_disabled_foundation_preserves_public_health_and_refuses_protected_access(): void
    {
        config()->set('company_security.enabled', false);
        $this->fakeHttp();
        $this->getJson('/security-test/read')->assertStatus(503);
        $this->getJson('/api/health')->assertOk();
        Http::assertNothingSent();
    }

    public function test_session_and_company_are_required_without_local_user_fallback(): void
    {
        $this->fakeHttp();
        $this->getJson('/security-test/read')->assertUnauthorized();
        $this->withUnencryptedCookie('vicartech-auth-session', 'opaque')->withHeader('X-Company-ID', '')->getJson('/security-test/read')->assertStatus(400);
        Http::assertNothingSent();
    }

    public function test_forged_browser_identity_is_ignored_and_every_request_is_validated(): void
    {
        $this->fakeHttp(['*' => Http::response($this->identity())]);
        $this->withUnencryptedCookie('vicartech-auth-session', 'opaque')->withHeader('X-User-ID', '999')->getJson('/security-test/read')->assertOk()->assertJsonPath('subject', '17');
        $this->getJson('/security-test/read')->assertOk();
        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => $request->hasHeader('Cookie', 'vicartech-auth-session=opaque') && ! $request->hasHeader('X-User-ID') && $request->hasHeader('X-Company-ID', $this->companyId));
    }

    public function test_mismatched_or_malformed_success_responses_fail_closed(): void
    {
        foreach ([['companyId' => (string) Str::uuid()], ['requestId' => (string) Str::uuid()], ['allowed' => 'true'], ['allowed' => false], ['permissions' => ['billing.refunds.record']], ['authorizationVersion' => 0], ['membershipId' => '17'], ['subject' => 17], ['unexpected' => 'field']] as $change) {
            $this->fakeHttp(['*' => Http::response(array_replace($this->identity(), $change))]);
            $this->withUnencryptedCookie('vicartech-auth-session', 'opaque')->getJson('/security-test/read')->assertStatus(503);
        }
        $this->fakeHttp(['*' => Http::response('not-json')]);
        $this->getJson('/security-test/read')->assertStatus(503);
    }

    public function test_session_and_permission_denials_are_distinct_from_upstream_failures(): void
    {
        foreach ([401 => 'UNAUTHENTICATED', 403 => 'FORBIDDEN', 419 => 'CSRF_TOKEN_MISMATCH'] as $status => $code) {
            $this->fakeHttp(['*' => Http::response(['error' => ['code' => $code, 'requestId' => $this->requestId]], $status)]);
            $this->withUnencryptedCookie('vicartech-auth-session', 'opaque')->getJson('/security-test/read')->assertStatus($status);
        }
        foreach ([401, 302, 500, 503] as $status) {
            $this->fakeHttp(['*' => Http::response(['error' => ['code' => 'MACHINE_AUTHENTICATION_FAILED', 'requestId' => $this->requestId]], $status)]);
            $this->getJson('/security-test/read')->assertStatus(503);
        }
    }

    public function test_outage_never_reuses_a_previously_successful_identity(): void
    {
        Http::fakeSequence()->push($this->identity())->pushResponse(Http::failedConnection('Connection refused or timed out.'));
        $this->withUnencryptedCookie('vicartech-auth-session', 'opaque')->getJson('/security-test/read')->assertOk();
        $this->getJson('/security-test/read')->assertStatus(503);
    }

    public function test_missing_tls_material_and_plaintext_auth_url_fail_closed(): void
    {
        $this->fakeHttp();
        config()->set('company_security.client_key_file', '/missing/key');
        $this->withUnencryptedCookie('vicartech-auth-session', 'opaque')->getJson('/security-test/read')->assertStatus(503);
        config()->set('company_security.client_key_file', $this->keyFile);
        config()->set('company_security.auth_url', 'http://auth-service');
        $this->getJson('/security-test/read')->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_unknown_or_pending_company_is_not_created_from_an_auth_response(): void
    {
        DB::table('company_subjects')->where('company_id', $this->companyId)->update(['status' => 'pending']);
        $this->fakeHttp(['*' => Http::response($this->identity())]);
        $this->withUnencryptedCookie('vicartech-auth-session', 'opaque')->getJson('/security-test/read')->assertStatus(503);
        $this->assertDatabaseCount('company_subjects', 1);
    }

    public function test_write_requires_csrf_idempotency_and_audit_without_storing_secrets(): void
    {
        $this->fakeHttp(['*' => Http::response($this->identity(true))]);
        $this->withUnencryptedCookie('vicartech-auth-session', 'opaque')->postJson('/security-test/write')->assertStatus(419);
        Http::assertNothingSent();
        $this->withHeader('X-XSRF-TOKEN', 'opaque-csrf')->postJson('/security-test/write')->assertStatus(400);
        $this->withHeader('Idempotency-Key', 'synthetic-intention-01')->postJson('/security-test/write')->assertOk();
        $this->assertDatabaseHas('company_security_audits', ['action' => 'command.authorized', 'actor_subject' => '17', 'company_id' => $this->companyId]);
        $this->assertStringNotContainsString('opaque', DB::table('company_security_audits')->first()->details);
        Http::assertSent(fn ($request): bool => $request->method() === 'POST' && $request->hasHeader('X-XSRF-TOKEN', 'opaque-csrf') && $request->data()['requiredPermissions'] === [$this->writePermission()]);
    }

    public function test_only_secure_host_only_session_cookies_are_relayed(): void
    {
        $this->fakeHttp(['*' => Http::response($this->identity(), 200, ['Set-Cookie' => ['vicartech-auth-session=renewed; Secure; HttpOnly; Path=/; SameSite=Lax', 'XSRF-TOKEN=csrf; Secure; Path=/; SameSite=Lax']])]);
        $response = $this->withUnencryptedCookie('vicartech-auth-session', 'opaque')->getJson('/security-test/read')->assertOk();
        $this->assertCount(2, $response->headers->getCookies());
        foreach (['Domain=example.com; Secure; HttpOnly; Path=/; SameSite=Lax', 'HttpOnly; Path=/; SameSite=Lax', 'Secure; Path=/; SameSite=Lax', 'Secure; HttpOnly; Path=/api; SameSite=Lax'] as $attributes) {
            $this->fakeHttp(['*' => Http::response($this->identity(), 200, ['Set-Cookie' => 'vicartech-auth-session=invalid; '.$attributes])]);
            $this->getJson('/security-test/read')->assertStatus(503);
        }
    }

    public function test_policy_refuses_a_foreign_company_even_for_a_permitted_subject(): void
    {
        $actor = new CompanyActor('17', $this->companyId, (string) Str::uuid(), 1, [$this->prefix.'.read'], $this->requestId);
        $this->expectException(SecurityFailure::class);
        app(CompanyResourceAccess::class)->authorize($actor, (string) Str::uuid(), $this->prefix.'.read');
    }

    public function test_machine_requires_verified_tls_and_rejects_replay(): void
    {
        $headers = $this->machineHeaders();
        $this->withHeaders($headers)->get('https://localhost/security-test/machine', ['Accept' => 'application/json'])->assertUnauthorized();
        $this->withServerVariables(['SSL_CLIENT_VERIFY' => 'SUCCESS', 'SSL_CLIENT_S_DN_CN' => 'test-source'])->withHeaders($headers)->get('https://localhost/security-test/machine', ['Accept' => 'application/json'])->assertOk();
        $this->withHeaders($headers)->get('https://localhost/security-test/machine', ['Accept' => 'application/json'])->assertUnauthorized();
    }

    public function test_machine_signature_binds_company_request_id_csrf_and_cookie(): void
    {
        $this->withServerVariables(['SSL_CLIENT_VERIFY' => 'SUCCESS', 'SSL_CLIENT_S_DN_CN' => 'test-source']);
        foreach (['X-Company-ID' => (string) Str::uuid(), 'X-Request-ID' => (string) Str::uuid(), 'X-XSRF-TOKEN' => 'changed', 'Cookie' => 'changed=value'] as $name => $value) {
            $headers = $this->machineHeaders();
            $headers[$name] = $value;
            $this->withHeaders($headers)->get('https://localhost/security-test/machine', ['Accept' => 'application/json'])->assertUnauthorized();
            $this->flushHeaders()->withHeaders(['X-Company-ID' => $this->companyId, 'X-Request-ID' => $this->requestId]);
        }
    }

    public function test_machine_identity_does_not_replace_a_user_session(): void
    {
        $this->fakeHttp();
        $this->withHeaders($this->machineHeaders())->getJson('/security-test/read')->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_configurable_separation_of_duties_supports_a_single_person_company(): void
    {
        $permission = $this->writePermission();
        $actor = new CompanyActor('17', $this->companyId, (string) Str::uuid(), 1, [$permission], $this->requestId);
        $authorization = app(SensitiveOperationAuthorization::class);
        $authorization->authorize($actor, $permission, '17');
        config()->set('company_security.distinct_author_permissions', [$permission => true]);
        $authorization->authorize($actor, $permission, '18');
        try {
            $authorization->authorize($actor, $permission, '17');
            $this->fail('An author cannot approve its own operation when separation is required.');
        } catch (SecurityFailure $failure) {
            $this->assertSame('SEPARATION_OF_DUTIES_REQUIRED', $failure->errorCode);
        }
        $this->assertDatabaseHas('company_security_audits', ['action' => 'sensitive.authorization.denied']);
    }

    public function test_company_specific_separation_policy_does_not_change_other_companies(): void
    {
        $permission = $this->writePermission();
        $actor = new CompanyActor('17', $this->companyId, (string) Str::uuid(), 1, [$permission], $this->requestId);
        DB::table('company_security_policies')->insert(['company_id' => $this->companyId, 'version' => 1,
            'data' => json_encode(['schemaVersion' => 1, 'distinctAuthorPermissions' => [$permission => true]])]);
        $authorization = app(SensitiveOperationAuthorization::class);
        try {
            $authorization->authorize($actor, $permission, '17');
            $this->fail('The company policy must require a distinct author.');
        } catch (SecurityFailure $failure) {
            $this->assertSame(403, $failure->status);
        }
        $otherCompany = (string) Str::uuid();
        DB::table('company_subjects')->insert(['company_id' => $otherCompany, 'status' => 'active', 'directory_version' => 1]);
        $otherActor = new CompanyActor('17', $otherCompany, (string) Str::uuid(), 1, [$permission], $this->requestId);
        $authorization->authorize($otherActor, $permission, '17');
        $this->assertDatabaseHas('company_security_audits', ['company_id' => $otherCompany, 'action' => 'sensitive.authorization.granted']);
    }

    public function test_security_audit_failure_refuses_the_command(): void
    {
        DB::unprepared("CREATE TRIGGER reject_synthetic_audit BEFORE INSERT ON company_security_audits BEGIN SELECT RAISE(ABORT, 'synthetic audit unavailable'); END");
        $this->fakeHttp(['*' => Http::response($this->identity(true))]);
        $this->withUnencryptedCookie('vicartech-auth-session', 'opaque')->withHeader('X-XSRF-TOKEN', 'opaque-csrf')
            ->withHeader('Idempotency-Key', 'synthetic-intention-01')->postJson('/security-test/write')->assertStatus(503);
    }

    public function test_company_resource_query_is_scoped_before_loading_rows(): void
    {
        $otherCompany = (string) Str::uuid();
        DB::table('company_subjects')->insert(['company_id' => $otherCompany, 'status' => 'active', 'directory_version' => 1]);
        foreach ([$this->companyId, $otherCompany] as $companyId) {
            DB::table('company_security_policies')->insert(['company_id' => $companyId, 'version' => 1, 'data' => '{"schemaVersion":1,"distinctAuthorPermissions":{}}']);
        }
        $model = new class extends Model
        {
            protected $table = 'company_security_policies';
        };
        $actor = new CompanyActor('17', $this->companyId, (string) Str::uuid(), 1, [$this->prefix.'.read'], $this->requestId);
        $rows = app(CompanyResourceAccess::class)->scope($actor, $model->newQuery())->get();
        $this->assertCount(1, $rows);
        $this->assertSame($this->companyId, $rows->first()->company_id);
    }

    public function test_malformed_company_security_policy_refuses_sensitive_operations(): void
    {
        $permission = $this->writePermission();
        DB::table('company_security_policies')->insert(['company_id' => $this->companyId, 'version' => 1,
            'data' => json_encode(['schemaVersion' => 1, 'distinctAuthorPermissions' => ['unknown.permission' => true]])]);
        $actor = new CompanyActor('17', $this->companyId, (string) Str::uuid(), 1, [$permission], $this->requestId);
        $this->expectException(SecurityFailure::class);
        app(SensitiveOperationAuthorization::class)->authorize($actor, $permission, '18');
    }

    private function fakeHttp(mixed $callback = null): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($callback);
    }

    private function writePermission(): string
    {
        return match ($this->prefix) {
            'inventory' => 'inventory.stock.adjust', 'interventions' => 'interventions.write', 'billing' => 'billing.invoices.write', 'accounting' => 'accounting.bills.write'
        };
    }

    private function identity(bool $write = false): array
    {
        return ['schemaVersion' => 2, 'subject' => '17', 'companyId' => $this->companyId, 'membershipId' => 'd96fc044-eeb5-4a26-876c-c20635cb5387', 'authorizationVersion' => 1, 'permissions' => [$write ? $this->writePermission() : $this->prefix.'.read'], 'purpose' => $write ? 'resource.write' : 'resource.read', 'requestId' => $this->requestId, 'allowed' => true];
    }

    private function machineHeaders(): array
    {
        $headers = ['X-Internal-Service' => 'test-source', 'X-Company-ID' => $this->companyId, 'X-Request-ID' => $this->requestId, 'X-Internal-Timestamp' => (string) now()->timestamp, 'X-Internal-Nonce' => Str::random(32), 'Content-Type' => 'application/json'];
        $headers['X-Internal-Signature'] = hash_hmac('sha256', app(CompanyRequestSignature::class)->canonical('GET', '/security-test/machine', $headers, ''), str_repeat('k', 48));

        return $headers;
    }
}
