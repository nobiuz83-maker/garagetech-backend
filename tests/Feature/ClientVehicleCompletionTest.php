<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\IsolatedDatabaseTransactions;
use Tests\TestCase;

class ClientVehicleCompletionTest extends TestCase
{
    use IsolatedDatabaseTransactions;

    public function test_client_search_is_account_scoped_paginated_and_sorted(): void
    {
        $user = User::factory()->create();
        Client::factory()->for($user)->create(['first_name' => 'Marie', 'last_name' => 'Roy']);
        Client::factory()->for($user)->create(['first_name' => 'Marie', 'last_name' => 'Zola']);
        Client::factory()->create(['first_name' => 'Marie', 'last_name' => 'Foreign']);
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/clients?search=Marie&perPage=1&sort=lastName&direction=desc')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.last_page', 2)->assertJsonPath('data.0.lastName', 'Zola');
        $this->getJson('/api/v1/clients?search=Marie&perPage=1&sort=lastName&direction=desc&page=2')
            ->assertJsonPath('data.0.lastName', 'Roy');
        $this->getJson('/api/v1/clients?search=Marie%20Roy')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/clients?perPage=101')->assertUnprocessable();
        $this->getJson('/api/v1/clients?sort=make')->assertUnprocessable();
        $this->getJson('/api/v1/clients?search=%25')->assertJsonCount(0, 'data');
    }

    public function test_vehicle_search_filters_and_nested_route_preserve_account_boundaries(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->create();
        $foreignClient = Client::factory()->create();
        $vehicle = Vehicle::factory()->for($client)->create(['make' => 'Toyota', 'model' => 'Corolla', 'year' => 2020, 'license_plate' => 'ABC123']);
        Vehicle::factory()->for($client)->create(['make' => 'Honda', 'year' => 2021]);
        Vehicle::factory()->for($foreignClient)->create(['make' => 'Toyota', 'model' => 'Corolla', 'year' => 2020]);
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/vehicles?make=Toyota&year=2020&search=ABC123')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $vehicle->id);
        $this->getJson('/api/v1/clients/'.$client->id.'/vehicles?perPage=1')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/clients/'.$foreignClient->id.'/vehicles')->assertForbidden();
        $this->getJson('/api/v1/vehicles?clientId='.$foreignClient->id)->assertJsonCount(0, 'data');
    }

    public function test_partial_client_update_preserves_relations_and_other_document_sections(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->create(['data' => ['schemaVersion' => 1, 'preferences' => ['language' => 'fr', 'preferredContactMethod' => 'sms']]]);
        $vehicle = Vehicle::factory()->for($client)->create();
        Sanctum::actingAs($user);
        $this->patchJson('/api/v1/clients/'.$client->id, ['firstName' => 'Updated', 'data' => ['schemaVersion' => 1, 'preferences' => ['language' => 'en']]])
            ->assertOk()->assertJsonPath('data.firstName', 'Updated')->assertJsonPath('data.data.preferences.language', 'en')
            ->assertJsonPath('data.data.preferences.preferredContactMethod', 'sms')->assertJsonPath('data.vehicles.0.id', $vehicle->id);
        $this->patchJson('/api/v1/clients/'.$client->id, ['email' => 'invalid-email'])->assertUnprocessable();
        $this->patchJson('/api/v1/clients/'.$client->id, ['vehicles' => []])->assertUnprocessable();
        $this->patchJson('/api/v1/clients/'.$client->id, ['data' => ['preferences' => ['language' => 'en']]])->assertUnprocessable();
        $this->assertNull($vehicle->fresh()->deleted_at);
    }

    public function test_partial_vehicle_update_does_not_require_or_change_its_owner(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->create();
        $vehicle = Vehicle::factory()->for($client)->create(['make' => 'Toyota']);
        Sanctum::actingAs($user);
        $this->patchJson('/api/v1/vehicles/'.$vehicle->id, ['licensePlate' => 'NEW123'])
            ->assertOk()->assertJsonPath('data.licensePlate', 'NEW123')->assertJsonPath('data.make', 'Toyota')
            ->assertJsonPath('data.clientId', $client->id);
        $this->patchJson('/api/v1/vehicles/'.$vehicle->id, ['year' => 1700])->assertUnprocessable();
        $this->patchJson('/api/v1/vehicles/'.$vehicle->id, ['data' => ['schemaVersion' => 1, 'legacyProperties' => ['privileged' => true]]])->assertUnprocessable();
    }

    public function test_address_replacement_normalizes_empty_strings_removes_old_coordinates_and_can_be_cleared(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->create(['address' => ['city' => 'Historical'], 'data' => ['schemaVersion' => 1, 'address' => ['city' => 'Old', 'latitude' => 45.5]]]);
        Sanctum::actingAs($user);
        $address = array_fill_keys(['formatted', 'houseNumber', 'street', 'city', 'province', 'postalCode', 'country', 'countryCode', 'provinceCode'], '');
        $address['city'] = 'Montréal';
        $this->patchJson('/api/v1/clients/'.$client->id, ['address' => $address])
            ->assertOk()->assertJsonPath('data.address.city', 'Montréal')->assertJsonPath('data.address.street', '')
            ->assertJsonMissingPath('data.address.latitude');
        $this->patchJson('/api/v1/clients/'.$client->id, ['address' => null])->assertOk()->assertJsonMissingPath('data.address');
        $this->getJson('/api/v1/clients/'.$client->id)->assertJsonMissingPath('data.address');
    }

    public function test_additive_migration_backfills_historical_addresses_and_can_resume_without_overwriting_documents(): void
    {
        $client = Client::factory()->create(['address' => ['city' => 'Montréal'], 'data' => null]);
        $originalTimestamp = $client->fresh()->updated_at->toISOString();
        $migration = require database_path('migrations/2026_11_12_160609_add_hybrid_data_to_clients_and_vehicles.php');
        $migration->up();
        $this->assertSame(['schemaVersion' => 1, 'address' => ['city' => 'Montréal']], $client->fresh()->data);
        DB::table('clients')->where('id', $client->id)->update(['data' => json_encode(['schemaVersion' => 1, 'address' => ['city' => 'Updated']])]);
        $migration->up();
        $this->assertSame('Updated', $client->fresh()->data['address']['city']);
        $this->assertSame($originalTimestamp, $client->fresh()->updated_at->toISOString());
    }

    public function test_controlled_import_preserves_unknown_nested_properties_and_original_empty_values(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $clientId = (string) Str::uuid();
        $vehicleId = (string) Str::uuid();
        $address = array_fill_keys(['formatted', 'houseNumber', 'street', 'city', 'province', 'postalCode', 'country', 'countryCode', 'provinceCode'], '');
        $input = [
            'clients' => [[
                'id' => $clientId, 'firstName' => 'Marie', 'lastName' => 'Roy', 'phone' => '', 'email' => 'historical contact',
                'createdAt' => '2024-01-02T03:04:05.123Z', 'address' => [...$address, 'unitNumber' => '  4B  '],
                'previousNotes' => '',
            ]],
            'vehicles' => [[
                'id' => $vehicleId, 'clientId' => $clientId, 'make' => 'Toyota', 'model' => 'Corolla', 'year' => 2020,
                'vin' => '', 'licensePlate' => '', 'createdAt' => '2024-01-02T03:04:05.123Z',
                'data' => ['schemaVersion' => 1, 'engine' => ['engineCode' => 'ABC', 'historicalCode' => ''], 'legacyProperties' => ['originalNotes' => '  preserved  ', 'emptyValue' => '']],
            ]],
        ];
        $this->postJson('/api/v1/clients/import', $input)->assertOk();
        $this->getJson('/api/v1/clients/'.$clientId)->assertJsonPath('data.email', 'historical contact')
            ->assertJsonPath('data.data.legacyProperties.previousNotes', '')
            ->assertJsonPath('data.data.legacyProperties.address.unitNumber', '  4B  ')
            ->assertJsonPath('data.vehicles.0.data.legacyProperties.data.engine.historicalCode', '')
            ->assertJsonPath('data.vehicles.0.data.engine.engineCode', 'ABC')
            ->assertJsonPath('data.vehicles.0.data.legacyProperties.originalNotes', '  preserved  ')
            ->assertJsonPath('data.vehicles.0.data.legacyProperties.emptyValue', '');
        $this->postJson('/api/v1/clients/import', $input)->assertJsonPath('data.createdClients', 0);
    }

    public function test_empty_document_sections_and_optional_technical_strings_have_a_stable_api_shape(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->create();
        $vehicle = Vehicle::factory()->for($client)->create();
        Sanctum::actingAs($user);
        $response = $this->patchJson('/api/v1/vehicles/'.$vehicle->id, ['data' => ['schemaVersion' => 1, 'engine' => ['engineCode' => ''], 'tires' => (object) []]])
            ->assertOk()->assertJsonPath('data.data.engine.engineCode', null);
        $this->assertStringContainsString('"tires":{}', $response->getContent());
        $this->patchJson('/api/v1/clients/'.$client->id, ['vehicles' => [[
            'id' => $vehicle->id, 'make' => 'Toyota', 'model' => 'Corolla', 'year' => 2020,
            'vin' => '', 'licensePlate' => '', 'data' => ['engine' => ['engineCode' => 'ABC']],
        ]]])->assertUnprocessable();
    }
}
