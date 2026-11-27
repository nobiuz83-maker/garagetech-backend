<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\IsolatedDatabaseTransactions;
use Tests\TestCase;

class AppointmentApiTest extends TestCase
{
    use IsolatedDatabaseTransactions;

    private function input(Vehicle $vehicle): array
    {
        return [
            'clientId' => $vehicle->client_id, 'vehicleId' => $vehicle->id, 'date' => '2027-01-12',
            'startTime' => '09:00', 'endTime' => '10:00', 'status' => 'scheduled',
            'serviceId' => 'ENGINE_OIL_CHANGE', 'serviceType' => 'Oil change', 'description' => 'Replace oil',
            'notes' => '', 'address' => '',
        ];
    }

    public function test_guests_receive_401_on_appointment_and_schedule_endpoints(): void
    {
        $this->getJson('/api/v1/appointments')->assertUnauthorized();
        $this->postJson('/api/v1/appointments', [])->assertUnauthorized();
        $this->postJson('/api/v1/appointments/import', [])->assertUnauthorized();
        $this->getJson('/api/v1/appointment-schedule')->assertUnauthorized();
    }

    public function test_creation_update_cancellation_and_archiving_preserve_relations_and_history(): void
    {
        $vehicle = Vehicle::factory()->create();
        Sanctum::actingAs($vehicle->client->user);
        $input = $this->input($vehicle);
        $created = $this->postJson('/api/v1/appointments', $input)->assertCreated()
            ->assertJsonPath('data.date', '2027-01-12')->assertJsonPath('data.version', 1);
        $identifier = $created->json('data.id');
        $this->assertDatabaseHas('appointments', ['id' => $identifier, 'vehicle_id' => $vehicle->id, 'client_id' => $vehicle->client_id]);
        $this->putJson('/api/v1/appointments/'.$identifier, [...$input, 'description' => 'Updated', 'version' => 1])
            ->assertOk()->assertJsonPath('data.description', 'Updated')->assertJsonPath('data.version', 2);
        $this->getJson('/api/v1/appointments/'.$identifier)->assertJsonPath('data.description', 'Updated');
        $this->postJson('/api/v1/appointments/'.$identifier.'/cancel', ['version' => 2])
            ->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.version', 3);
        $this->deleteJson('/api/v1/appointments/'.$identifier, ['version' => 3])->assertNoContent();
        $this->assertSoftDeleted('appointments', ['id' => $identifier]);
        $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id, 'client_id' => $vehicle->client_id]);
        $this->getJson('/api/v1/appointments/'.$identifier)->assertNotFound();
    }

    public function test_cross_account_access_is_forbidden_and_vehicle_client_mismatch_returns_422(): void
    {
        $appointment = Appointment::factory()->create();
        $vehicle = Vehicle::factory()->create();
        Sanctum::actingAs($vehicle->client->user);
        $identifier = $appointment->id;
        $this->getJson('/api/v1/appointments')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/appointments/'.$identifier)->assertForbidden();
        $this->putJson('/api/v1/appointments/'.$identifier, [...$this->input($vehicle), 'version' => 1])->assertForbidden();
        $this->deleteJson('/api/v1/appointments/'.$identifier, ['version' => 1])->assertForbidden();
        $this->postJson('/api/v1/appointments/'.$identifier.'/cancel', ['version' => 1])->assertForbidden();
        $this->postJson('/api/v1/appointments', [...$this->input($vehicle), 'vehicleId' => $appointment->vehicle_id])
            ->assertUnprocessable()->assertJsonPath('errors.vehicleId.0', 'APPOINTMENT_VEHICLE_REQUIRED');
        $this->postJson('/api/v1/appointments', [...$this->input($vehicle), 'clientId' => $appointment->client_id])
            ->assertUnprocessable()->assertJsonPath('errors.clientId.0', 'APPOINTMENT_CLIENT_REQUIRED');
        $this->assertDatabaseCount('appointments', 1);
    }

    #[TestWith(['date', '2027-02-30'])]
    #[TestWith(['startTime', '25:00'])]
    #[TestWith(['endTime', '08:59'])]
    #[TestWith(['endTime', '09:00'])]
    #[TestWith(['status', 'unknown'])]
    #[TestWith(['description', ''])]
    #[TestWith(['data', ['schemaVersion' => 2], 'data.schemaVersion'])]
    #[TestWith(['data', ['schemaVersion' => 1, 'clientId' => 'unknown']])]
    #[TestWith(['privileged', true])]
    public function test_invalid_input_returns_422_without_writing(string $property, mixed $value, ?string $errorProperty = null): void
    {
        $vehicle = Vehicle::factory()->create();
        Sanctum::actingAs($vehicle->client->user);
        $this->postJson('/api/v1/appointments', [...$this->input($vehicle), $property => $value])
            ->assertUnprocessable()->assertJsonValidationErrors($errorProperty ?? $property);
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_search_date_status_and_relationship_filters_are_account_scoped(): void
    {
        $appointment = Appointment::factory()->create(['description' => 'Special brakes']);
        Appointment::factory()->create(['description' => 'Special brakes']);
        Appointment::factory()->for($appointment->user)->for($appointment->client)->for($appointment->vehicle)
            ->create(['date' => '2027-01-13', 'status' => 'completed']);
        Sanctum::actingAs($appointment->user);
        $this->getJson('/api/v1/appointments?search=Special&date=2027-01-12&status=scheduled&clientId='.$appointment->client_id.'&vehicleId='.$appointment->vehicle_id)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $appointment->id);
        $this->getJson('/api/v1/appointments?from=2027-01-13&to=2027-01-14&status=completed&perPage=1')
            ->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/appointments?perPage=101')->assertUnprocessable();
        $this->getJson('/api/v1/appointments?to=2027-01-12')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_overlaps_are_refused_but_cancelled_bookings_release_the_slot(): void
    {
        $appointment = Appointment::factory()->create();
        Sanctum::actingAs($appointment->user);
        $input = $this->input($appointment->vehicle);
        $this->postJson('/api/v1/appointments', [...$input, 'startTime' => '09:30'])->assertUnprocessable()
            ->assertJsonPath('errors.startTime.0', 'APPOINTMENT_CONFLICT');
        $this->postJson('/api/v1/appointments', [...$input, 'startTime' => '10:00', 'endTime' => '11:00'])->assertCreated();
        $this->postJson('/api/v1/appointments/'.$appointment->id.'/cancel', ['version' => 1])->assertOk();
        $this->postJson('/api/v1/appointments', $input)->assertCreated();
    }

    public function test_schedule_enforces_hours_breaks_lead_time_and_configurable_overlap_gap(): void
    {
        $appointment = Appointment::factory()->create();
        Sanctum::actingAs($appointment->user);
        $schedule = ['workDays' => [1, 2, 3, 4, 5], 'openingTime' => '08:00', 'closingTime' => '18:00',
            'defaultDurationMinutes' => 60, 'intervalMinutes' => 15, 'breakStart' => '12:00', 'breakEnd' => '13:00', 'preventOverlaps' => true, 'bookingLeadMinutes' => 30];
        $this->putJson('/api/v1/appointment-schedule', ['schemaVersion' => 1, 'schedule' => $schedule])->assertOk();
        $this->getJson('/api/v1/appointment-schedule')->assertJsonPath('data.schedule.intervalMinutes', 15);
        $this->travelTo(CarbonImmutable::parse('2027-01-12 08:00', 'America/Toronto'));
        $input = $this->input($appointment->vehicle);
        $this->postJson('/api/v1/appointments', [...$input, 'startTime' => '10:00', 'endTime' => '11:00'])->assertUnprocessable()
            ->assertJsonPath('errors.startTime.0', 'APPOINTMENT_CONFLICT');
        $this->postJson('/api/v1/appointments', [...$input, 'startTime' => '12:00', 'endTime' => '13:00'])->assertUnprocessable()
            ->assertJsonPath('errors.startTime.0', 'APPOINTMENT_OUTSIDE_HOURS');
        $this->postJson('/api/v1/appointments', [...$input, 'startTime' => '08:00', 'endTime' => '08:15'])->assertUnprocessable()
            ->assertJsonPath('errors.startTime.0', 'APPOINTMENT_BOOKING_LEAD');
        $this->putJson('/api/v1/appointment-schedule', ['schemaVersion' => 1, 'schedule' => [...$schedule, 'preventOverlaps' => false]])->assertOk();
        $this->postJson('/api/v1/appointments', $input)->assertCreated();
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/appointment-schedule')->assertOk()->assertJsonPath('data', null);
    }

    public function test_nonexistent_toronto_time_returns_422(): void
    {
        $vehicle = Vehicle::factory()->create();
        Sanctum::actingAs($vehicle->client->user);
        $this->postJson('/api/v1/appointments', [...$this->input($vehicle), 'date' => '2027-03-14', 'startTime' => '02:30', 'endTime' => '04:00'])
            ->assertUnprocessable()->assertJsonPath('errors.startTime.0', 'APPOINTMENT_TIME_INVALID');
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_stale_updates_and_archives_return_409_without_overwriting(): void
    {
        $appointment = Appointment::factory()->create(['version' => 2]);
        Sanctum::actingAs($appointment->user);
        $this->putJson('/api/v1/appointments/'.$appointment->id, [...$this->input($appointment->vehicle), 'version' => 1])->assertConflict();
        $this->deleteJson('/api/v1/appointments/'.$appointment->id, ['version' => 1])->assertConflict();
        $this->assertSame(2, $appointment->fresh()->version);
        $this->assertNull($appointment->fresh()->deleted_at);
    }

    public function test_import_is_transactional_idempotent_preserves_identifiers_dates_and_unknown_properties(): void
    {
        $vehicle = Vehicle::factory()->create();
        Sanctum::actingAs($vehicle->client->user);
        $record = [...$this->input($vehicle), 'id' => (string) Str::uuid(), 'createdAt' => '2024-01-01T12:00:00.123Z',
            'updatedAt' => '2024-01-02T12:00:00.456Z', 'oldNotes' => '  preserve  '];
        $this->postJson('/api/v1/appointments/import', ['appointments' => [$record]])->assertOk()->assertJsonPath('data.createdAppointments', 1);
        $this->postJson('/api/v1/appointments/import', ['appointments' => [[...$record, 'description' => 'Do not overwrite']]])
            ->assertOk()->assertJsonPath('data.skippedAppointments', 1);
        $this->getJson('/api/v1/appointments/'.$record['id'])->assertJsonPath('data.createdAt', '2024-01-01T12:00:00.123000Z')
            ->assertJsonPath('data.updatedAt', '2024-01-02T12:00:00.456000Z')
            ->assertJsonPath('data.description', 'Replace oil')->assertJsonPath('data.data.legacyProperties.oldNotes', '  preserve  ');
        $second = [...$record, 'id' => (string) Str::uuid()];
        $invalid = [...$record, 'id' => (string) Str::uuid(), 'vehicleId' => (string) Str::uuid()];
        $this->postJson('/api/v1/appointments/import', ['appointments' => [$second, $invalid]])->assertUnprocessable();
        $this->assertDatabaseMissing('appointments', ['id' => $second['id']]);
        $this->assertDatabaseCount('appointments', 1);
    }

    public function test_foreign_import_conflicts_roll_back_and_imported_addresses_keep_unknown_properties(): void
    {
        $foreignAppointment = Appointment::factory()->create();
        $vehicle = Vehicle::factory()->create();
        Sanctum::actingAs($vehicle->client->user);
        $record = [...$this->input($vehicle), 'id' => (string) Str::uuid(),
            'createdAt' => '2024-01-01T12:00:00.123Z', 'updatedAt' => '2024-01-02T12:00:00.456Z'];
        $conflict = [...$record, 'id' => $foreignAppointment->id];
        $this->postJson('/api/v1/appointments/import', ['appointments' => [$record, $conflict]])->assertConflict();
        $this->assertDatabaseMissing('appointments', ['id' => $record['id']]);
        $address = array_fill_keys(['formatted', 'houseNumber', 'street', 'city', 'province', 'postalCode', 'country', 'countryCode', 'provinceCode'], '');
        $record['addressDetails'] = [...$address, 'city' => 'Québec', 'oldUnit' => '  4B  '];
        $this->postJson('/api/v1/appointments/import', ['appointments' => [$record]])->assertOk();
        $this->getJson('/api/v1/appointments/'.$record['id'])
            ->assertJsonPath('data.addressDetails.city', 'Québec')
            ->assertJsonPath('data.data.legacyProperties.addressDetails.oldUnit', '  4B  ');
    }

    public function test_manual_addresses_require_confirmation_and_validate_canadian_postal_codes(): void
    {
        $vehicle = Vehicle::factory()->create();
        Sanctum::actingAs($vehicle->client->user);
        $address = array_fill_keys(['formatted', 'houseNumber', 'street', 'city', 'province', 'postalCode', 'country', 'countryCode', 'provinceCode'], '');
        $address = [...$address, 'formatted' => 'Québec', 'city' => 'Québec', 'source' => 'manual', 'countryCode' => 'CA'];
        $this->postJson('/api/v1/appointments', [...$this->input($vehicle), 'addressDetails' => $address])
            ->assertUnprocessable()->assertJsonPath('errors.addressDetails.0', 'APPOINTMENT_ADDRESS_CONFIRMATION');
        $this->postJson('/api/v1/appointments', [...$this->input($vehicle), 'addressDetails' => [...$address, 'manualConfirmed' => true, 'postalCode' => 'invalid']])
            ->assertUnprocessable()->assertJsonValidationErrors('addressDetails.postalCode')
            ->assertJsonFragment(['addressDetails.postalCode' => ['APPOINTMENT_POSTAL_INVALID']]);
        $this->postJson('/api/v1/appointments', [...$this->input($vehicle), 'addressDetails' => [...$address, 'manualConfirmed' => true]])
            ->assertCreated()->assertJsonPath('data.addressDetails.street', '');
    }

    public function test_existing_appointments_protect_clients_and_vehicles_from_archiving(): void
    {
        $appointment = Appointment::factory()->create();
        Vehicle::factory()->for($appointment->client)->create();
        Sanctum::actingAs($appointment->user);
        $this->deleteJson('/api/v1/clients/'.$appointment->client_id)->assertUnprocessable()->assertJsonPath('errors.client.0', 'HISTORY_REFERENCED');
        $this->deleteJson('/api/v1/vehicles/'.$appointment->vehicle_id)->assertUnprocessable()->assertJsonPath('errors.vehicle.0', 'HISTORY_REFERENCED');
        $this->assertNull($appointment->client->fresh()->deleted_at);
        $this->assertNull($appointment->vehicle->fresh()->deleted_at);
    }
}
