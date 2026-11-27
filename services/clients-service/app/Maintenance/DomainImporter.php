<?php

namespace App\Maintenance;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DomainImporter
{
    public function guard(bool $writing): void
    {
        $isUnitTest = app()->runningUnitTests() && defined('PHPUNIT_COMPOSER_INSTALL');
        $connection = DB::connection();
        $database = $connection->getDatabaseName();
        DomainSnapshot::require(($connection->getDriverName() === 'sqlite' && $database === ':memory:' && $isUnitTest)
            || ($connection->getDriverName() === 'mysql' && preg_match('/^vicartech_(test|candidate)_[a-z0-9_]+$/', $database) === 1));
        DomainSnapshot::require(! Schema::hasTable('users') && ! Schema::hasTable('sessions'));
        if ($connection->getDriverName() === 'mysql') {
            $connection->statement("SET time_zone = '+00:00'");
        }
        if ($writing) {
            DomainSnapshot::require(config('maintenance_import.enabled') === true);
            DomainSnapshot::require($isUnitTest || app()->isDownForMaintenance());
        }
    }

    public function run(DomainSnapshot $snapshot, bool $apply, bool $verify = false): array
    {
        $this->guard($apply);

        return DB::transaction(function () use ($snapshot, $apply, $verify): array {
            DB::table('maintenance_locks')->where('id', 1)->lockForUpdate()->firstOrFail();
            $batch = DB::table('maintenance_imports')->where('migration_id', $snapshot->document->migrationId)->first();
            if ($batch !== null) {
                DomainSnapshot::require(hash_equals($batch->payload_sha256, $snapshot->sha256));
                DomainSnapshot::require(hash_equals($batch->source_sha256, $snapshot->document->sourceSha256));
                DomainSnapshot::require(hash_equals($batch->reference_digest, $snapshot->document->referenceDigest));
                DomainSnapshot::require(DomainSnapshot::canonical(json_decode($batch->counts, true, 512, JSON_THROW_ON_ERROR)) === DomainSnapshot::canonical(array_map('count', $snapshot->tables)));
                $this->verify($snapshot);

                return $this->receipt($snapshot, 'verified-existing');
            }
            DomainSnapshot::require(! $verify);
            foreach (array_merge(['owner_subjects', 'maintenance_imports'], array_keys($snapshot->tables)) as $table) {
                DomainSnapshot::require(DB::table($table)->count() === 0);
            }
            if (! $apply) {
                return $this->receipt($snapshot, 'planned');
            }
            DB::table('maintenance_imports')->insert([
                'migration_id' => $snapshot->document->migrationId, 'payload_sha256' => $snapshot->sha256,
                'source_sha256' => $snapshot->document->sourceSha256, 'reference_digest' => $snapshot->document->referenceDigest,
                'counts' => json_encode(array_map('count', $snapshot->tables), JSON_THROW_ON_ERROR), 'completed_at' => now(),
            ]);
            foreach ($snapshot->owners as $owner) {
                DB::table('owner_subjects')->insert(['subject_id' => $owner]);
            }
            foreach ($snapshot->tables as $table => $records) {
                foreach ($records as $record) {
                    if (array_key_exists('data', $record) && $record['data'] !== null) {
                        $record['data'] = DomainSnapshot::canonical($record['data']);
                    }
                    DB::table($table)->insert($record);
                }
            }
            $this->verify($snapshot);

            return $this->receipt($snapshot, 'imported');
        });
    }

    public function verify(DomainSnapshot $snapshot): void
    {
        $actualOwners = DB::table('owner_subjects')->pluck('subject_id')->map(fn (mixed $owner): string => (string) $owner)->all();
        $expectedOwners = $snapshot->owners;
        sort($actualOwners);
        sort($expectedOwners);
        DomainSnapshot::require($actualOwners === $expectedOwners);
        foreach ($snapshot->tables as $table => $expectedRows) {
            $actualRows = [];
            foreach (DB::table($table)->get() as $stored) {
                $row = [];
                foreach (DomainSnapshot::TABLES[$snapshot->domain][$table] as $column) {
                    $value = $stored->$column;
                    if ($column === 'data' && $value !== null) {
                        $value = json_decode($value, false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
                    } elseif (in_array($column, ['id', 'user_id', 'owner_subject_id'], true)) {
                        $value = (string) $value;
                    } elseif (in_array($column, ['year', 'version'], true)) {
                        $value = (int) $value;
                    }
                    $row[$column] = $value;
                }
                $actualRows[$snapshot->identity($table, $row)] = DomainSnapshot::canonical($row);
            }
            $expected = [];
            foreach ($expectedRows as $row) {
                $expected[$snapshot->identity($table, $row)] = DomainSnapshot::canonical($row);
            }
            ksort($actualRows);
            ksort($expected);
            DomainSnapshot::require($actualRows === $expected);
        }
    }

    private function receipt(DomainSnapshot $snapshot, string $status): array
    {
        return ['status' => $status, 'domain' => $snapshot->domain, 'migrationId' => $snapshot->document->migrationId,
            'sourceSha256' => $snapshot->document->sourceSha256, 'payloadSha256' => $snapshot->sha256,
            'referenceDigest' => $snapshot->document->referenceDigest, 'counts' => array_map('count', $snapshot->tables),
            'registryActivated' => false];
    }
}
