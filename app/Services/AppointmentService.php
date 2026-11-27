<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AppointmentService
{
    public function __construct(private AppointmentScheduling $scheduling) {}

    public function save(User $user, array $input, ?Appointment $appointment = null): Appointment
    {
        return DB::transaction(function () use ($user, $input, $appointment): Appointment {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $appointment = $appointment ? Appointment::whereKey($appointment->id)->lockForUpdate()->firstOrFail() : null;
            if ($appointment !== null) {
                abort_if($appointment->version !== $input['version'], 409, 'APPOINTMENT_VERSION_CONFLICT');
            }
            $this->validateAssociation($user, $input);
            $this->scheduling->validate($user, $input, $appointment);
            $appointment ??= new Appointment;
            $appointment->user_id = $user->id;
            $appointment->fill($this->attributes($input, $appointment->data));
            $appointment->version = $appointment->exists ? $appointment->version + 1 : 1;
            $appointment->save();

            return $appointment;
        });
    }

    public function cancel(User $user, Appointment $appointment, int $version): Appointment
    {
        return DB::transaction(function () use ($user, $appointment, $version): Appointment {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $appointment = Appointment::whereKey($appointment->id)->lockForUpdate()->firstOrFail();
            abort_if($appointment->version !== $version, 409, 'APPOINTMENT_VERSION_CONFLICT');
            if ($appointment->status === 'completed') {
                throw ValidationException::withMessages(['status' => 'APPOINTMENT_STATUS_INVALID']);
            }
            $appointment->status = 'cancelled';
            $appointment->version++;
            $appointment->save();

            return $appointment;
        });
    }

    public function archive(User $user, Appointment $appointment, int $version): void
    {
        DB::transaction(function () use ($user, $appointment, $version): void {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $appointment = Appointment::whereKey($appointment->id)->lockForUpdate()->firstOrFail();
            abort_if($appointment->version !== $version, 409, 'APPOINTMENT_VERSION_CONFLICT');
            $appointment->delete();
        });
    }

    public function import(User $user, array $records): array
    {
        return DB::transaction(function () use ($user, $records): array {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $created = 0;
            foreach ($records as $input) {
                $existing = Appointment::withTrashed()->find($input['id']);
                abort_if($existing && ($existing->user_id !== $user->id || $existing->trashed()
                    || $existing->client_id !== $input['clientId'] || $existing->vehicle_id !== $input['vehicleId']), 409, 'IMPORT_CONFLICT');
                $this->validateAssociation($user, $input);
                if ($existing) {
                    continue;
                }
                // Historical imports preserve bookings even when today's schedule has changed.
                $appointment = new Appointment($this->attributes($input));
                $appointment->id = $input['id'];
                $appointment->user_id = $user->id;
                $appointment->created_at = $input['createdAt'];
                $appointment->updated_at = $input['updatedAt'];
                $appointment->save();
                $created++;
            }

            return ['createdAppointments' => $created, 'skippedAppointments' => count($records) - $created];
        });
    }

    private function validateAssociation(User $user, array $input): void
    {
        $client = Client::where('user_id', $user->id)->whereKey($input['clientId'])->lockForUpdate()->first();
        if ($client === null) {
            throw ValidationException::withMessages(['clientId' => 'APPOINTMENT_CLIENT_REQUIRED']);
        }
        if (! $client->vehicles()->whereKey($input['vehicleId'])->exists()) {
            throw ValidationException::withMessages(['vehicleId' => 'APPOINTMENT_VEHICLE_REQUIRED']);
        }
    }

    private function attributes(array $input, ?array $existingDocument = null): array
    {
        $document = $existingDocument ?? ['schemaVersion' => 1];
        foreach (['addressDetails', 'serviceId'] as $property) {
            if (array_key_exists($property, $input)) {
                if ($input[$property] === null) {
                    unset($document[$property]);
                } else {
                    $document[$property] = $property === 'addressDetails'
                        ? array_map(fn ($value) => $value ?? '', $input[$property])
                        : $input[$property];
                }
            }
        }
        $document['address'] = $input['address'] ?? '';
        if (isset($input['data']['legacyProperties'])) {
            $document['legacyProperties'] = $input['data']['legacyProperties'];
        }

        return [
            'client_id' => $input['clientId'], 'vehicle_id' => $input['vehicleId'],
            'date' => $input['date'], 'start_time' => $input['startTime'], 'end_time' => $input['endTime'],
            'status' => $input['status'], 'service_type' => $input['serviceType'],
            'description' => $input['description'], 'notes' => $input['notes'] ?? '', 'data' => $document,
        ];
    }
}
