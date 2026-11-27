<?php

namespace Tests\Feature;

use App\Auth\SecurityFailure;
use App\Http\Controllers\Internal\CompanySessionValidationController;
use App\Models\User;
use App\Services\CompanyMembershipManager;
use App\Services\CompanyPermissionService;
use App\Services\CompanyRequestSignature;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CompanyMembershipSecurityTest extends TestCase
{
    private string $companyId;

    private string $keyFile;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('database.connections.sqlite.url', null);
        DB::purge('sqlite');
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        config()->set('company_security.enabled', true);
        config()->set('session.secure', true);
        config()->set('session.domain', null);
        $this->keyFile = tempnam(sys_get_temp_dir(), 'membership-signing-');
        file_put_contents($this->keyFile, str_repeat('k', 48));
        config()->set('company_security.callers.inventory-service.key_file', $this->keyFile);
        Route::middleware(['company.boundary', 'company.machine:session.validate', 'web', 'company.session'])
            ->match(['GET', 'POST'], '/api/internal/v2/session/validate', CompanySessionValidationController::class);
        $this->companyId = $this->company();
        $this->owner = User::factory()->create();
        $this->membership($this->owner, $this->companyId, 'owner');
        $this->withServerVariables(['SSL_CLIENT_VERIFY' => 'SUCCESS', 'SSL_CLIENT_S_DN_CN' => 'inventory-service']);
        $this->withSession(['company_security.started_at' => now()->timestamp, 'auth.password_confirmed_at' => now()->timestamp]);
    }

    protected function tearDown(): void
    {
        unlink($this->keyFile);
        parent::tearDown();
    }

    public function test_migrations_never_assign_existing_users_or_create_default_companies(): void
    {
        $user = User::factory()->create();
        $this->assertDatabaseMissing('company_memberships', ['user_id' => $user->id]);
        $this->assertSame(1, DB::table('companies')->count());
    }

    public function test_two_employees_can_use_one_company_without_individual_ownership(): void
    {
        $colleague = User::factory()->create();
        $this->membership($colleague, $this->companyId, 'inventory_manager');
        foreach ([$this->owner, $colleague] as $user) {
            $this->actingAs($user, 'web');
            $this->validateRead()->assertOk()->assertJsonPath('subject', (string) $user->id)->assertJsonPath('companyId', $this->companyId)->assertJsonPath('permissions', ['inventory.read']);
        }
    }

    public function test_roles_do_not_leak_between_company_memberships(): void
    {
        $otherCompany = $this->company();
        $this->membership($this->owner, $otherCompany, 'technician');
        $this->actingAs($this->owner, 'web');
        $this->validateRead('inventory.valuation.read')->assertOk();
        $this->validateRead('inventory.valuation.read', $otherCompany)->assertForbidden();
        $this->validateRead('inventory.read', $otherCompany)->assertOk();
        $this->validateRead('inventory.read', (string) Str::uuid())->assertForbidden();
    }

    public function test_signed_machine_request_without_session_is_not_authorized(): void
    {
        $this->validateRead()->assertUnauthorized();
    }

    public function test_suspended_membership_and_revoked_roles_are_effective_on_the_next_request(): void
    {
        $this->actingAs($this->owner, 'web');
        $this->validateRead()->assertOk();
        DB::table('company_memberships')->where('user_id', $this->owner->id)->update(['status' => 'suspended']);
        $this->validateRead()->assertForbidden();
        DB::table('company_memberships')->where('user_id', $this->owner->id)->update(['status' => 'active']);
        DB::table('company_membership_roles')->update(['revoked_at' => now()]);
        $this->validateRead()->assertForbidden();
    }

    public function test_read_introspection_cannot_validate_write_permissions_or_another_service_scope(): void
    {
        $this->actingAs($this->owner, 'web');
        $this->validateRead('inventory.stock.adjust')->assertForbidden();
        $this->validateRead('billing.read')->assertForbidden();
    }

    public function test_absolute_session_expiry_cannot_be_extended_by_activity(): void
    {
        $this->actingAs($this->owner, 'web')->withSession(['company_security.started_at' => now()->subSeconds(config('company_security.absolute_session_seconds'))->timestamp]);
        $this->validateRead()->assertUnauthorized();
    }

    public function test_internal_post_requires_real_csrf_even_with_machine_and_user_proofs(): void
    {
        $this->app['env'] = 'local';
        $this->actingAs($this->owner, 'web');
        $body = json_encode(['requiredPermissions' => ['inventory.stock.adjust'], 'purpose' => 'resource.write'], JSON_THROW_ON_ERROR);
        $uri = '/api/internal/v2/session/validate';
        $this->withHeaders($this->headers('POST', $uri, $body))->call('POST', 'https://localhost'.$uri, [], [], [], $this->transformHeadersToServerVars($this->headers('POST', $uri, $body)), $body)->assertStatus(419);
    }

    public function test_administrator_cannot_delegate_financial_permissions_outside_its_authority(): void
    {
        $administrator = User::factory()->create();
        $this->membership($administrator, $this->companyId, 'administrator');
        $target = User::factory()->create();
        try {
            app(CompanyMembershipManager::class)->change($this->managementRequest($administrator), $this->companyId, (string) $target->id, [], ['billing.read', 'billing.refunds.record'], 'active');
            $this->fail('Financial escalation must be denied.');
        } catch (SecurityFailure $failure) {
            $this->assertSame(403, $failure->status);
        }
        $this->assertDatabaseMissing('company_memberships', ['user_id' => $target->id]);
    }

    public function test_last_owner_is_preserved_without_removing_any_user(): void
    {
        $this->expectException(SecurityFailure::class);
        app(CompanyMembershipManager::class)->change($this->managementRequest($this->owner), $this->companyId, (string) $this->owner->id, ['administrator'], [], 'active');
    }

    public function test_explicit_membership_change_is_audited_and_keeps_revoked_links(): void
    {
        $colleague = User::factory()->create();
        $membershipId = $this->membership($colleague, $this->companyId, 'technician');
        app(CompanyMembershipManager::class)->change($this->managementRequest($this->owner), $this->companyId, (string) $colleague->id, ['service_advisor'], [], 'active');
        $this->assertDatabaseHas('company_membership_roles', ['membership_id' => $membershipId, 'role_id' => 'service_advisor', 'revoked_at' => null]);
        $this->assertNotNull(DB::table('company_membership_roles')->where('membership_id', $membershipId)->where('role_id', 'technician')->value('revoked_at'));
        $this->assertDatabaseHas('companies', ['id' => $this->companyId, 'authorization_version' => 2]);
        $this->assertDatabaseHas('company_security_audits', ['action' => 'membership.changed']);
        $this->assertDatabaseCount('users', 2);
    }

    public function test_recent_authentication_is_required_for_sensitive_permissions(): void
    {
        $permissions = app(CompanyPermissionService::class);
        $permissions->requireRecentAuthentication(now()->timestamp);
        $this->expectException(SecurityFailure::class);
        $permissions->requireRecentAuthentication(now()->subMinutes(6)->timestamp);
    }

    public function test_denied_membership_change_leaves_authorizations_unchanged(): void
    {
        $colleague = User::factory()->create();
        $request = $this->managementRequest($this->owner);
        $request->session()->put('auth.password_confirmed_at', now()->subMinutes(6)->timestamp);
        try {
            app(CompanyMembershipManager::class)->change($request, $this->companyId, (string) $colleague->id, ['owner'], [], 'active');
            $this->fail('Stale authentication must be denied.');
        } catch (SecurityFailure $failure) {
            $this->assertSame('RECENT_AUTHENTICATION_REQUIRED', $failure->errorCode);
        }
        $this->assertDatabaseCount('company_memberships', 1);
        $this->assertDatabaseCount('company_security_audits', 0);
    }

    public function test_login_refresh_and_logout_preserve_then_invalidate_the_v2_session(): void
    {
        $this->owner->forceFill(['password' => 'synthetic-password'])->save();
        $this->postJson('/login', ['email' => $this->owner->email, 'password' => 'synthetic-password'])->assertNoContent();
        $this->validateRead()->assertOk();
        $this->validateRead()->assertOk();
        $this->postJson('/logout')->assertNoContent();
        $this->validateRead()->assertUnauthorized();
    }

    public function test_financial_step_up_is_checked_on_the_actual_signed_csrf_protected_endpoint(): void
    {
        $this->app['env'] = 'local';
        config()->set('company_security.callers.billing-service.key_file', $this->keyFile);
        $this->actingAs($this->owner, 'web')->withSession(['auth.password_confirmed_at' => now()->subMinutes(6)->timestamp]);
        $this->validateWrite('billing.refunds.record', 'billing-service')->assertForbidden()->assertJsonPath('error.code', 'RECENT_AUTHENTICATION_REQUIRED');
        $this->withSession(['auth.password_confirmed_at' => now()->timestamp]);
        $this->validateWrite('billing.refunds.record', 'billing-service')->assertOk();
        $this->assertDatabaseHas('company_security_audits', ['action' => 'sensitive.authorization.denied']);
        $this->assertDatabaseHas('company_security_audits', ['action' => 'sensitive.authorization.granted']);
    }

    public function test_membership_and_audit_are_atomic_when_audit_storage_fails(): void
    {
        $target = User::factory()->create();
        DB::unprepared("CREATE TRIGGER reject_synthetic_audit BEFORE INSERT ON company_security_audits BEGIN SELECT RAISE(ABORT, 'synthetic audit unavailable'); END");
        try {
            app(CompanyMembershipManager::class)->change($this->managementRequest($this->owner), $this->companyId, (string) $target->id, ['technician'], [], 'active');
            $this->fail('No authorization change may commit without its audit.');
        } catch (SecurityFailure $failure) {
            $this->assertSame(503, $failure->status);
        }
        $this->assertDatabaseMissing('company_memberships', ['user_id' => $target->id]);
        $this->assertDatabaseHas('companies', ['id' => $this->companyId, 'authorization_version' => 1]);
    }

    private function validateWrite(string $permission, string $caller): TestResponse
    {
        $token = Str::random(40);
        $this->withSession(['_token' => $token]);
        $csrf = app('encrypter')->encrypt(CookieValuePrefix::create('XSRF-TOKEN', app('encrypter')->getKey()).$token, false);
        $uri = '/api/internal/v2/session/validate';
        $body = json_encode(['requiredPermissions' => [$permission], 'purpose' => 'resource.write'], JSON_THROW_ON_ERROR);
        $headers = $this->headers('POST', $uri, $body);
        $headers['X-Internal-Service'] = $caller;
        $headers['X-XSRF-TOKEN'] = $csrf;
        $headers['X-Internal-Signature'] = hash_hmac('sha256', app(CompanyRequestSignature::class)->canonical('POST', $uri, $headers, $body), str_repeat('k', 48));
        $this->withServerVariables(['SSL_CLIENT_VERIFY' => 'SUCCESS', 'SSL_CLIENT_S_DN_CN' => $caller]);

        return $this->call('POST', 'https://localhost'.$uri, [], [], [], $this->transformHeadersToServerVars($headers), $body);
    }

    private function validateRead(string $permission = 'inventory.read', ?string $companyId = null): TestResponse
    {
        $uri = '/api/internal/v2/session/validate?'.http_build_query(['requiredPermissions' => $permission, 'purpose' => 'resource.read'], '', '&', PHP_QUERY_RFC3986);

        return $this->withHeaders($this->headers('GET', $uri, '', $companyId))->get('https://localhost'.$uri, ['Accept' => 'application/json']);
    }

    private function headers(string $method, string $uri, string $body = '', ?string $companyId = null): array
    {
        $headers = ['X-Internal-Service' => 'inventory-service', 'X-Company-ID' => $companyId ?? $this->companyId, 'X-Request-ID' => (string) Str::uuid(), 'X-Internal-Timestamp' => (string) now()->timestamp, 'X-Internal-Nonce' => Str::random(32)];
        if ($method === 'POST') {
            $headers['Content-Type'] = 'application/json';
        }
        $headers['X-Internal-Signature'] = hash_hmac('sha256', app(CompanyRequestSignature::class)->canonical($method, $uri, $headers, $body), str_repeat('k', 48));

        return $headers;
    }

    private function company(): string
    {
        $companyId = (string) Str::uuid();
        DB::table('companies')->insert(['id' => $companyId, 'display_name' => 'Synthetic company', 'status' => 'active', 'authorization_version' => 1]);

        return $companyId;
    }

    private function membership(User $user, string $companyId, string $role): string
    {
        $id = (string) Str::uuid();
        DB::table('company_memberships')->insert(['id' => $id, 'company_id' => $companyId, 'user_id' => $user->id, 'status' => 'active']);
        DB::table('company_membership_roles')->insert(['membership_id' => $id, 'role_id' => $role, 'revoked_at' => null]);

        return $id;
    }

    private function managementRequest(User $actor): Request
    {
        $request = Request::create('/not-exposed', 'POST');
        $request->headers->set('X-Request-ID', (string) Str::uuid());
        $request->setUserResolver(fn () => $actor);
        $session = new Store('synthetic', new ArraySessionHandler(120));
        $session->put('auth.password_confirmed_at', now()->timestamp);
        $request->setLaravelSession($session);

        return $request;
    }
}
