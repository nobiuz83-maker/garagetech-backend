<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ProvisionCompanyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('database.connections.sqlite.url', null);
        DB::purge('sqlite');
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    }

    public function test_provisions_a_company_for_the_confirmed_owner_without_changing_other_memberships(): void
    {
        $owner = User::factory()->create(['email' => 'owner@example.test']);
        $existingCompanyId = (string) str()->uuid();
        $existingMembershipId = (string) str()->uuid();
        DB::table('companies')->insert(['id' => $existingCompanyId, 'display_name' => 'Synthetic A', 'status' => 'active', 'authorization_version' => 1]);
        DB::table('company_memberships')->insert(['id' => $existingMembershipId, 'company_id' => $existingCompanyId, 'user_id' => $owner->id, 'status' => 'active']);
        DB::table('company_membership_roles')->insert(['membership_id' => $existingMembershipId, 'role_id' => 'owner']);

        $this->artisan('auth:provision-company', [
            'display-name' => 'ViCarTech', 'owner-id' => $owner->id, 'owner-email' => $owner->email,
        ])->assertSuccessful();

        $company = DB::table('companies')->where('display_name', 'ViCarTech')->first();
        $this->assertNotNull($company);
        $this->assertNotSame($existingCompanyId, $company->id);
        $this->assertDatabaseHas('company_memberships', ['company_id' => $company->id, 'user_id' => $owner->id, 'status' => 'active']);
        $this->assertDatabaseHas('company_membership_roles', ['membership_id' => DB::table('company_memberships')->where('company_id', $company->id)->value('id'), 'role_id' => 'owner', 'revoked_at' => null]);
        $this->assertDatabaseHas('company_memberships', ['id' => $existingMembershipId, 'company_id' => $existingCompanyId, 'status' => 'active']);
        $this->assertDatabaseHas('company_security_audits', ['company_id' => $company->id, 'action' => 'company.provisioned']);
    }

    public function test_rejects_a_mismatched_owner_email_without_creating_a_company(): void
    {
        $owner = User::factory()->create(['email' => 'owner@example.test']);

        $this->artisan('auth:provision-company', [
            'display-name' => 'ViCarTech', 'owner-id' => $owner->id, 'owner-email' => 'someone-else@example.test',
        ])->assertFailed();

        $this->assertDatabaseMissing('companies', ['display_name' => 'ViCarTech']);
    }

    public function test_does_not_create_a_duplicate_company_on_retry(): void
    {
        $owner = User::factory()->create(['email' => 'owner@example.test']);
        $arguments = ['display-name' => 'ViCarTech', 'owner-id' => $owner->id, 'owner-email' => $owner->email];

        $this->artisan('auth:provision-company', $arguments)->assertSuccessful();
        $this->artisan('auth:provision-company', $arguments)->assertSuccessful();

        $this->assertDatabaseCount('companies', 1);
        $this->assertDatabaseCount('company_memberships', 1);
    }
}
