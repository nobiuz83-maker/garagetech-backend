<?php

namespace App\Services;

use App\Auth\CompanyActor;
use App\Models\Client;
use App\Models\OwnerSubject;
use App\Models\Vehicle;
use App\Support\HybridDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClientService
{
    public function __construct(private ClientArchiving $archiving, private CompanyRecordScope $scope) {}

    public function save(CompanyActor $actor, array $input, ?Client $client = null): Client
    {
        return DB::transaction(function () use ($actor, $input, $client): Client {
            if ($client !== null) {
                $client = Client::whereKey($client->id)->lockForUpdate()->firstOrFail();
            } else {
                OwnerSubject::ensureExists($actor->subject);
                $client = new Client;
                $client->user_id = $actor->subject;
                $client->company_id = $actor->companyId;
            }
            $attributes = $this->clientAttributes($input);
            $attributes['data'] = HybridDocument::merge($client->data, $attributes['data']);
            if (array_key_exists('address', $input)) {
                // Address forms submit a full replacement, including removal of stale coordinates.
                $attributes['data']['address'] = $this->normalizeAddress($input['address']);
            }
            $client->fill($attributes)->save();
            if (! array_key_exists('vehicles', $input)) {
                return $client->load('vehicles');
            }
            $retainedVehicleIds = [];
            foreach ($input['vehicles'] as $vehicleInput) {
                $vehicle = isset($vehicleInput['id'])
                    ? $client->vehicles()->whereKey($vehicleInput['id'])->first()
                    : new Vehicle;
                if ($vehicle === null) {
                    throw ValidationException::withMessages(['vehicles' => 'VEHICLE_CLIENT_MISMATCH']);
                }
                $attributes = $this->vehicleAttributes($vehicleInput);
                $attributes['data'] = HybridDocument::merge($vehicle->data, $attributes['data']);
                $vehicle->fill($attributes);
                $client->vehicles()->save($vehicle);
                $retainedVehicleIds[] = $vehicle->id;
            }
            $removedVehicles = $client->vehicles()->whereNotIn('id', $retainedVehicleIds)->get();
            foreach ($removedVehicles as $removedVehicle) {
                $this->archiving->archiveVehicle($actor, $removedVehicle);
            }

            return $client->load('vehicles');
        });
    }

    public function archive(CompanyActor $actor, Client $client): void
    {
        $this->archiving->archiveClient($actor, $client);
    }

    public function saveVehicle(Client $client, array $input, ?Vehicle $vehicle = null): Vehicle
    {
        return DB::transaction(function () use ($client, $input, $vehicle): Vehicle {
            $client = Client::whereKey($client->id)->lockForUpdate()->firstOrFail();
            $vehicle = $vehicle === null ? new Vehicle : $client->vehicles()->whereKey($vehicle->id)->firstOrFail();
            $attributes = $this->vehicleAttributes($input);
            $attributes['data'] = HybridDocument::merge($vehicle->data, $attributes['data']);
            $vehicle->fill($attributes);
            $client->vehicles()->save($vehicle);

            return $vehicle;
        });
    }

    public function archiveVehicle(CompanyActor $actor, Vehicle $vehicle): void
    {
        $this->archiving->archiveVehicle($actor, $vehicle);
    }

    public function import(CompanyActor $actor, array $input): array
    {
        return DB::transaction(function () use ($actor, $input): array {
            // Serialize imports for one account so retries never duplicate records.
            OwnerSubject::ensureExists($actor->subject);
            OwnerSubject::whereKey($actor->subject)->lockForUpdate()->firstOrFail();
            $createdClients = 0;
            $createdVehicles = 0;
            foreach ($input['clients'] as $clientInput) {
                $client = Client::withTrashed()->find($clientInput['id']);
                abort_if($client !== null && (! $this->scope->canAccessClient($actor, $client) || $client->trashed()), 409, 'IMPORT_CONFLICT');
                if ($client === null) {
                    $client = new Client($this->clientAttributes($clientInput));
                    $client->id = $clientInput['id'];
                    $client->user_id = $actor->subject;
                    $client->company_id = $actor->companyId;
                    $client->created_at = $clientInput['createdAt'];
                    $client->save();
                    $createdClients++;
                }
            }
            foreach ($input['vehicles'] as $vehicleInput) {
                $client = Client::where('user_id', $actor->subject)->whereKey($vehicleInput['clientId'])->lockForUpdate()->first();
                if ($client === null) {
                    throw ValidationException::withMessages(['vehicles' => 'VEHICLE_CLIENT_MISMATCH']);
                }
                $vehicle = Vehicle::withTrashed()->find($vehicleInput['id']);
                abort_if($vehicle !== null && ($vehicle->client_id !== $client->id || $vehicle->trashed()), 409, 'IMPORT_CONFLICT');
                if ($vehicle === null) {
                    $vehicle = new Vehicle($this->vehicleAttributes($vehicleInput));
                    $vehicle->id = $vehicleInput['id'];
                    $vehicle->created_at = $vehicleInput['createdAt'];
                    $client->vehicles()->save($vehicle);
                    $createdVehicles++;
                }
            }

            return [
                'createdClients' => $createdClients,
                'createdVehicles' => $createdVehicles,
                'skippedClients' => count($input['clients']) - $createdClients,
                'skippedVehicles' => count($input['vehicles']) - $createdVehicles,
            ];
        });
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function clientAttributes(array $input): array
    {
        $attributes = $this->relationalAttributes($input, [
            'firstName' => 'first_name', 'lastName' => 'last_name', 'phone' => 'phone', 'email' => 'email',
        ]);
        $attributes['data'] = ['schemaVersion' => 1, ...($input['data'] ?? [])];
        if (array_key_exists('address', $input)) {
            $attributes['data']['address'] = $this->normalizeAddress($input['address']);
        }

        return $attributes;
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function vehicleAttributes(array $input): array
    {
        return $this->relationalAttributes($input, [
            'make' => 'make', 'model' => 'model', 'year' => 'year', 'vin' => 'vin', 'licensePlate' => 'license_plate',
        ]) + ['data' => $input['data'] ?? ['schemaVersion' => 1]];
    }

    /** @param array<string, mixed> $input
     * @param  array<string, string>  $columns
     * @return array<string, mixed>
     */
    private function relationalAttributes(array $input, array $columns): array
    {
        $attributes = [];
        foreach ($columns as $property => $column) {
            if (array_key_exists($property, $input)) {
                $attributes[$column] = $input[$property] ?? '';
            }
        }

        return $attributes;
    }

    /** @param array<string, mixed>|null $address
     * @return array<string, mixed>|null
     */
    private function normalizeAddress(?array $address): ?array
    {
        if ($address === null) {
            return null;
        }
        foreach (['formatted', 'houseNumber', 'street', 'city', 'province', 'postalCode', 'country', 'countryCode', 'provinceCode'] as $field) {
            if (array_key_exists($field, $address) && $address[$field] === null) {
                $address[$field] = '';
            }
        }

        return $address;
    }
}
