<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListClientVehiclesRequest;
use App\Http\Requests\SaveClientRequest;
use App\Http\Resources\ClientResource;
use App\Models\Client;
use App\Services\ClientService;
use App\Services\ClientVehicleSearch;
use App\Services\CompanyRecordScope;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class ClientController extends Controller
{
    public function index(ListClientVehiclesRequest $request, ClientVehicleSearch $search, CompanyRecordScope $scope): AnonymousResourceCollection
    {
        $query = $scope->clients(Client::query(), $request->user())->with('vehicles');
        $search->applySearch($query, $request->validated('search') ?? '', ['first_name', 'last_name', 'phone', 'email']);

        return ClientResource::collection($search->paginate($query, $request, [
            'createdAt' => 'created_at', 'firstName' => 'first_name', 'lastName' => 'last_name',
        ]));
    }

    public function show(Client $client): ClientResource
    {
        Gate::authorize('view', $client);

        return new ClientResource($client->load('vehicles'));
    }

    public function store(SaveClientRequest $request, ClientService $service): ClientResource
    {
        return new ClientResource($service->save($request->user(), $request->validated()));
    }

    public function update(SaveClientRequest $request, Client $client, ClientService $service): ClientResource
    {
        Gate::authorize('update', $client);

        return new ClientResource($service->save($request->user(), $request->validated(), $client));
    }

    public function destroy(Request $request, Client $client, ClientService $service): Response
    {
        Gate::authorize('delete', $client);
        $service->archive($request->user(), $client);

        return response()->noContent();
    }
}
