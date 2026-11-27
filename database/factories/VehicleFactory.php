<?php

namespace Database\Factories;

use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

class VehicleFactory extends Factory
{
    public function definition(): array
    {
        return ['client_id' => Client::factory(), 'make' => 'Toyota', 'model' => 'Corolla', 'year' => 2020, 'vin' => '', 'license_plate' => ''];
    }
}
