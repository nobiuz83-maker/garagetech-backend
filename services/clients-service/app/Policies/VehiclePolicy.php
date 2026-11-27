<?php

namespace App\Policies;

use App\Auth\CompanyActor;
use App\Models\Client;
use App\Models\Vehicle;
use App\Services\CompanyRecordScope;

class VehiclePolicy
{
    public function view(CompanyActor $actor, Vehicle $vehicle): bool
    {
        $client = Client::withTrashed()->whereKey($vehicle->client_id)->first();

        return $client !== null && app(CompanyRecordScope::class)->canAccessClient($actor, $client);
    }

    public function update(CompanyActor $actor, Vehicle $vehicle): bool
    {
        return $this->view($actor, $vehicle);
    }

    public function delete(CompanyActor $actor, Vehicle $vehicle): bool
    {
        return $this->view($actor, $vehicle);
    }

    public function restore(CompanyActor $actor, Vehicle $vehicle): bool
    {
        return $this->view($actor, $vehicle);
    }
}
