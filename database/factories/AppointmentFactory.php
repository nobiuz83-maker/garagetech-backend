<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

class AppointmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'vehicle_id' => Vehicle::factory(),
            'client_id' => fn (array $attributes): string => Vehicle::findOrFail($attributes['vehicle_id'])->client_id,
            'user_id' => fn (array $attributes): int => Client::findOrFail($attributes['client_id'])->user_id,
            'date' => '2027-01-12', 'start_time' => '09:00', 'end_time' => '10:00',
            'status' => 'scheduled', 'service_type' => 'Oil change', 'description' => 'Replace oil', 'notes' => '',
            'data' => ['schemaVersion' => 1], 'version' => 1,
        ];
    }
}
