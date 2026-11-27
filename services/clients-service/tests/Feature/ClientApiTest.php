<?php

namespace Tests\Feature;

use App\Models\Client;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as OutgoingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\CompanyIdentityFixtures;
use Tests\IsolatedDatabaseTransactions;
use Tests\TestCase;

class ClientApiTest extends TestCase
{
    use CompanyIdentityFixtures;
    use IsolatedDatabaseTransactions;

    private string $configurationDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withCredentials();
        $this->configurationDirectory = sys_get_temp_dir().'/client-domain-'.Str::random(16);
        mkdir($this->configurationDirectory);
        foreach (['ca_certificate_file', 'client_certificate_file', 'client_key_file', 'signing_key_file'] as $setting) {
            $filename = $this->configurationDirectory.'/'.$setting;
            file_put_contents($filename, str_repeat('k', 48));
            config()->set('remote_auth.'.$setting, $filename);
        }
        config()->set('remote_auth.url', 'https://auth-service:8443');
        $this->configureCompanyIdentity();
        $this->identity('17');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->configurationDirectory.'/*') as $filename) {
            unlink($filename);
        }
        rmdir($this->configurationDirectory);
        $this->cleanupCompanyIdentity();
        parent::tearDown();
    }

    private function identity(string $subject): void
    {
        $this->companyTestSubject = $subject;
        $this->withUnencryptedCookie('vicartech-auth-session', 'opaque-session');
        $this->fakeCompanyIdentity();
    }

    private function input(): array
    {
        return ['firstName' => 'Fictional', 'lastName' => 'Owner', 'phone' => '', 'email' => '', 'vehicles' => [
            ['make' => 'Toyota', 'model' => 'Corolla', 'year' => 2020, 'vin' => '', 'licensePlate' => '', 'data' => ['schemaVersion' => 1, 'engine' => ['fuelType' => 'gasoline']]],
        ]];
    }

    public function test_a_verified_subject_can_create_and_list_only_its_client_and_vehicle(): void
    {
        $response = $this->postJson('/api/v1/clients', $this->input())->assertCreated();
        $this->getJson('/api/v1/clients')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.vehicles.0.make', 'Toyota');
        $this->assertDatabaseHas('owner_subjects', ['subject_id' => '17']);
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('users'));
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('sessions'));
        $this->getJson('/api/v1/clients/'.$response->json('data.id'))->assertOk();
    }

    public function test_foreign_ownership_is_denied_for_read_update_and_archive(): void
    {
        $created = $this->postJson('/api/v1/clients', $this->input())->assertCreated();
        $clientId = $created->json('data.id');
        $vehicleId = $this->getJson('/api/v1/clients')->json('data.0.vehicles.0.id');
        $this->identity('23');
        $this->withHeader('X-User-ID', '17')->getJson('/api/v1/clients')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/clients/'.$clientId)->assertForbidden();
        $this->putJson('/api/v1/clients/'.$clientId, $this->input())->assertForbidden();
        $this->deleteJson('/api/v1/clients/'.$clientId)->assertForbidden();
        $this->getJson('/api/v1/vehicles/'.$vehicleId)->assertForbidden();
        $this->deleteJson('/api/v1/vehicles/'.$vehicleId)->assertForbidden();
    }

    public function test_auth_outage_prevents_database_reads_and_writes_on_business_routes(): void
    {
        Http::swap(new Factory);
        Http::fake(['*' => Http::response([], 503)]);
        $this->getJson('/api/v1/clients')->assertStatus(503);
        $this->postJson('/api/v1/clients', $this->input())->assertStatus(503);
        $this->assertDatabaseCount('clients', 0);
        $this->assertDatabaseCount('owner_subjects', 0);
        $this->getJson('/api/v1/clients/'.Str::uuid())->assertStatus(503);
    }

    public function test_json_updates_preserve_optional_sections_and_vehicle_relations(): void
    {
        $created = $this->postJson('/api/v1/clients', $this->input())->assertCreated();
        $clientId = $created->json('data.id');
        $this->patchJson('/api/v1/clients/'.$clientId, ['firstName' => 'Updated', 'data' => ['schemaVersion' => 1, 'preferences' => ['language' => 'fr']]])->assertOk();
        $this->getJson('/api/v1/clients/'.$clientId)->assertOk()
            ->assertJsonPath('data.firstName', 'Updated')->assertJsonCount(1, 'data.vehicles')
            ->assertJsonPath('data.data.preferences.language', 'fr');
    }

    public function test_unavailable_external_history_validation_never_allows_archiving(): void
    {
        $created = $this->postJson('/api/v1/clients', $this->input())->assertCreated();
        $clientId = $created->json('data.id');
        $this->deleteJson('/api/v1/clients/'.$clientId)->assertStatus(503);
        $this->assertNull(Client::findOrFail($clientId)->deleted_at);
    }

    public function test_invalid_csrf_from_auth_is_propagated_before_a_write(): void
    {
        Http::swap(new Factory);
        Http::fake(fn (OutgoingRequest $request) => Http::response([
            'error' => ['code' => 'CSRF_TOKEN_MISMATCH', 'requestId' => $request->header('X-Request-ID')[0] ?? ''],
        ], 419));
        $this->postJson('/api/v1/clients', $this->input())->assertStatus(419);
        $this->assertDatabaseCount('clients', 0);
    }

    public function test_import_is_idempotent_and_cannot_reassign_a_foreign_identifier(): void
    {
        $clientId = (string) Str::uuid();
        $vehicleId = (string) Str::uuid();
        $input = ['clients' => [
            ['id' => $clientId, 'createdAt' => '2024-01-02T03:04:05Z', 'firstName' => 'Imported', 'lastName' => 'Owner', 'phone' => '', 'email' => ''],
        ], 'vehicles' => [
            ['id' => $vehicleId, 'clientId' => $clientId, 'createdAt' => '2024-01-02T03:04:05Z', ...$this->input()['vehicles'][0]],
        ]];
        $this->postJson('/api/v1/clients/import', $input)->assertOk()->assertJsonPath('data.createdClients', 1);
        $this->postJson('/api/v1/clients/import', $input)->assertOk()->assertJsonPath('data.createdClients', 0);
        $this->assertDatabaseCount('clients', 1);
        $this->assertDatabaseCount('vehicles', 1);
        $this->identity('23');
        $this->postJson('/api/v1/clients/import', $input)->assertStatus(409);
        $this->assertDatabaseMissing('owner_subjects', ['subject_id' => '23']);
    }

    public function test_an_unverifiable_vehicle_removal_rolls_back_the_entire_update(): void
    {
        $created = $this->postJson('/api/v1/clients', $this->input())->assertCreated();
        $clientId = $created->json('data.id');
        $replacement = $this->input();
        $replacement['firstName'] = 'Must roll back';
        $this->putJson('/api/v1/clients/'.$clientId, $replacement)->assertStatus(503);
        $this->assertSame('Fictional', Client::findOrFail($clientId)->first_name);
        $this->assertDatabaseCount('vehicles', 1);
    }

    public function test_a_missing_cookie_is_rejected_before_business_model_binding(): void
    {
        Http::swap(new Factory);
        Http::fake();
        $this->withUnencryptedCookie('vicartech-auth-session', '')->getJson('/api/v1/clients/'.Str::uuid())->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_unknown_document_versions_and_properties_are_rejected(): void
    {
        $input = $this->input();
        $input['data'] = ['schemaVersion' => 2];
        $this->postJson('/api/v1/clients', $input)->assertUnprocessable();
        $input['data'] = ['schemaVersion' => 1, 'ownerSubjectId' => '23'];
        $this->postJson('/api/v1/clients', $input)->assertUnprocessable();
        $this->assertDatabaseCount('clients', 0);
    }

    public function test_storage_errors_do_not_expose_client_data_in_logs_or_responses(): void
    {
        Log::spy();
        Route::get('/storage-failure', function (): never {
            throw new QueryException('mysql', 'insert into clients values (?)', ['private-client-value'], new RuntimeException('Storage unavailable.'));
        });
        $this->getJson('/storage-failure')->assertStatus(503)->assertDontSee('private-client-value');
        Log::shouldHaveReceived('warning')->once()->with('ClientsService storage unavailable.', ['exception' => QueryException::class]);
        Log::shouldNotHaveReceived('error');
    }
}
