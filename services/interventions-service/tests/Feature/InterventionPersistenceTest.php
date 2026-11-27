<?php

namespace Tests\Feature;

use App\Auth\CompanyActor;
use App\Models\Intervention;
use App\Policies\InterventionPolicy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class InterventionPersistenceTest extends TestCase
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

    public function test_an_intervention_cannot_reference_a_vehicle_from_another_company(): void
    {
        $firstCompanyId = (string) Str::uuid();
        $secondCompanyId = (string) Str::uuid();
        $clientId = (string) Str::uuid();
        $vehicleId = (string) Str::uuid();
        foreach ([$firstCompanyId, $secondCompanyId] as $companyId) {
            DB::table('company_subjects')->insert(['company_id' => $companyId, 'status' => 'active', 'directory_version' => 1]);
        }
        DB::table('intervention_client_vehicle_references')->insert([
            'company_id' => $firstCompanyId, 'client_id' => $clientId, 'vehicle_id' => $vehicleId,
        ]);

        $this->expectException(QueryException::class);
        Intervention::create([
            'company_id' => $secondCompanyId, 'client_id' => $clientId, 'vehicle_id' => $vehicleId,
            'start_date' => '2026-11-17', 'status' => 'planned', 'description' => 'Inspection',
            'diagnosis' => '', 'notes' => '', 'labor_rate_cents' => 10000, 'labor_hours' => '1.0000',
            'data' => ['schemaVersion' => 1],
        ]);
    }

    public function test_policy_requires_both_company_membership_and_the_requested_permission(): void
    {
        $companyId = (string) Str::uuid();
        $intervention = new Intervention(['company_id' => $companyId]);
        $reader = new CompanyActor('1', $companyId, (string) Str::uuid(), 1, ['interventions.read'], (string) Str::uuid());
        $foreignReader = new CompanyActor('2', (string) Str::uuid(), (string) Str::uuid(), 1, ['interventions.read'], (string) Str::uuid());
        $policy = new InterventionPolicy;

        $this->assertTrue($policy->view($reader, $intervention));
        $this->assertFalse($policy->update($reader, $intervention));
        $this->assertFalse($policy->view($foreignReader, $intervention));
    }
}
