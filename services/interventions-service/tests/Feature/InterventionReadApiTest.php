<?php

namespace Tests\Feature;

use App\Models\Intervention;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class InterventionReadApiTest extends TestCase
{
    private string $companyId;

    private string $requestId;

    private string $keyFile;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('database.connections.sqlite.url', null);
        DB::purge('sqlite');
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->companyId = (string) Str::uuid();
        $this->requestId = (string) Str::uuid();
        $this->keyFile = tempnam(sys_get_temp_dir(), 'intervention-auth-');
        file_put_contents($this->keyFile, str_repeat('k', 48));
        config()->set('company_security.enabled', true);
        config()->set('company_security.auth_url', 'https://auth-service:8443');
        foreach (['ca_certificate_file', 'client_certificate_file', 'client_key_file', 'signing_key_file'] as $setting) {
            config()->set('company_security.'.$setting, $this->keyFile);
        }
        Http::preventStrayRequests();
        $this->withHeaders(['X-Company-ID' => $this->companyId, 'X-Request-ID' => $this->requestId])->withCredentials();
    }

    protected function tearDown(): void
    {
        unlink($this->keyFile);
        parent::tearDown();
    }

    public function test_authenticated_search_and_detail_exclude_other_companies(): void
    {
        Http::fake(['*' => Http::response([
            'schemaVersion' => 2, 'subject' => '17', 'companyId' => $this->companyId,
            'membershipId' => (string) Str::uuid(), 'authorizationVersion' => 1,
            'permissions' => ['interventions.read'], 'purpose' => 'resource.read',
            'requestId' => $this->requestId, 'allowed' => true,
        ])]);
        $own = $this->createIntervention($this->companyId, 'Oil inspection');
        $foreign = $this->createIntervention((string) Str::uuid(), 'Oil inspection');

        $this->withUnencryptedCookie('vicartech-auth-session', 'opaque')
            ->getJson('/api/v1/interventions?search=Oil')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id);
        $this->getJson('/api/v1/interventions/'.$own->id)->assertOk()->assertJsonPath('data.laborRateCents', 10000);
        $this->getJson('/api/v1/interventions/'.$foreign->id)->assertNotFound();
    }

    public function test_anonymous_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/interventions')->assertUnauthorized();
    }

    public function test_creation_is_idempotent_after_reference_verification(): void
    {
        DB::table('company_subjects')->insert(['company_id' => $this->companyId, 'status' => 'active', 'directory_version' => 1]);
        $payload = [
            'id' => (string) Str::uuid(), 'clientId' => (string) Str::uuid(), 'vehicleId' => (string) Str::uuid(),
            'startDate' => '2026-11-17', 'description' => 'Inspection', 'diagnosis' => '', 'notes' => '',
            'laborRateCents' => 10000, 'laborHours' => '1.0000',
        ];
        DB::table('intervention_legacy_owner_mappings')->insert(['company_id' => $this->companyId, 'owner_subject_id' => 17, 'created_at' => now()]);
        config()->set('intervention_references.clients_url', 'https://clients-service:8443');
        foreach (['ca_certificate_file', 'client_certificate_file', 'client_key_file'] as $setting) {
            config()->set('intervention_references.'.$setting, $this->keyFile);
        }
        Http::fake([
            'https://auth-service:8443/*' => Http::response([
                'schemaVersion' => 2, 'subject' => '17', 'companyId' => $this->companyId,
                'membershipId' => (string) Str::uuid(), 'authorizationVersion' => 1,
                'permissions' => ['interventions.write'], 'purpose' => 'resource.write',
                'requestId' => $this->requestId, 'allowed' => true,
            ]),
            'https://clients-service:8443/*' => Http::response(['data' => [
                'id' => $payload['clientId'], 'vehicles' => [['id' => $payload['vehicleId'], 'clientId' => $payload['clientId']]],
            ]]),
        ]);
        $this->withUnencryptedCookie('vicartech-auth-session', 'opaque')
            ->withHeaders(['X-XSRF-TOKEN' => 'csrf', 'Idempotency-Key' => 'synthetic-create-0001'])
            ->postJson('/api/v1/interventions', $payload)->assertCreated()->assertJsonPath('data.id', $payload['id']);
        $this->postJson('/api/v1/interventions', $payload)->assertCreated()->assertJsonPath('data.id', $payload['id']);
        $this->assertDatabaseCount('interventions', 1);
        $updated = [...$payload, 'version' => 1, 'description' => 'Inspection updated'];
        $this->withHeader('Idempotency-Key', 'synthetic-update-0001')
            ->putJson('/api/v1/interventions/'.$payload['id'], $updated)->assertOk()
            ->assertJsonPath('data.description', 'Inspection updated')->assertJsonPath('data.version', 2);
        $this->withHeader('Idempotency-Key', 'synthetic-update-0002')
            ->putJson('/api/v1/interventions/'.$payload['id'], $updated)->assertStatus(409);
    }

    public function test_creation_and_update_preserve_a_verified_appointment_and_final_address(): void
    {
        DB::table('company_subjects')->insert(['company_id' => $this->companyId, 'status' => 'active', 'directory_version' => 1]);
        $payload = [
            'id' => (string) Str::uuid(), 'clientId' => (string) Str::uuid(), 'vehicleId' => (string) Str::uuid(),
            'appointmentId' => (string) Str::uuid(), 'startDate' => '2026-11-17', 'description' => 'Electrical check',
            'diagnosis' => '', 'notes' => '', 'address' => '70 Rue Boisjoli, Saint-Boniface, QC G0X 2L0, Canada',
            'addressDetails' => [
                'formatted' => '70 Rue Boisjoli, Saint-Boniface, QC G0X 2L0, Canada',
                'houseNumber' => '70', 'street' => 'Rue Boisjoli', 'city' => 'Saint-Boniface', 'province' => 'Québec',
                'postalCode' => 'G0X 2L0', 'country' => 'Canada', 'countryCode' => 'CA', 'provinceCode' => 'QC',
                'source' => 'manual', 'manualConfirmed' => true,
            ],
            'laborRateCents' => 12500, 'laborHours' => '1.0000',
        ];
        DB::table('intervention_legacy_owner_mappings')->insert(['company_id' => $this->companyId, 'owner_subject_id' => 17, 'created_at' => now()]);
        config()->set('intervention_references.clients_url', 'https://clients-service:8443');
        config()->set('intervention_references.appointments_url', 'https://appointments-service:8443');
        foreach (['ca_certificate_file', 'client_certificate_file', 'client_key_file'] as $setting) {
            config()->set('intervention_references.'.$setting, $this->keyFile);
        }
        Http::fake([
            'https://auth-service:8443/*' => Http::response([
                'schemaVersion' => 2, 'subject' => '17', 'companyId' => $this->companyId,
                'membershipId' => (string) Str::uuid(), 'authorizationVersion' => 1,
                'permissions' => ['interventions.write'], 'purpose' => 'resource.write',
                'requestId' => $this->requestId, 'allowed' => true,
            ]),
            'https://clients-service:8443/*' => Http::response(['data' => [
                'id' => $payload['clientId'], 'vehicles' => [['id' => $payload['vehicleId'], 'clientId' => $payload['clientId']]],
            ]]),
            'https://appointments-service:8443/*' => Http::response(['data' => [
                'id' => $payload['appointmentId'], 'clientId' => $payload['clientId'], 'vehicleId' => $payload['vehicleId'],
            ]]),
        ]);

        $this->withUnencryptedCookie('vicartech-auth-session', 'opaque')
            ->withHeaders(['X-XSRF-TOKEN' => 'csrf', 'Idempotency-Key' => 'appointment-create-0001'])
            ->postJson('/api/v1/interventions', $payload)->assertCreated()
            ->assertJsonPath('data.appointmentId', $payload['appointmentId'])
            ->assertJsonPath('data.address', $payload['address'])
            ->assertJsonPath('data.addressDetails.houseNumber', '70');

        $updatedAddress = '456 Oak Avenue, Lévis, Québec, G6V 1A1, Canada';
        $updated = [...$payload, 'version' => 1, 'description' => 'Electrical diagnosis',
            'address' => $updatedAddress, 'addressDetails' => [...$payload['addressDetails'], 'formatted' => $updatedAddress,
                'houseNumber' => '456', 'street' => 'Oak Avenue', 'city' => 'Lévis', 'postalCode' => 'G6V 1A1']];
        $this->withHeader('Idempotency-Key', 'appointment-update-0001')
            ->putJson('/api/v1/interventions/'.$payload['id'], $updated)->assertOk()
            ->assertJsonPath('data.appointmentId', $payload['appointmentId'])
            ->assertJsonPath('data.address', $updatedAddress)
            ->assertJsonPath('data.version', 2);
        $this->assertDatabaseHas('interventions', ['id' => $payload['id'], 'appointment_id' => $payload['appointmentId'], 'address' => $updatedAddress]);
        $this->assertDatabaseHas('intervention_appointment_references', [
            'company_id' => $this->companyId, 'client_id' => $payload['clientId'],
            'vehicle_id' => $payload['vehicleId'], 'appointment_id' => $payload['appointmentId'],
        ]);
    }

    private function createIntervention(string $companyId, string $description): Intervention
    {
        $clientId = (string) Str::uuid();
        $vehicleId = (string) Str::uuid();
        DB::table('company_subjects')->insert(['company_id' => $companyId, 'status' => 'active', 'directory_version' => 1]);
        DB::table('intervention_client_vehicle_references')->insert(['company_id' => $companyId, 'client_id' => $clientId, 'vehicle_id' => $vehicleId]);

        return Intervention::create([
            'company_id' => $companyId, 'client_id' => $clientId, 'vehicle_id' => $vehicleId,
            'start_date' => '2026-11-17', 'status' => 'planned', 'description' => $description,
            'diagnosis' => '', 'notes' => '', 'labor_rate_cents' => 10000, 'labor_hours' => '1.0000',
            'data' => ['schemaVersion' => 1],
        ]);
    }
}
