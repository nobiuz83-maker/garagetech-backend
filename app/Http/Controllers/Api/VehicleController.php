<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListClientVehiclesRequest;
use App\Http\Requests\SaveVehicleRequest;
use App\Http\Resources\VehicleResource;
use App\Models\Client;
use App\Models\Vehicle;
use App\Services\ClientService;
use App\Services\ClientVehicleSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class VehicleController extends Controller
{
    public function index(ListClientVehiclesRequest $request, ClientVehicleSearch $search): AnonymousResourceCollection
    {
        $query = Vehicle::whereHas('client', fn ($clients) => $clients->where('user_id', $request->user()->id));
        if ($request->filled('clientId')) {
            $query->where('client_id', $request->string('clientId')->toString());
        }

        return $this->collection($query, $request, $search);
    }

    public function forClient(ListClientVehiclesRequest $request, Client $client, ClientVehicleSearch $search): AnonymousResourceCollection
    {
        Gate::authorize('view', $client);

        return $this->collection($client->vehicles()->getQuery(), $request, $search);
    }

    private function collection(Builder $query, ListClientVehiclesRequest $request, ClientVehicleSearch $search): AnonymousResourceCollection
    {
        foreach (['make' => 'make', 'year' => 'year'] as $filter => $column) {
            if ($request->filled($filter)) {
                $query->where($column, $request->validated($filter));
            }
        }
        $search->applySearch($query, $request->validated('search') ?? '', ['make', 'model', 'year', 'vin', 'license_plate']);

        return VehicleResource::collection($search->paginate($query, $request, [
            'createdAt' => 'created_at', 'make' => 'make', 'model' => 'model', 'year' => 'year',
        ]));
    }

    public function show(Vehicle $vehicle): VehicleResource
    {
        Gate::authorize('view', $vehicle);

        return new VehicleResource($vehicle);
    }

    public function store(SaveVehicleRequest $request, ClientService $service): VehicleResource
    {
        $client = Client::where('user_id', $request->user()->id)->findOrFail($request->validated('clientId'));

        return new VehicleResource($service->saveVehicle($client, $request->validated()));
    }

    public function update(SaveVehicleRequest $request, Vehicle $vehicle, ClientService $service): VehicleResource
    {
        Gate::authorize('update', $vehicle);
        if ($request->has('clientId') && $request->validated('clientId') !== $vehicle->client_id) {
            throw ValidationException::withMessages(['clientId' => 'VEHICLE_CLIENT_MISMATCH']);
        }

        return new VehicleResource($service->saveVehicle($vehicle->client, $request->validated(), $vehicle));
    }

    public function destroy(Vehicle $vehicle, ClientService $service): Response
    {
        Gate::authorize('delete', $vehicle);
        $service->archiveVehicle($vehicle);

        return response()->noContent();
    }
}
