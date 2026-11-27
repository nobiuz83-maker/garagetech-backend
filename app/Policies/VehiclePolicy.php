<?php

namespace App\Policies;

use App\Models\Vehicle;
use App\Models\User;

class VehiclePolicy
{
    public function view(User $user, Vehicle $vehicle): bool
    {
        return $user->id === $vehicle->client->user_id;
    }

    public function update(User $user, Vehicle $vehicle): bool
    {
        return $this->view($user, $vehicle);
    }

    public function delete(User $user, Vehicle $vehicle): bool
    {
        return $this->view($user, $vehicle);
    }
}
