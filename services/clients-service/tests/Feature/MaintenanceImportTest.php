<?php

namespace Tests\Feature;

use App\Maintenance\DomainImporter;
use App\Maintenance\DomainSnapshot;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\IsolatedDatabaseTransactions;
use Tests\TestCase;

class MaintenanceImportTest extends TestCase
{
    use IsolatedDatabaseTransactions;

    private function snapshot(): DomainSnapshot
    {
        $path = base_path('tests/Fixtures/maintenance-snapshot.json');

        return DomainSnapshot::read($path, hash_file('sha256', $path));
    }

    public function test_default_plan_writes_nothing_and_apply_requires_explicit_enablement(): void
    {
        $snapshot = $this->snapshot();
        $importer = app(DomainImporter::class);
        $this->assertSame('planned', $importer->run($snapshot, false)['status']);
        $this->assertDatabaseCount('maintenance_imports', 0);
        $this->assertDatabaseCount('owner_subjects', 0);
        $this->expectException(RuntimeException::class);
        $importer->run($snapshot, true);
    }

    public function test_import_is_idempotent_preserves_all_rows_and_preloads_all_history(): void
    {
        config()->set('maintenance_import.enabled', true);
        $snapshot = $this->snapshot();
        $importer = app(DomainImporter::class);
        $receipt = $importer->run($snapshot, true);
        $this->assertSame('imported', $receipt['status']);
        $this->assertFalse($receipt['registryActivated']);
        $this->assertSame('verified-existing', $importer->run($snapshot, true)['status']);
        $this->assertSame('verified-existing', $importer->run($snapshot, false, true)['status']);
        $this->assertDatabaseCount('maintenance_imports', 1);
        foreach ($snapshot->tables as $table => $rows) {
            $this->assertDatabaseCount($table, count($rows));
        }
        $this->assertFalse(Schema::hasTable('users'));
        if ($snapshot->domain === 'clients') {
            $this->assertNotNull(DB::table('clients')->value('deleted_at'));
            $this->assertNotNull(DB::table('vehicles')->value('deleted_at'));
            $this->assertDatabaseCount('archive_events', 0);
            $this->assertDatabaseCount('appointment_references', 3);
            $this->assertStringContainsString('"object":{}', DB::table('vehicles')->value('data'));
            $this->assertSame('2024-01-02 03:04:05.123', DB::table('clients')->value('created_at'));
        } else {
            $this->assertDatabaseCount('appointments', 3);
            $this->assertSame(1, DB::table('appointments')->whereNotNull('deleted_at')->count());
            $this->assertFalse(Schema::hasTable('clients'));
            $this->assertFalse(Schema::hasTable('vehicles'));
            $this->assertSame('2024-01-02 03:04:05.123', DB::table('appointments')->value('created_at'));
        }
    }

    public function test_changed_payload_and_post_import_drift_are_rejected_without_overwrite(): void
    {
        config()->set('maintenance_import.enabled', true);
        $snapshot = $this->snapshot();
        $importer = app(DomainImporter::class);
        $importer->run($snapshot, true);
        $table = $snapshot->domain === 'clients' ? 'clients' : 'appointments';
        $field = $table === 'clients' ? 'first_name' : 'description';
        DB::table($table)->update([$field => 'Changed after import']);
        try {
            $importer->run($snapshot, false, true);
            $this->fail('Drift must block verification.');
        } catch (RuntimeException) {
            $this->assertSame('Changed after import', DB::table($table)->value($field));
        }
        $snapshot->sha256 = str_repeat('b', 64);
        $this->expectException(RuntimeException::class);
        $importer->run($snapshot, true);
    }

