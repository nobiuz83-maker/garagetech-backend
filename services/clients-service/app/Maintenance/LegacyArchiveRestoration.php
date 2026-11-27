<?php

namespace App\Maintenance;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

/** Explicit operator decisions do not reconstruct or claim historical archive provenance. */
class LegacyArchiveRestoration
{
    public function __construct(private DomainImporter $guard) {}

    public function run(stdClass $plan, string $planSha256, bool $apply): array
    {
        $this->guard->guard($apply);
        DomainSnapshot::exactKeys((array) $plan, ['schemaVersion', 'actionId', 'operation', 'reversesActionId', 'operator', 'reviewer', 'reason', 'ticket', 'records']);
        DomainSnapshot::require($plan->schemaVersion === 1);
        DomainSnapshot::uuid($plan->actionId);
        DomainSnapshot::require(in_array($plan->operation, ['restore', 'rollback'], true));
        foreach (['operator', 'reviewer', 'reason', 'ticket'] as $property) {
            DomainSnapshot::require(is_string($plan->$property) && strlen(trim($plan->$property)) >= 3 && strlen($plan->$property) <= 1000);
        }
        DomainSnapshot::require($plan->operator !== $plan->reviewer);
        DomainSnapshot::require(is_array($plan->records) && count($plan->records) > 0 && count($plan->records) <= 100);
        if ($plan->operation === 'rollback') {
            DomainSnapshot::uuid($plan->reversesActionId);
        } else {
            DomainSnapshot::require($plan->reversesActionId === null);
        }

        return DB::transaction(function () use ($plan, $planSha256, $apply): array {
            DB::table('maintenance_locks')->where('id', 1)->lockForUpdate()->firstOrFail();
            $existing = DB::table('manual_archive_actions')->where('id', $plan->actionId)->first();
            if ($existing !== null) {
                DomainSnapshot::require(hash_equals($existing->plan_sha256, $planSha256));
                DomainSnapshot::require(DB::table('manual_archive_actions')->where('reverses_action_id', $plan->actionId)->doesntExist());
                $changes = $this->auditRecords($existing->records);
                DomainSnapshot::require(hash_equals($existing->family_digest, $this->familyDigest($changes)));

                return ['status' => 'verified-existing', 'actionId' => $plan->actionId];
            }
            if ($plan->operation === 'rollback') {
                return $this->rollback($plan, $planSha256, $apply);
            }
            $changes = $this->review($plan->records, $apply);
            if (! $apply) {
                return ['status' => 'planned', 'actionId' => $plan->actionId, 'records' => $changes, 'historicalProvenanceInferred' => false];
            }
            foreach ($changes as &$change) {
                DB::table($change['table'])->where('id', $change['id'])->update(['deleted_at' => null]);
                $change['afterSha256'] = $this->rowDigest($change['table'], $change['id']);
            }
            unset($change);
            $this->record($plan, $planSha256, $changes);

            return ['status' => 'restored', 'actionId' => $plan->actionId, 'count' => count($changes), 'historicalProvenanceInferred' => false];
        });
    }

