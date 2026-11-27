<?php

namespace App\Http\Controllers\Api;

use App\Auth\CompanyActor;
use App\Http\Resources\ClientResource;
use App\Http\Resources\VehicleResource;
use App\Models\Client;
use App\Models\Vehicle;
use App\Services\ClientArchiving;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ClientRestoreController
{
    public function client(Request $request, Client $client, ClientArchiving $archiving): ClientResource
    {
        Gate::authorize('restore', $client);

        /** @var CompanyActor $actor */
        $actor = $request->user();

        return new ClientResource($archiving->restoreClient($actor, $client));
    }

    public function vehicle(Request $request, Vehicle $vehicle, ClientArchiving $archiving): VehicleResource
    {
        Gate::authorize('restore', $vehicle);

        /** @var CompanyActor $actor */
        $actor = $request->user();

        return new VehicleResource($archiving->restoreVehicle($actor, $vehicle));
    }

    public function clientHistory(Request $request, Client $client): JsonResponse
    {
        Gate::authorize('view', $client);
        $vehicleIds = $client->vehicles()->withTrashed()->pluck('id');
        $query = DB::table('archive_events')->where(function (Builder $query) use ($client, $vehicleIds): void {
            $query->where(function (Builder $clientEvents) use ($client): void {
                $clientEvents->where('entity_type', 'client')->where('entity_id', $client->id);
            })->orWhere(function (Builder $vehicleEvents) use ($vehicleIds): void {
                $vehicleEvents->where('entity_type', 'vehicle')->whereIn('entity_id', $vehicleIds);
            });
        });

        return $this->history($query, $request);
    }

    public function vehicleHistory(Request $request, Vehicle $vehicle): JsonResponse
    {
        Gate::authorize('view', $vehicle);

        return $this->history(DB::table('archive_events')->where('entity_type', 'vehicle')->where('entity_id', $vehicle->id), $request);
    }

    private function history(Builder $query, Request $request): JsonResponse
    {
        $input = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'perPage' => ['sometimes', 'integer', 'between:1,100']]);
        $pagination = $query->orderBy('id')->paginate((int) ($input['perPage'] ?? 50));
        $events = $pagination->getCollection()->map(fn (object $event): array => [
            'id' => (string) $event->id, 'batchId' => $event->batch_id, 'entityType' => $event->entity_type,
            'entityId' => $event->entity_id, 'action' => $event->action, 'actorSubjectId' => (string) $event->actor_subject_id,
            'occurredAt' => CarbonImmutable::parse($event->occurred_at, 'UTC')->toISOString(),
        ]);

        return response()->json(['data' => $events, 'meta' => ['scope' => 'events-recorded-by-clients-service', 'legacyProvenanceInferred' => false, 'current_page' => $pagination->currentPage(), 'last_page' => $pagination->lastPage(), 'total' => $pagination->total()]])->header('Cache-Control', 'no-store');
    }
}
