<?php

namespace App\Services;

use App\Auth\CompanyActor;
use App\Models\Client;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ClientArchiving
{
    public function archiveClient(CompanyActor $actor, Client $client): void
    {
        DB::transaction(function () use ($actor, $client): void {
            $client = Client::whereKey($client->id)->lockForUpdate()->firstOrFail();
            $this->assertHistoryAllowsArchive('client_id', $client->id);
            $batch = (string) Str::uuid();
            foreach ($client->vehicles()->get() as $vehicle) {
                $vehicle->delete();
                $this->record($actor, 'vehicle', $vehicle->id, 'archived', $batch);
            }
            $client->delete();
            $this->record($actor, 'client', $client->id, 'archived', $batch);
        });
    }

    public function archiveVehicle(CompanyActor $actor, Vehicle $vehicle): void
    {
        DB::transaction(function () use ($actor, $vehicle): void {
            $client = Client::whereKey($vehicle->client_id)->lockForUpdate()->firstOrFail();
            $vehicle = $client->vehicles()->whereKey($vehicle->id)->firstOrFail();
            if ($client->vehicles()->count() <= 1) {
                throw ValidationException::withMessages(['vehicle' => 'LAST_VEHICLE_REQUIRED']);
            }
            $this->assertHistoryAllowsArchive('vehicle_id', $vehicle->id);
            $vehicle->delete();
            $this->record($actor, 'vehicle', $vehicle->id, 'archived', (string) Str::uuid());
        });
    }

    public function restoreClient(CompanyActor $actor, Client $client): Client
    {
        return DB::transaction(function () use ($actor, $client): Client {
            $client = Client::withTrashed()->whereKey($client->id)->lockForUpdate()->firstOrFail();
            if (! $client->trashed()) {
                return $client->load('vehicles');
            }
            $event = $this->archiveEvent('client', $client->id);
            $client->restore();
            $vehicleIds = DB::table('archive_events')->where('batch_id', $event->batch_id)
                ->where('entity_type', 'vehicle')->where('action', 'archived')->pluck('entity_id');
            foreach ($client->vehicles()->onlyTrashed()->whereIn('id', $vehicleIds)->get() as $vehicle) {
                $vehicle->restore();
                $this->record($actor, 'vehicle', $vehicle->id, 'restored', $event->batch_id);
            }
            $this->record($actor, 'client', $client->id, 'restored', $event->batch_id);

            return $client->load('vehicles');
        });
    }

    public function restoreVehicle(CompanyActor $actor, Vehicle $vehicle): Vehicle
    {
        return DB::transaction(function () use ($actor, $vehicle): Vehicle {
            $client = Client::withTrashed()->whereKey($vehicle->client_id)->lockForUpdate()->firstOrFail();
            abort_if($client->trashed(), 409, 'PARENT_CLIENT_ARCHIVED');
            $vehicle = Vehicle::withTrashed()->whereKey($vehicle->id)->firstOrFail();
            if (! $vehicle->trashed()) {
                return $vehicle;
            }
            $event = $this->archiveEvent('vehicle', $vehicle->id);
            $vehicle->restore();
            $this->record($actor, 'vehicle', $vehicle->id, 'restored', $event->batch_id);

            return $vehicle;
        });
    }

    private function assertHistoryAllowsArchive(string $column, string $identifier): void
    {
        abort_unless(config('relationships.registry_ready'), 503, 'HISTORY_VERIFICATION_UNAVAILABLE');
        if (DB::table('appointment_references')->where($column, $identifier)->exists()) {
            throw ValidationException::withMessages(['history' => 'HISTORY_REFERENCED']);
        }
    }

    private function archiveEvent(string $type, string $identifier): object
    {
        $event = DB::table('archive_events')->where('entity_type', $type)->where('entity_id', $identifier)->latest('id')->first();
        abort_if($event === null || $event->action !== 'archived', 409, 'ARCHIVE_PROVENANCE_REQUIRED');

        return $event;
    }

    private function record(CompanyActor $actor, string $type, string $identifier, string $action, string $batch): void
    {
        DB::table('archive_events')->insert([
            'batch_id' => $batch, 'entity_type' => $type, 'entity_id' => $identifier,
            'action' => $action, 'actor_subject_id' => $actor->subject, 'occurred_at' => now(),
        ]);
    }
}
