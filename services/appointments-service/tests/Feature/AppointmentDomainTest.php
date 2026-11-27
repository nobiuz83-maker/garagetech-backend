<?php

namespace Tests\Feature;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as OutgoingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\CompanyIdentityFixtures;
use Tests\IsolatedDatabaseTransactions;
use Tests\TestCase;

class AppointmentDomainTest extends TestCase
{
    use CompanyIdentityFixtures;
    use IsolatedDatabaseTransactions;

    private string $keyDirectory;

    private string $subject = '17';

    private int $clientsStatus = 200;

    private bool $invalidReply = false;

    private string $clientId;

    private string $vehicleId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withCredentials();
        $this->clientId = (string) Str::uuid();
        $this->vehicleId = (string) Str::uuid();
        $this->keyDirectory = sys_get_temp_dir().'/appointment-domain-'.Str::random(16);
        mkdir($this->keyDirectory);
        foreach (['ca_certificate_file', 'client_certificate_file', 'client_key_file', 'signing_key_file'] as $setting) {
            file_put_contents($this->keyDirectory.'/'.$setting, str_repeat('k', 48));
            config()->set('remote_auth.'.$setting, $this->keyDirectory.'/'.$setting);
        }
        config()->set('remote_auth.url', 'https://auth-service:8443');
        config()->set('relationships.clients_url', 'https://clients-service:8443');
        config()->set('relationships.signing_key_file', $this->keyDirectory.'/signing_key_file');
        $this->configureCompanyIdentity();
        Http::swap(new Factory);
        Http::fake(function (OutgoingRequest $request): PromiseInterface {
            if (str_contains($request->url(), '/session/validate')) {
                return $this->companyValidationResponse($request);
            }
            if ($this->clientsStatus !== 200) {
                return Http::response(['error' => 'UNAVAILABLE'], $this->clientsStatus);
            }
            if ($this->invalidReply) {
                return Http::response(['data' => ['retained' => true]]);
            }
            if ($request->method() === 'GET') {
                return Http::response(['data' => ['schemaVersion' => 1, 'ownerSubjectId' => $this->subject, 'clientIds' => [$this->clientId], 'vehicleIds' => [$this->vehicleId]]]);
            }
            $input = json_decode($request->body(), true);

            return Http::response(['data' => ['schemaVersion' => 1, ...$input, 'ownerSubjectId' => $this->subject, 'retained' => true]]);
        });
        $this->withUnencryptedCookie('vicartech-auth-session', 'opaque-session')->withHeader('X-XSRF-TOKEN', 'browser-csrf');
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

    private function input(): array
    {
        return ['clientId' => $this->clientId, 'vehicleId' => $this->vehicleId, 'date' => '2027-01-08', 'startTime' => '10:00', 'endTime' => '11:00',
            'status' => 'scheduled', 'serviceType' => 'Fictional', 'description' => 'Test booking', 'notes' => '', 'address' => ''];
    }

    public function test_creation_retains_a_remote_reference_before_committing_local_relations(): void
    {
        $created = $this->postJson('/api/v1/appointments', $this->input())->assertCreated();
        $this->getJson('/api/v1/appointments')->assertOk()->assertJsonCount(1, 'data');
        $this->assertDatabaseCount('client_vehicle_associations', 1);
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('clients'));
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('vehicles'));
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('users'));
        $identifier = $created->json('data.id');
        Http::assertSent(fn (OutgoingRequest $request): bool => str_contains($request->url(), '/appointment-references')
            && $request->hasHeader('X-Internal-Service', 'appointments-service')
            && $request->hasHeader('X-XSRF-TOKEN', 'browser-csrf') && json_decode($request->body(), true)['appointmentId'] === $identifier);
    }

    public function test_clients_unavailability_and_invalid_success_responses_fail_closed(): void
    {
        $this->clientsStatus = 503;
        $this->postJson('/api/v1/appointments', $this->input())->assertStatus(503);
        $this->clientsStatus = 200;
        $this->invalidReply = true;
        $this->postJson('/api/v1/appointments', $this->input())->assertStatus(503);
        $this->assertDatabaseCount('appointments', 0);
        $this->assertDatabaseCount('owner_subjects', 0);
    }

    public function test_foreign_owner_and_stale_versions_are_rejected(): void
    {
        $created = $this->postJson('/api/v1/appointments', $this->input())->assertCreated();
        $identifier = $created->json('data.id');
        $this->putJson('/api/v1/appointments/'.$identifier, [...$this->input(), 'version' => 9])->assertStatus(409);
        $this->subject = '23';
        $this->companyTestSubject = '23';
        $this->getJson('/api/v1/appointments')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/appointments/'.$identifier)->assertForbidden();
        $this->putJson('/api/v1/appointments/'.$identifier, [...$this->input(), 'version' => 1])->assertForbidden();
    }

    public function test_cancellation_and_archival_keep_the_local_relationship_and_history(): void
    {
        $created = $this->postJson('/api/v1/appointments', $this->input())->assertCreated();
        $identifier = $created->json('data.id');
        $this->postJson('/api/v1/appointments/'.$identifier.'/cancel', ['version' => 1])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->deleteJson('/api/v1/appointments/'.$identifier, ['version' => 2])->assertNoContent();
        $this->assertDatabaseCount('appointments', 1);
        $this->assertDatabaseCount('client_vehicle_associations', 1);
        $this->assertNotNull(DB::table('appointments')->where('id', $identifier)->value('deleted_at'));
    }

    public function test_name_search_uses_the_clients_api_and_local_ids_instead_of_a_cross_database_join(): void
    {
        $this->postJson('/api/v1/appointments', $this->input())->assertCreated();
        $this->getJson('/api/v1/appointments?search=FictionalClient')->assertOk()->assertJsonCount(1, 'data');
        Http::assertSent(fn (OutgoingRequest $request): bool => str_contains($request->url(), '/client-vehicle-search?search=FictionalClient'));
    }

    public function test_overlapping_bookings_roll_back_the_local_write(): void
    {
        $this->postJson('/api/v1/appointments', $this->input())->assertCreated();
        $this->postJson('/api/v1/appointments', $this->input())->assertUnprocessable();
        $this->assertDatabaseCount('appointments', 1);
    }
}
