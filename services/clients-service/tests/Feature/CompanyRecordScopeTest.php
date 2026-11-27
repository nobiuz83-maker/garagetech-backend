<?php

namespace Tests\Feature;

use App\Auth\CompanyActor;
use App\Models\Client;
use App\Services\CompanyRecordScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\IsolatedDatabaseTransactions;
use Tests\TestCase;

final class CompanyRecordScopeTest extends TestCase
{
    use IsolatedDatabaseTransactions;

    public function test_legacy_client_records_stay_with_the_assigned_company_after_the_owner_joins_another_company(): void
    {
        $syntheticCompanyId = (string) Str::uuid();
        $vicartechCompanyId = (string) Str::uuid();
        $legacyClientId = (string) Str::uuid();
        $newClientId = (string) Str::uuid();
        DB::table('owner_subjects')->insert(['subject_id' => 1]);
        DB::table('company_subjects')->insert([
            ['company_id' => $syntheticCompanyId, 'status' => 'active', 'directory_version' => 1],
            ['company_id' => $vicartechCompanyId, 'status' => 'active', 'directory_version' => 1],
        ]);
        foreach ([[$legacyClientId, null], [$newClientId, $vicartechCompanyId]] as [$clientId, $companyId]) {
            DB::table('clients')->insert([
                'id' => $clientId, 'user_id' => 1, 'company_id' => $companyId,
                'first_name' => 'Garage', 'last_name' => 'Customer', 'phone' => '', 'email' => '',
                'data' => json_encode(['schemaVersion' => 1], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('legacy_client_company_assignments')->insert([
            'client_id' => $legacyClientId, 'company_id' => $syntheticCompanyId, 'created_at' => now(),
        ]);
        $scope = app(CompanyRecordScope::class);

        $this->assertSame([$legacyClientId], $scope->clients(Client::query(), $this->actor($syntheticCompanyId))->orderBy('id')->pluck('id')->all());
        $this->assertSame([$newClientId], $scope->clients(Client::query(), $this->actor($vicartechCompanyId))->orderBy('id')->pluck('id')->all());
    }

    private function actor(string $companyId): CompanyActor
    {
        return new CompanyActor('1', $companyId, (string) Str::uuid(), 1, ['clients.read'], (string) Str::uuid());
    }
}