    public function rowDigest(string $table, string $identifier): string
    {
        DomainSnapshot::require(in_array($table, ['clients', 'vehicles'], true));
        $row = DB::table($table)->where('id', $identifier)->first();
        DomainSnapshot::require($row !== null);
        if ($row->data !== null) {
            $row->data = json_decode($row->data, false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        }
        if (isset($row->user_id)) {
            $row->user_id = (string) $row->user_id;
        }
        if (isset($row->year)) {
            $row->year = (int) $row->year;
        }

        return hash('sha256', DomainSnapshot::canonical($row));
    }

    private function review(array $records, bool $apply): array
    {
        $changes = [];
        $clientIds = [];
        foreach ($records as $record) {
            DomainSnapshot::require($record instanceof stdClass);
            DomainSnapshot::exactKeys((array) $record, ['entityType', 'id', 'expectedSha256']);
            DomainSnapshot::require(in_array($record->entityType, ['client', 'vehicle'], true));
            DomainSnapshot::uuid($record->id);
            $table = $record->entityType === 'client' ? 'clients' : 'vehicles';
            $key = $table.'|'.$record->id;
            DomainSnapshot::require(! isset($changes[$key]));
            $row = DB::table($table)->where('id', $record->id)->lockForUpdate()->first();
            DomainSnapshot::require($row !== null && $row->deleted_at !== null);
            DomainSnapshot::require(DB::table('archive_events')->where('entity_type', $record->entityType)->where('entity_id', $record->id)->doesntExist());
            $digest = $this->rowDigest($table, $record->id);
            DomainSnapshot::require($record->expectedSha256 === null || (is_string($record->expectedSha256) && hash_equals($record->expectedSha256, $digest)));
            DomainSnapshot::require(! $apply || $record->expectedSha256 !== null);
            $changes[$key] = ['table' => $table, 'id' => $record->id, 'deletedAt' => $row->deleted_at, 'beforeSha256' => $digest];
            if ($table === 'clients') {
                $clientIds[] = $record->id;
            }
        }
        foreach ($changes as $change) {
            if ($change['table'] === 'vehicles') {
                $vehicle = DB::table('vehicles')->where('id', $change['id'])->first();
                $parent = DB::table('clients')->where('id', $vehicle->client_id)->lockForUpdate()->first();
                DomainSnapshot::require($parent !== null && ($parent->deleted_at === null || in_array($parent->id, $clientIds, true)));
            }
        }

        return array_values($changes);
    }

    private function rollback(stdClass $plan, string $planSha256, bool $apply): array
    {
        $original = DB::table('manual_archive_actions')->where('id', $plan->reversesActionId)->lockForUpdate()->first();
        DomainSnapshot::require($original !== null && $original->operation === 'restore');
        DomainSnapshot::require(DB::table('manual_archive_actions')->where('reverses_action_id', $original->id)->doesntExist());
        $changes = $this->auditRecords($original->records);
        $selected = [];
        foreach ($plan->records as $record) {
            DomainSnapshot::require($record instanceof stdClass);
            DomainSnapshot::exactKeys((array) $record, ['entityType', 'id', 'expectedSha256']);
            DomainSnapshot::require(in_array($record->entityType, ['client', 'vehicle'], true));
            DomainSnapshot::uuid($record->id);
            $selected[] = ($record->entityType === 'client' ? 'clients' : 'vehicles').'|'.$record->id.'|'.$record->expectedSha256;
        }
        $expected = array_map(fn (array $change): string => $change['table'].'|'.$change['id'].'|'.$change['afterSha256'], $changes);
        sort($selected);
        sort($expected);
        DomainSnapshot::require($selected === $expected);
        DomainSnapshot::require(hash_equals($original->family_digest, $this->familyDigest($changes)));
        foreach ($changes as $change) {
            DomainSnapshot::require(hash_equals($change['afterSha256'], $this->rowDigest($change['table'], $change['id'])));
        }
        if (! $apply) {
            return ['status' => 'rollback-planned', 'actionId' => $plan->actionId];
        }
        foreach ($changes as $change) {
            DB::table($change['table'])->where('id', $change['id'])->update(['deleted_at' => $change['deletedAt']]);
            DomainSnapshot::require(hash_equals($change['beforeSha256'], $this->rowDigest($change['table'], $change['id'])));
        }
        $this->record($plan, $planSha256, $changes);

        return ['status' => 'rolled-back', 'actionId' => $plan->actionId, 'count' => count($changes)];
    }

    private function familyDigest(array $changes): string
    {
        $parents = [];
        foreach ($changes as $change) {
            $parents[] = $change['table'] === 'clients' ? $change['id'] : DB::table('vehicles')->where('id', $change['id'])->value('client_id');
        }
        $parents = array_unique($parents);
        sort($parents);
        $state = [];
        foreach ($parents as $parent) {
            $state[] = $this->rowDigest('clients', $parent);
            foreach (DB::table('vehicles')->where('client_id', $parent)->orderBy('id')->pluck('id') as $vehicle) {
                $state[] = $this->rowDigest('vehicles', $vehicle);
            }
            $references = DB::table('appointment_references')->where('client_id', $parent)->orderBy('id')->get()->all();
            $state[] = DomainSnapshot::canonical($references);
            $vehicles = DB::table('vehicles')->where('client_id', $parent)->pluck('id');
            $events = DB::table('archive_events')->where(function (Builder $query) use ($parent, $vehicles): void {
                $query->where(function (Builder $clientQuery) use ($parent): void {
                    $clientQuery->where('entity_type', 'client')->where('entity_id', $parent);
                })->orWhere(function (Builder $vehicleQuery) use ($vehicles): void {
                    $vehicleQuery->where('entity_type', 'vehicle')->whereIn('entity_id', $vehicles);
                });
            })->orderBy('id')->get()->all();
            $state[] = DomainSnapshot::canonical($events);
        }

        return hash('sha256', DomainSnapshot::canonical($state));
    }

    private function auditRecords(string $document): array
    {
        $audit = json_decode($document, true, 512, JSON_THROW_ON_ERROR);
        DomainSnapshot::exactKeys($audit, ['schemaVersion', 'records']);
        DomainSnapshot::require($audit['schemaVersion'] === 1 && is_array($audit['records']));

        return $audit['records'];
    }

    private function record(stdClass $plan, string $planSha256, array $changes): void
    {
        DB::table('manual_archive_actions')->insert([
            'id' => $plan->actionId, 'operation' => $plan->operation, 'reverses_action_id' => $plan->reversesActionId,
            'operator' => $plan->operator, 'reviewer' => $plan->reviewer, 'reason' => $plan->reason, 'ticket' => $plan->ticket,
            'plan_sha256' => $planSha256, 'records' => json_encode(['schemaVersion' => 1, 'records' => $changes], JSON_THROW_ON_ERROR),
            'family_digest' => $this->familyDigest($changes), 'created_at' => now(),
        ]);
    }
}
