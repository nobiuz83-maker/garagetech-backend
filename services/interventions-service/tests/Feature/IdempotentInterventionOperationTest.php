<?php

namespace Tests\Feature;

use App\Auth\CompanyActor;
use App\Auth\SecurityFailure;
use App\Services\IdempotentInterventionOperation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class IdempotentInterventionOperationTest extends TestCase
{
    private CompanyActor $actor;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('database.connections.sqlite.url', null);
        DB::purge('sqlite');
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $companyId = (string) Str::uuid();
        DB::table('company_subjects')->insert(['company_id' => $companyId, 'status' => 'active', 'directory_version' => 1]);
        $this->actor = new CompanyActor('17', $companyId, (string) Str::uuid(), 1, ['interventions.write'], (string) Str::uuid());
    }

    public function test_repeating_the_same_request_returns_the_original_result_without_executing_twice(): void
    {
        $executions = 0;
        $operation = function () use (&$executions): array {
            $executions++;

            return ['id' => (string) Str::uuid()];
        };
        $service = app(IdempotentInterventionOperation::class);
        $first = $service->execute($this->actor, 'intervention.create', 'synthetic-request-0001', '{"description":"Inspection"}', $operation);
        $second = $service->execute($this->actor, 'intervention.create', 'synthetic-request-0001', '{"description":"Inspection"}', $operation);

        $this->assertSame($first, $second);
        $this->assertSame(1, $executions);
        $this->assertDatabaseCount('intervention_idempotency_keys', 1);
    }

    public function test_reusing_a_key_with_a_different_payload_is_rejected(): void
    {
        $service = app(IdempotentInterventionOperation::class);
        $service->execute($this->actor, 'intervention.create', 'synthetic-request-0001', '{"description":"First"}', fn (): array => ['created' => true]);

        $this->expectException(SecurityFailure::class);
        $service->execute($this->actor, 'intervention.create', 'synthetic-request-0001', '{"description":"Second"}', fn (): array => ['created' => true]);
    }

    public function test_failed_operation_does_not_leave_a_completed_key(): void
    {
        try {
            app(IdempotentInterventionOperation::class)->execute($this->actor, 'intervention.create', 'synthetic-request-0001', '{}', function (): array {
                DB::table('company_subjects')->where('company_id', $this->actor->companyId)->update(['status' => 'pending']);
                throw new RuntimeException('Synthetic failure');
            });
            $this->fail('The synthetic operation should fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic failure', $exception->getMessage());
        }

        $this->assertDatabaseCount('intervention_idempotency_keys', 0);
        $this->assertDatabaseHas('company_subjects', ['company_id' => $this->actor->companyId, 'status' => 'active']);
    }
}
