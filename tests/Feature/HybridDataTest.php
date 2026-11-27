<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\IsolatedDatabaseTransactions;
use Tests\TestCase;

class HybridDataTest extends TestCase
{
    use IsolatedDatabaseTransactions;

    public function test_nested_vehicle_changes_preserve_other_document_properties(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->create();
        $vehicle = Vehicle::factory()->for($client)->create(['data' => ['schemaVersion' => 1, 'engine' => ['fuelType' => 'gasoline', 'engineCode' => 'ABC'], 'tires' => ['front' => '205/55R16']]]);
        Sanctum::actingAs($user);
        $input = ['clientId' => $client->id, 'make' => 'Toyota', 'model' => 'Corolla', 'year' => 2020, 'vin' => '', 'licensePlate' => '', 'data' => ['schemaVersion' => 1, 'engine' => ['fuelType' => 'diesel']]];
        $this->putJson('/api/v1/vehicles/'.$vehicle->id, $input)->assertOk()
            ->assertJsonPath('data.data.engine.fuelType', 'diesel')
            ->assertJsonPath('data.data.engine.engineCode', 'ABC')
            ->assertJsonPath('data.data.tires.front', '205/55R16');
        $this->assertSame('Toyota', $vehicle->fresh()->make);
        $this->putJson('/api/v1/vehicles/'.$vehicle->id, [...$input, 'data' => ['schemaVersion' => 2]])->assertUnprocessable();
        $this->putJson('/api/v1/vehicles/'.$vehicle->id, [...$input, 'data' => ['schemaVersion' => 1, 'unexpected' => true]])->assertUnprocessable();
        $this->putJson('/api/v1/vehicles/'.$vehicle->id, [...$input, 'data' => 'invalid json'])->assertUnprocessable();
    }

    public function test_import_preserves_unknown_nested_properties_and_retries_without_overwriting(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $clientId = (string) Str::uuid();
        $input = ['clients' => [['id' => $clientId, 'firstName' => 'Marie', 'lastName' => 'Roy', 'phone' => '', 'email' => '', 'createdAt' => '2024-01-02T03:04:05Z', 'oldPreferences' => ['nested' => ['enabled' => true]], 'data' => ['schemaVersion' => 1, 'preferences' => ['language' => 'fr']]]], 'vehicles' => []];
        $this->postJson('/api/v1/clients/import', $input)->assertOk()->assertJsonPath('data.createdClients', 1);
        $this->postJson('/api/v1/clients/import', $input)->assertOk()->assertJsonPath('data.createdClients', 0);
        $this->getJson('/api/v1/clients/'.$clientId)->assertOk()
            ->assertJsonPath('data.data.legacyProperties.oldPreferences.nested.enabled', true)
            ->assertJsonPath('data.data.preferences.language', 'fr');
    }

    public function test_address_is_stored_in_versioned_document_without_changing_api_shape(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $address = array_fill_keys(['formatted', 'houseNumber', 'street', 'city', 'province', 'postalCode', 'country', 'countryCode', 'provinceCode'], '');
        $address['city'] = 'Montréal';
        $response = $this->postJson('/api/v1/clients', ['firstName' => 'Marie', 'lastName' => 'Roy', 'phone' => '', 'email' => '', 'address' => $address, 'data' => ['schemaVersion' => 1, 'preferences' => ['language' => 'fr']], 'vehicles' => [['make' => 'Toyota', 'model' => 'Corolla', 'year' => 2020, 'vin' => '', 'licensePlate' => '']]])->assertCreated()->assertJsonPath('data.address.city', 'Montréal');
        $client = Client::findOrFail($response->json('data.id'));
        $this->assertSame('Montréal', $client->data['address']['city']);
        $this->assertSame(1, $client->data['schemaVersion']);
        $this->assertNull($client->address);
    }
}
