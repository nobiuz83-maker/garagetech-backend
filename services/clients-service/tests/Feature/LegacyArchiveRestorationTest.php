<?php

namespace Tests\Feature;

use App\Maintenance\DomainImporter;
use App\Maintenance\DomainSnapshot;
use App\Maintenance\LegacyArchiveRestoration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use stdClass;
use Tests\IsolatedDatabaseTransactions;
use Tests\TestCase;

class LegacyArchiveRestorationTest extends TestCase
{
    use IsolatedDatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('maintenance_import.enabled', true);
        $path = base_path('tests/Fixtures/maintenance-snapshot.json');
        app(DomainImporter::class)->run(DomainSnapshot::read($path, hash_file('sha256', $path)), true);
    }

    private function plan(bool $includeClient = true): stdClass
    {
        $restoration = app(LegacyArchiveRestoration::class);
        $records = [];
        foreach (['clients', 'vehicles'] as $table) {
            if ($table === 'clients' && ! $includeClient) {
                continue;
            }
            $identifier = DB::table($table)->value('id');
            $records[] = ['entityType' => $table === 'clients' ? 'client' : 'vehicle', 'id' => $identifier, 'expectedSha256' => $restoration->rowDigest($table, $identifier)];
        }

        return json_decode(json_encode(['schemaVersion' => 1, 'actionId' => (string) Str::uuid(), 'operation' => 'restore', 'reversesActionId' => null,
            'operator' => 'test-operator', 'reviewer' => 'test-reviewer', 'reason' => 'Explicit fictional decision with unknown historical cause', 'ticket' => 'TEST-17', 'records' => $records], JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    }

    private function runPlan(stdClass $plan, bool $apply = true): array
    {
        return app(LegacyArchiveRestoration::class)->run($plan, hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR)), $apply);
    }

    private function rollbackPlan(stdClass $original): stdClass
    {
        $plan = clone $original;
        $plan->actionId = (string) Str::uuid();
        $plan->operation = 'rollback';
        $plan->reversesActionId = $original->actionId;
        $plan->records = array_map(function (stdClass $record): stdClass {
            $copy = clone $record;
            $copy->expectedSha256 = app(LegacyArchiveRestoration::class)->rowDigest($copy->entityType === 'client' ? 'clients' : 'vehicles', $copy->id);

            return $copy;
        }, $original->records);

        return $plan;
    }

    public function test_preview_never_reactivates_records_and_no_historical_provenance_is_invented(): void
    {
        $plan = $this->plan();
        $result = $this->runPlan($plan, false);
        $this->assertSame('planned', $result['status']);
        $this->assertFalse($result['historicalProvenanceInferred']);
        $this->assertDatabaseCount('manual_archive_actions', 0);
        $this->assertDatabaseCount('archive_events', 0);
        $this->assertNotNull(DB::table('clients')->value('deleted_at'));
    }

    public function test_explicit_restore_and_rollback_preserve_full_records_and_append_audit(): void
    {
        $plan = $this->plan();
        $before = [];
        foreach ($plan->records as $record) {
            $before[$record->id] = $record->expectedSha256;
        }
        $this->assertSame('restored', $this->runPlan($plan)['status']);
        $this->assertSame('verified-existing', $this->runPlan($plan)['status']);
        $this->assertNull(DB::table('clients')->value('deleted_at'));
        $this->assertNull(DB::table('vehicles')->where('id', $plan->records[1]->id)->value('deleted_at'));
        $this->assertDatabaseCount('archive_events', 0);
        $rollback = $this->rollbackPlan($plan);
        $this->assertSame('rolled-back', $this->runPlan($rollback)['status']);
        $this->assertSame('verified-existing', $this->runPlan($rollback)['status']);
        foreach ($plan->records as $record) {
            $this->assertSame($before[$record->id], app(LegacyArchiveRestoration::class)->rowDigest($record->entityType === 'client' ? 'clients' : 'vehicles', $record->id));
        }
        $this->assertDatabaseCount('manual_archive_actions', 2);
        $this->assertDatabaseCount('appointment_references', 3);
    }

    public function test_vehicle_parent_rule_and_two_distinct_reviewers_are_required(): void
    {
        try {
            $this->runPlan($this->plan(false));
            $this->fail('Vehicle restoration requires its parent to be active or explicitly selected.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('manual_archive_actions', 0);
        }
        $plan = $this->plan();
        $plan->reviewer = $plan->operator;
        $this->expectException(RuntimeException::class);
        $this->runPlan($plan);
    }

    public function test_stale_restore_plan_and_rollback_after_new_reference_are_refused(): void
    {
        $plan = $this->plan();
        DB::table('clients')->update(['phone' => 'Changed']);
        try {
            $this->runPlan($plan);
            $this->fail('A stale plan must not write.');
        } catch (RuntimeException) {
            $this->assertNotNull(DB::table('clients')->value('deleted_at'));
        }
        $plan = $this->plan();
        $this->runPlan($plan);
        $rollback = $this->rollbackPlan($plan);
        $reference = (array) DB::table('appointment_references')->first();
        unset($reference['id']);
        $reference['appointment_id'] = (string) Str::uuid();
        DB::table('appointment_subjects')->insert(['appointment_id' => $reference['appointment_id'], 'owner_subject_id' => $reference['owner_subject_id']]);
        DB::table('appointment_references')->insert($reference);
        try {
            $this->runPlan($rollback);
            $this->fail('New relationships must block rollback.');
        } catch (RuntimeException) {
            $this->assertNull(DB::table('clients')->value('deleted_at'));
            $this->assertDatabaseCount('manual_archive_actions', 1);
        }
    }

    public function test_restoring_only_parent_never_restores_implicitly_its_children(): void
    {
        $plan = $this->plan();
        $plan->records = [$plan->records[0]];
        $this->runPlan($plan);
        $this->assertNull(DB::table('clients')->value('deleted_at'));
        $this->assertNotNull(DB::table('vehicles')->value('deleted_at'));
    }

    public function test_audit_write_failure_rolls_back_the_entire_selected_restoration(): void
    {
        $plan = $this->plan();
        DB::statement("CREATE TRIGGER fail_audit BEFORE INSERT ON manual_archive_actions BEGIN SELECT RAISE(ABORT, 'synthetic audit failure'); END");
        try {
            $this->runPlan($plan);
            $this->fail('An unaudited restoration must never commit.');
        } catch (QueryException) {
            foreach ($plan->records as $record) {
                $this->assertSame($record->expectedSha256, app(LegacyArchiveRestoration::class)->rowDigest($record->entityType === 'client' ? 'clients' : 'vehicles', $record->id));
            }
            $this->assertDatabaseCount('manual_archive_actions', 0);
        }
    }

    public function test_unselected_archived_vehicle_is_preserved_during_explicit_family_restore(): void
    {
        $plan = $this->plan();
        $selected = array_map(fn (stdClass $record): string => $record->id, $plan->records);
        $unselected = DB::table('vehicles')->whereNotIn('id', $selected)->value('id');
        $before = app(LegacyArchiveRestoration::class)->rowDigest('vehicles', $unselected);
        $this->runPlan($plan);
        $this->assertSame($before, app(LegacyArchiveRestoration::class)->rowDigest('vehicles', $unselected));
        $this->assertNotNull(DB::table('vehicles')->where('id', $unselected)->value('deleted_at'));
    }
}
