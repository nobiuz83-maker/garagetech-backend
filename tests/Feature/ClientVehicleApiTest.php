<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\IsolatedDatabaseTransactions;
use Tests\TestCase;

class ClientVehicleApiTest extends TestCase
{
    use IsolatedDatabaseTransactions;

    private function vehicleInput(): array
    {
        return ['make' => 'Toyota', 'model' => 'Corolla', 'year' => 2020, 'vin' => '', 'licensePlate' => ''];
    }

    private function clientInput(): array
    {
        return ['firstName' => 'Marie', 'lastName' => 'Roy', 'phone' => '', 'email' => '', 'vehicles' => [$this->vehicleInput()]];
    }

    public function test_guests_cannot_access_client_vehicle_or_import_endpoints(): void
    {
        $this->getJson('/api/v1/clients')->assertUnauthorized();
        $this->getJson('/api/v1/vehicles')->assertUnauthorized();
        $this->postJson('/api/v1/clients/import', [])->assertUnauthorized();
        $this->postJson('/api/v1/clients', $this->clientInput())->assertUnauthorized();
    }

    public function test_client_creation_preserves_contract_and_creates_vehicle_atomically(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $response = $this->postJson('/api/v1/clients', $this->clientInput())->assertCreated()
            ->assertJsonPath('data.firstName', 'Marie')->assertJsonPath('data.phone', '')
            ->assertJsonPath('data.vehicles.0.vin', '');
        $this->assertDatabaseHas('vehicles', ['client_id' => $response->json('data.id')]);
        $this->getJson('/api/v1/clients')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/vehicles')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_missing_vehicles_and_invalid_years_do_not_create_partial_records(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v1/clients', [...$this->clientInput(), 'vehicles' => []])->assertUnprocessable();
        $this->postJson('/api/v1/clients', [...$this->clientInput(), 'vehicles' => [[...$this->vehicleInput(), 'year' => 1800]]])->assertUnprocessable();
        $this->assertDatabaseCount('clients', 0);
        $this->assertDatabaseCount('vehicles', 0);
    }

    public function test_accounts_cannot_read_modify_archive_or_attach_to_other_accounts_records(): void
    {
        $foreignClient = Client::factory()->create();
        $foreignVehicle = Vehicle::factory()->for($foreignClient)->create();
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/clients')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/vehicles')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/clients/'.$foreignClient->id)->assertForbidden();
        $this->putJson('/api/v1/clients/'.$foreignClient->id, $this->clientInput())->assertForbidden();
        $this->deleteJson('/api/v1/clients/'.$foreignClient->id)->assertForbidden();
        $this->getJson('/api/v1/vehicles/'.$foreignVehicle->id)->assertForbidden();
        $this->putJson('/api/v1/vehicles/'.$foreignVehicle->id, [...$this->vehicleInput(), 'clientId' => $foreignClient->id])->assertForbidden();
        $this->deleteJson('/api/v1/vehicles/'.$foreignVehicle->id)->assertForbidden();
        $this->postJson('/api/v1/vehicles', [...$this->vehicleInput(), 'clientId' => $foreignClient->id])->assertNotFound();
    }

    public function test_last_vehicle_is_protected_and_archiving_retains_database_records(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->create();
        $vehicle = Vehicle::factory()->for($client)->create();
        Sanctum::actingAs($user);
        $this->deleteJson('/api/v1/vehicles/'.$vehicle->id)->assertUnprocessable()->assertJsonPath('errors.vehicle.0', 'LAST_VEHICLE_REQUIRED');
        $secondVehicle = Vehicle::factory()->for($client)->create();
        $this->deleteJson('/api/v1/vehicles/'.$secondVehicle->id)->assertNoContent();
        $this->assertSoftDeleted($secondVehicle);
        $this->deleteJson('/api/v1/clients/'.$client->id)->assertNoContent();
        $this->assertSoftDeleted($client);
        $this->assertSoftDeleted($vehicle);
        $this->assertDatabaseCount('clients', 1);
        $this->assertDatabaseCount('vehicles', 2);
    }

    public function test_aggregate_update_rolls_back_when_a_vehicle_belongs_to_another_client(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->create(['first_name' => 'Original']);
        $vehicle = Vehicle::factory()->create();
        Sanctum::actingAs($user);
        $this->putJson('/api/v1/clients/'.$client->id, [...$this->clientInput(), 'vehicles' => [[...$this->vehicleInput(), 'id' => $vehicle->id]]])->assertUnprocessable();
        $this->assertSame('Original', $client->fresh()->first_name);
    }

    public function test_aggregate_update_archives_removed_vehicles_and_preserves_retained_identifiers(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->create();
        $retainedVehicle = Vehicle::factory()->for($client)->create();
        $removedVehicle = Vehicle::factory()->for($client)->create();
        Sanctum::actingAs($user);
        $this->putJson('/api/v1/clients/'.$client->id, [...$this->clientInput(), 'vehicles' => [[...$this->vehicleInput(), 'id' => $retainedVehicle->id, 'model' => 'Updated']]])
            ->assertOk()->assertJsonCount(1, 'data.vehicles')->assertJsonPath('data.vehicles.0.id', $retainedVehicle->id);
        $this->assertSoftDeleted($removedVehicle);
        $this->assertSame('Updated', $retainedVehicle->fresh()->model);
    }

    public function test_import_preserves_uuids_dates_and_addresses_and_is_safe_to_retry(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $clientId = (string) Str::uuid();
        $vehicleId = (string) Str::uuid();
        $address = ['formatted' => '', 'houseNumber' => '', 'street' => '', 'city' => 'Montréal', 'province' => '', 'postalCode' => '', 'country' => '', 'countryCode' => '', 'provinceCode' => ''];
        $input = [
            'clients' => [[...$this->clientInput(), 'id' => $clientId, 'createdAt' => '2024-01-02T03:04:05.123Z', 'address' => $address]],
            'vehicles' => [[...$this->vehicleInput(), 'id' => $vehicleId, 'clientId' => $clientId, 'createdAt' => '2024-01-02T03:04:05.123Z']],
        ];
        $this->postJson('/api/v1/clients/import', $input)->assertOk()->assertJsonPath('data.createdClients', 1)->assertJsonPath('data.createdVehicles', 1)->assertJsonPath('data.skippedClients', 0)->assertJsonPath('data.skippedVehicles', 0);
        $input['clients'][0]['firstName'] = 'Do not overwrite';
        $this->postJson('/api/v1/clients/import', $input)->assertOk()->assertJsonPath('data.createdClients', 0)->assertJsonPath('data.createdVehicles', 0)->assertJsonPath('data.skippedClients', 1)->assertJsonPath('data.skippedVehicles', 1);
        $this->getJson('/api/v1/clients/'.$clientId)->assertJsonPath('data.firstName', 'Marie')->assertJsonPath('data.address.city', 'Montréal')->assertJsonPath('data.createdAt', '2024-01-02T03:04:05.123000Z');
        $this->assertDatabaseCount('clients', 1);
        $this->assertDatabaseCount('vehicles', 1);
    }

    public function test_import_rejects_orphans_and_identifier_conflicts_without_partial_writes(): void
    {
        $foreignClient = Client::factory()->create();
        Sanctum::actingAs(User::factory()->create());
        $clientId = (string) Str::uuid();
        $clientInput = [...$this->clientInput(), 'id' => $clientId, 'createdAt' => now()->toISOString()];
        $this->postJson('/api/v1/clients/import', ['clients' => [$clientInput], 'vehicles' => [[...$this->vehicleInput(), 'id' => (string) Str::uuid(), 'clientId' => (string) Str::uuid(), 'createdAt' => now()->toISOString()]]])->assertUnprocessable();
        $this->assertDatabaseMissing('clients', ['id' => $clientId]);
        $this->postJson('/api/v1/clients/import', ['clients' => [$clientInput, [...$clientInput, 'id' => $foreignClient->id]], 'vehicles' => []])->assertConflict();
        $this->assertDatabaseMissing('clients', ['id' => $clientId]);
    }

    public function test_legacy_clients_without_vehicles_can_be_imported_without_data_loss(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v1/clients/import', ['clients' => [[...$this->clientInput(), 'id' => (string) Str::uuid(), 'createdAt' => now()->toISOString()]], 'vehicles' => []])->assertOk();
        $this->assertDatabaseCount('clients', 1);
    }

    public function test_import_rate_limit_is_independent_of_general_api_activity(): void
    {
        Sanctum::actingAs(User::factory()->create());

        for ($requestNumber = 0; $requestNumber < 12; $requestNumber++) {
            $this->getJson('/api/v1/clients')->assertOk();
        }

        for ($importNumber = 0; $importNumber < 10; $importNumber++) {
            $this->postJson('/api/v1/clients/import', ['clients' => [], 'vehicles' => []])->assertOk();
        }

        $this->postJson('/api/v1/clients/import', ['clients' => [], 'vehicles' => []])->assertTooManyRequests();
        $this->getJson('/api/v1/clients')->assertOk();
    }
}