    public function test_nonempty_target_is_never_merged_or_overwritten(): void
    {
        config()->set('maintenance_import.enabled', true);
        DB::table('owner_subjects')->insert(['subject_id' => '23']);
        try {
            app(DomainImporter::class)->run($this->snapshot(), true);
            $this->fail('Only an empty candidate is accepted.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('maintenance_imports', 0);
            $this->assertDatabaseCount('owner_subjects', 1);
        }
    }

    public function test_partial_write_failure_rolls_back_rows_projections_and_receipt(): void
    {
        config()->set('maintenance_import.enabled', true);
        $table = config('maintenance_import.domain') === 'clients' ? 'vehicles' : 'appointments';
        DB::statement("CREATE TRIGGER fail_import BEFORE INSERT ON {$table} BEGIN SELECT RAISE(ABORT, 'synthetic failure'); END");
        try {
            app(DomainImporter::class)->run($this->snapshot(), true);
            $this->fail('The synthetic SQL failure must abort import.');
        } catch (QueryException) {
            $this->assertDatabaseCount('maintenance_imports', 0);
            $this->assertDatabaseCount('owner_subjects', 0);
            foreach (array_keys($this->snapshot()->tables) as $domainTable) {
                $this->assertDatabaseCount($domainTable, 0);
            }
        }
    }

    public function test_bad_digest_unknown_fields_and_missing_historical_references_are_refused(): void
    {
        $path = base_path('tests/Fixtures/maintenance-snapshot.json');
        try {
            DomainSnapshot::read($path, str_repeat('0', 64));
            $this->fail('Unapproved file must be rejected.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('maintenance_imports', 0);
        }
        $snapshot = $this->snapshot();
        $snapshot->document->tables->users = [];
        try {
            new DomainSnapshot($snapshot->document, $snapshot->sha256);
            $this->fail('Unknown tables must be rejected.');
        } catch (RuntimeException) {
            $this->assertFalse(Schema::hasTable('users'));
        }
        $snapshot = $this->snapshot();
        $table = $snapshot->domain === 'clients' ? 'appointment_references' : 'appointments';
        array_pop($snapshot->document->tables->$table);
        $this->expectException(RuntimeException::class);
        new DomainSnapshot($snapshot->document, $snapshot->sha256);
    }

    public function test_cli_requires_reviewed_fingerprint_and_returns_no_business_data(): void
    {
        $path = base_path('tests/Fixtures/maintenance-snapshot.json');
        $this->artisan('domain:import', ['snapshot' => $path, '--sha256' => str_repeat('0', 64)])->assertFailed();
        $this->artisan('domain:import', ['snapshot' => $path, '--sha256' => hash_file('sha256', $path)])->assertSuccessful();
        $this->assertDatabaseCount('maintenance_imports', 0);
    }

    public function test_unrepresentable_json_integer_is_rejected_instead_of_rounded_or_stringified(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'unsafe-json-');
        $bytes = file_get_contents(base_path('tests/Fixtures/maintenance-snapshot.json'));
        $bytes = preg_replace('/"data": \{/', '"data": {"unrepresentable": 9223372036854775808,', $bytes, 1);
        file_put_contents($file, $bytes);
        try {
            $this->expectException(RuntimeException::class);
            DomainSnapshot::read($file, hash('sha256', $bytes));
        } finally {
            unlink($file);
        }
    }

    public function test_corrupted_import_receipt_blocks_idempotent_replay(): void
    {
        config()->set('maintenance_import.enabled', true);
        $snapshot = $this->snapshot();
        $importer = app(DomainImporter::class);
        $importer->run($snapshot, true);
        DB::table('maintenance_imports')->update(['reference_digest' => str_repeat('0', 64)]);
        $this->expectException(RuntimeException::class);
        $importer->run($snapshot, true);
    }

    public function test_numeric_strings_inside_unknown_json_properties_remain_strings(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'json-string-');
        $bytes = file_get_contents(base_path('tests/Fixtures/maintenance-snapshot.json'));
        $bytes = preg_replace('/"data": \{/', '"data": {"preservedString": "9223372036854775808",', $bytes, 1);
        file_put_contents($file, $bytes);
        try {
            $snapshot = DomainSnapshot::read($file, hash('sha256', $bytes));
            $table = $snapshot->domain === 'clients' ? 'clients' : 'appointments';
            $this->assertSame('9223372036854775808', $snapshot->tables[$table][0]['data']->preservedString);
        } finally {
            unlink($file);
        }
    }
}
