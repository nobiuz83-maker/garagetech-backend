<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\CompanyIdentityFixtures;
use Tests\IsolatedDatabaseTransactions;
use Tests\TestCase;

class ClientArchivingTest extends TestCase
{
    use CompanyIdentityFixtures;
    use IsolatedDatabaseTransactions;

    private string $keyDirectory;

    private string $subject = '17';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withCredentials();
        $this->keyDirectory = sys_get_temp_dir().'/archive-test-'.Str::random(16);
        mkdir($this->keyDirectory);
        foreach (['ca_certificate_file', 'client_certificate_file', 'client_key_file', 'signing_key_file'] as $setting) {
            file_put_contents($this->keyDirectory.'/'.$setting, str_repeat('k', 48));
            config()->set('remote_auth.'.$setting, $this->keyDirectory.'/'.$setting);
        }
        config()->set('remote_auth.url', 'https://auth-service:8443');
        config()->set('relationships.registry_ready', true);
        config()->set('internal_auth.require_mtls', false);
        config()->set('internal_auth.callers.appointments-service.key_file', $this->keyDirectory.'/signing_key_file');
        $this->configureCompanyIdentity();
        $this->fakeCompanyIdentity();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->keyDirectory.'/*') as $filename) {
            unlink($filename);
        }
        rmdir($this->keyDirectory);
        $this->cleanupCompanyIdentity();
        parent::tearDown();
    }

    private function createClient(): array
    {
        $input = ['firstName' => 'Fictional', 'lastName' => 'Archive', 'phone' => '', 'email' => '', 'vehicles' => []];
        foreach (['Corolla', 'Civic'] as $model) {
            $input['vehicles'][] = ['make' => 'Test', 'model' => $model, 'year' => 2020, 'vin' => '', 'licensePlate' => ''];
        }
        $client = $this->postJson('/api/v1/clients', $input)->assertCreated()->json('data.id');

        return $this->getJson('/api/v1/clients/'.$client)->assertOk()->json('data');
    }

    private function retain(array $client, string $appointmentId, int $expected = 200): void
    {
        $target = '/api/internal/v1/appointment-references';
        $payload = ['appointmentId' => $appointmentId, 'clientId' => $client['id'], 'vehicleId' => $client['vehicles'][0]['id']];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->timestamp;
        $nonce = Str::random(32);
        $canonical = implode("\n", ['POST', $target, $timestamp, $nonce, hash('sha256', $body)]);
        // This unit test isolates machine verification from user verification, whose contract is covered separately.
        $this->companyTestSubject = $this->subject;
        $this->fakeCompanyIdentity();
        $this->withHeaders(['X-Internal-Service' => 'appointments-service', 'X-Internal-Timestamp' => $timestamp, 'X-Internal-Nonce' => $nonce,
            'X-Internal-Signature' => hash_hmac('sha256', $canonical, str_repeat('k', 48))])->postJson($target, $payload)->assertStatus($expected);
        $this->fakeCompanyIdentity();
    }

    public function test_restore_only_reactivates_vehicles_from_the_same_archive_batch(): void
    {
        $client = $this->createClient();
        $first = $client['vehicles'][0]['id'];
        $second = $client['vehicles'][1]['id'];
        $this->deleteJson('/api/v1/vehicles/'.$first)->assertNoContent();
        $this->deleteJson('/api/v1/clients/'.$client['id'])->assertNoContent();
        $this->postJson('/api/v1/vehicles/'.$first.'/restore')->assertStatus(409);
        $this->postJson('/api/v1/clients/'.$client['id'].'/restore')->assertOk()->assertJsonCount(1, 'data.vehicles');
        $this->assertNotNull(Vehicle::withTrashed()->findOrFail($first)->deleted_at);
        $this->assertNull(Vehicle::findOrFail($second)->deleted_at);
        $this->postJson('/api/v1/vehicles/'.$first.'/restore')->assertOk();
        $this->assertDatabaseCount('clients', 1);
        $this->assertDatabaseCount('vehicles', 2);
        $this->assertDatabaseCount('archive_events', 6);
        $this->getJson('/api/v1/clients/'.$client['id'].'/archive-history')->assertOk()->assertJsonCount(6, 'data')->assertJsonPath('meta.legacyProvenanceInferred', false);
    }

    public function test_retained_reference_blocks_archival_and_is_idempotent(): void
    {
        $client = $this->createClient();
        $appointment = (string) Str::uuid();
        $this->retain($client, $appointment);
        $this->retain($client, $appointment);
        $this->assertDatabaseCount('appointment_references', 1);
        $this->deleteJson('/api/v1/clients/'.$client['id'])->assertUnprocessable();
        $this->deleteJson('/api/v1/vehicles/'.$client['vehicles'][0]['id'])->assertUnprocessable();
        $this->assertNull(Client::findOrFail($client['id'])->deleted_at);
        $this->assertDatabaseCount('archive_events', 0);
    }

    public function test_archived_associations_cannot_be_registered_for_new_appointments(): void
    {
        $client = $this->createClient();
        $this->deleteJson('/api/v1/clients/'.$client['id'])->assertNoContent();
        $this->assertNotNull(Client::withTrashed()->findOrFail($client['id'])->deleted_at);
        $this->retain($client, (string) Str::uuid(), 422);
        $this->assertDatabaseCount('appointment_references', 0);
    }

    public function test_unknown_legacy_provenance_and_foreign_restore_are_denied(): void
    {
        $client = $this->createClient();
        DB::table('clients')->where('id', $client['id'])->update(['deleted_at' => now()]);
        $this->postJson('/api/v1/clients/'.$client['id'].'/restore')->assertStatus(409);
        $this->subject = '23';
        $this->companyTestSubject = '23';
        $this->postJson('/api/v1/clients/'.$client['id'].'/restore')->assertForbidden();
    }

    public function test_non_mtls_or_badly_signed_internal_calls_cannot_create_references(): void
    {
        $client = $this->createClient();
        $this->postJson('/api/internal/v1/appointment-references', ['appointmentId' => (string) Str::uuid(), 'clientId' => $client['id'], 'vehicleId' => $client['vehicles'][0]['id']])->assertUnauthorized();
        $this->assertDatabaseCount('appointment_references', 0);
    }

    public function test_internal_search_preserves_owned_archived_history_and_filters_foreign_owners(): void
    {
        $client = $this->createClient();
        $this->deleteJson('/api/v1/clients/'.$client['id'])->assertNoContent();
        foreach (['17', '23'] as $subject) {
            $this->subject = $subject;
            $this->companyTestSubject = $subject;
            $this->fakeCompanyIdentity();
            $target = '/api/internal/v1/client-vehicle-search?search=Test';
            $timestamp = (string) now()->timestamp;
            $nonce = Str::random(32);
            $canonical = implode("\n", ['GET', $target, $timestamp, $nonce, hash('sha256', '')]);
            $response = $this->withHeaders(['X-Internal-Service' => 'appointments-service', 'X-Internal-Timestamp' => $timestamp,
                'X-Internal-Nonce' => $nonce, 'X-Internal-Signature' => hash_hmac('sha256', $canonical, str_repeat('k', 48))])->get($target, ['Accept' => 'application/json'])->assertOk();
            $response->assertJsonCount(0, 'data.clientIds');
            $response->assertJsonCount($subject === '17' ? 2 : 0, 'data.vehicleIds');
        }
    }
}
