<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CompanySecurityMariaDbTest extends TestCase
{
    public function test_isolated_mariadb_migrations_constraints_json_and_database_permissions(): void
    {
        if (getenv('LOT1_MARIADB_ENABLED') !== 'true') {
            $this->markTestSkipped('Requires the separately provisioned, isolated LOT 1 MariaDB socket.');
        }
        $service = config('company_security.service_name');
        $shortName = str_replace('-service', '', $service);
        $database = $shortName === 'auth' ? 'auth_db' : 'lot1_'.$shortName;
        $this->assertSame('lot1_'.$shortName, getenv('LOT1_MARIADB_USER'));
        config()->set('database.connections.lot1_validation', [
            'driver' => 'mariadb', 'url' => null, 'unix_socket' => '/run/lot1-mariadb/mysqld.sock',
            'database' => $database, 'username' => getenv('LOT1_MARIADB_USER'),
            'password' => getenv('LOT1_MARIADB_PASSWORD'), 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '', 'strict' => true,
        ]);
        config()->set('database.default', 'lot1_validation');
        DB::purge('lot1_validation');
        $this->assertStringStartsWith('10.6.', DB::selectOne('SELECT VERSION() AS version')->version);
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        DB::beginTransaction();
        try {
            $companyId = (string) Str::uuid();
            if ($shortName === 'auth') {
                $userId = DB::table('users')->insertGetId(['name' => 'Synthetic', 'email' => Str::uuid().'@example.invalid', 'password' => 'not-a-real-password-hash']);
                DB::table('companies')->insert(['id' => $companyId, 'display_name' => 'Synthetic', 'status' => 'active', 'authorization_version' => 1]);
                $membershipId = (string) Str::uuid();
                DB::table('company_memberships')->insert(['id' => $membershipId, 'company_id' => $companyId, 'user_id' => $userId, 'status' => 'active']);
                $this->assertDatabaseHas('company_memberships', ['id' => $membershipId]);
                $this->expectConstraintFailure(fn () => DB::table('company_memberships')->insert(['id' => (string) Str::uuid(), 'company_id' => $companyId, 'user_id' => $userId, 'status' => 'active']));
                $this->expectConstraintFailure(fn () => DB::table('company_memberships')->insert(['id' => (string) Str::uuid(), 'company_id' => (string) Str::uuid(), 'user_id' => $userId, 'status' => 'active']));
            } else {
                DB::table('company_subjects')->insert(['company_id' => $companyId, 'status' => 'active', 'directory_version' => 1]);
                DB::table('company_security_policies')->insert(['company_id' => $companyId, 'version' => 1, 'data' => '{"schemaVersion":1,"distinctAuthorPermissions":{}}']);
                $this->expectConstraintFailure(fn () => DB::table('company_security_policies')->insert(['company_id' => (string) Str::uuid(), 'version' => 1, 'data' => '{"schemaVersion":1}']));
            }
            $audit = ['id' => (string) Str::uuid(), 'company_id' => $companyId, 'actor_subject' => '17', 'request_id' => (string) Str::uuid(), 'action' => 'synthetic.validation', 'details' => '{"schemaVersion":1}', 'occurred_at' => now()];
            DB::table('company_security_audits')->insert($audit);
            $this->expectConstraintFailure(fn () => DB::table('company_security_audits')->insert([...$audit, 'id' => (string) Str::uuid(), 'details' => '{invalid-json}']));
            DB::table('company_security_nonces')->insert(['caller' => 'synthetic', 'nonce_hash' => str_repeat('a', 64), 'expires_at' => now()]);
            $this->expectConstraintFailure(fn () => DB::table('company_security_nonces')->insert(['caller' => 'synthetic', 'nonce_hash' => str_repeat('a', 64), 'expires_at' => now()]));
            try {
                DB::select('SELECT User FROM mysql.user');
                $this->fail('A service credential must not read administrative or foreign databases.');
            } catch (QueryException $failure) {
                $this->assertContains($failure->errorInfo[1], [1044, 1142]);
            }
        } finally {
            // Only uncommitted synthetic fixtures are rolled back; persisted records are never removed.
            DB::rollBack();
        }
    }

    private function expectConstraintFailure(callable $operation): void
    {
        try {
            $operation();
            $this->fail('MariaDB must enforce the schema constraint.');
        } catch (QueryException $failure) {
            $this->assertContains($failure->errorInfo[1], [1062, 1452, 4025]);
        }
    }
}
