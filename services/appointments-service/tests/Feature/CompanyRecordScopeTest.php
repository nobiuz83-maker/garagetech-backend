<?php

namespace Tests\Feature;

use App\Auth\CompanyActor;
use App\Models\Appointment;
use App\Services\CompanyRecordScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\IsolatedDatabaseTransactions;
use Tests\TestCase;

final class CompanyRecordScopeTest extends TestCase
{
    use IsolatedDatabaseTransactions;

    public function test_legacy_appointments_stay_with_the_assigned_company_after_the_owner_joins_another_company(): void
    {
        $syntheticCompanyId = (string) Str::uuid();
        $vicartechCompanyId = (string) Str::uuid();
        $legacyAppointmentId = (string) Str::uuid();
        $newAppointmentId = (string) Str::uuid();
        $clientId = (string) Str::uuid();
        $vehicleId = (string) Str::uuid();
        DB::table('owner_subjects')->insert(['subject_id' => 1]);
        DB::table('company_subjects')->insert([
            ['company_id' => $syntheticCompanyId, 'status' => 'active', 'directory_version' => 1],
            ['company_id' => $vicartechCompanyId, 'status' => 'active', 'directory_version' => 1],
        ]);
        DB::table('client_vehicle_associations')->insert([
            ['client_id' => $clientId, 'vehicle_id' => $vehicleId, 'user_id' => 1, 'company_id' => null],
        ]);
        foreach ([[$legacyAppointmentId, null], [$newAppointmentId, $vicartechCompanyId]] as [$appointmentId, $companyId]) {
            DB::table('appointments')->insert([
                'id' => $appointmentId, 'user_id' => 1, 'company_id' => $companyId,
                'client_id' => $clientId, 'vehicle_id' => $vehicleId,
                'date' => '2026-11-25', 'start_time' => '09:00:00', 'end_time' => '10:00:00',
                'status' => 'confirmed', 'service_type' => 'inspection', 'description' => '', 'notes' => '',
                'data' => json_encode(['schemaVersion' => 1], JSON_THROW_ON_ERROR), 'version' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('legacy_appointment_company_assignments')->insert([
            'appointment_id' => $legacyAppointmentId, 'company_id' => $syntheticCompanyId, 'created_at' => now(),
        ]);
        $scope = app(CompanyRecordScope::class);

        $this->assertSame([$legacyAppointmentId], $scope->appointments(Appointment::query(), $this->actor($syntheticCompanyId))->orderBy('id')->pluck('id')->all());
        $this->assertSame([$newAppointmentId], $scope->appointments(Appointment::query(), $this->actor($vicartechCompanyId))->orderBy('id')->pluck('id')->all());
    }

    private function actor(string $companyId): CompanyActor
    {
        return new CompanyActor('1', $companyId, (string) Str::uuid(), 1, ['appointments.read'], (string) Str::uuid());
    }
}
