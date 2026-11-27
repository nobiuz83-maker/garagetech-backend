<?php

namespace App\Services;

use App\Auth\CompanyActor;
use App\Models\Appointment;
use App\Models\OwnerSubject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AppointmentService
{
    public function __construct(private AppointmentScheduling $scheduling, private ClientAssociationRegistry $references, private CompanyRecordScope $scope, private AppointmentNotificationService $notifications, private AppointmentBrandAssetStorage $brandAssets) {}

    public function save(CompanyActor $actor, array $input, ?Appointment $appointment = null): Appointment
    {
        $appointmentId = $appointment?->id ?? (string) Str::uuid();
        $this->references->retain($actor, $appointmentId, $input);

        return DB::transaction(function () use ($actor, $input, $appointment, $appointmentId): Appointment {
            OwnerSubject::ensureExists($actor->subject);
            OwnerSubject::whereKey($actor->subject)->lockForUpdate()->firstOrFail();
            $appointment = $appointment ? Appointment::whereKey($appointment->id)->lockForUpdate()->firstOrFail() : null;
            if ($appointment !== null) {
                abort_if($appointment->version !== $input['version'], 409, 'APPOINTMENT_VERSION_CONFLICT');
            }
            $this->retainLocalAssociation($actor, $input);
            $this->scheduling->validate($actor, $input, $appointment);
            $details = $input['notificationDetails'] ?? $appointment?->data['notificationDetails'] ?? [];
            $existingLogoPath = $appointment?->data['notificationDetails']['companyLogoPath'] ?? null;
            if (! empty($details['companyLogo'])) {
                $details['companyLogoPath'] = $this->brandAssets->store($actor->companyId, $details['companyLogo']);
            } elseif (array_key_exists('companyLogo', $details)) {
                unset($details['companyLogoPath']);
            } elseif ($existingLogoPath) {
                $details['companyLogoPath'] = $existingLogoPath;
            }
            unset($details['companyLogo']);
            $input['notificationDetails'] = $details;
            $isNewAppointment = $appointment === null;
            $appointment ??= new Appointment;
            $appointment->id = $appointmentId;
            $appointment->user_id = $actor->subject;
            if ($isNewAppointment) {
                $appointment->company_id = $actor->companyId;
            }
            $appointment->fill($this->attributes($input, $appointment->data));
            $appointment->version = $appointment->exists ? $appointment->version + 1 : 1;
            $appointment->save();
            $this->notifications->synchronize($appointment, $details);

            return $appointment;
        });
    }

    public function cancel(CompanyActor $actor, Appointment $appointment, int $version): Appointment
    {
        return DB::transaction(function () use ($actor, $appointment, $version): Appointment {
            OwnerSubject::ensureExists($actor->subject);
            OwnerSubject::whereKey($actor->subject)->lockForUpdate()->firstOrFail();
            $appointment = Appointment::whereKey($appointment->id)->lockForUpdate()->firstOrFail();
            abort_if($appointment->version !== $version, 409, 'APPOINTMENT_VERSION_CONFLICT');
            if ($appointment->status === 'completed') {
                throw ValidationException::withMessages(['status' => 'APPOINTMENT_STATUS_INVALID']);
            }
            $notificationDetails = $appointment->data['notificationDetails'] ?? [];
            $appointment->data = [...$appointment->data, 'cancellationOrigin' => 'garage'];
            $appointment->status = 'cancelled';
            $appointment->version++;
            $appointment->save();
            $this->notifications->synchronize($appointment, []);
            $this->notifications->recordCancellation($appointment, $notificationDetails);

            return $appointment;
        });
    }

    public function archive(CompanyActor $actor, Appointment $appointment, int $version): void
    {
        DB::transaction(function () use ($actor, $appointment, $version): void {
            OwnerSubject::ensureExists($actor->subject);
            OwnerSubject::whereKey($actor->subject)->lockForUpdate()->firstOrFail();
            $appointment = Appointment::whereKey($appointment->id)->lockForUpdate()->firstOrFail();
            abort_if($appointment->version !== $version, 409, 'APPOINTMENT_VERSION_CONFLICT');
            $appointment->delete();
        });
    }

    public function import(CompanyActor $actor, array $records): array
    {
        foreach ($records as $input) {
            $this->references->retain($actor, $input['id'], $input);
        }

        return DB::transaction(function () use ($actor, $records): array {
            OwnerSubject::ensureExists($actor->subject);
            OwnerSubject::whereKey($actor->subject)->lockForUpdate()->firstOrFail();
            $created = 0;
            foreach ($records as $input) {
                $existing = Appointment::withTrashed()->find($input['id']);
                abort_if($existing && (! $this->scope->canAccessAppointment($actor, $existing) || $existing->trashed()
                    || $existing->client_id !== $input['clientId'] || $existing->vehicle_id !== $input['vehicleId']), 409, 'IMPORT_CONFLICT');
                $this->retainLocalAssociation($actor, $input);
                if ($existing) {
                    continue;
                }
                // Historical imports preserve bookings even when today's schedule has changed.
                $appointment = new Appointment($this->attributes($input));
                $appointment->id = $input['id'];
                $appointment->user_id = $actor->subject;
                $appointment->company_id = $actor->companyId;
                $appointment->created_at = $input['createdAt'];
                $appointment->updated_at = $input['updatedAt'];
                $appointment->save();
                $created++;
            }

            return ['createdAppointments' => $created, 'skippedAppointments' => count($records) - $created];
        });
    }

    private function retainLocalAssociation(CompanyActor $actor, array $input): void
    {
        DB::table('client_vehicle_associations')->upsert([
            ['client_id' => $input['clientId'], 'vehicle_id' => $input['vehicleId'], 'user_id' => $actor->subject, 'company_id' => $actor->companyId],
        ], ['client_id', 'vehicle_id'], ['client_id']);
        $association = DB::table('client_vehicle_associations')->where('client_id', $input['clientId'])->where('vehicle_id', $input['vehicleId'])->first();
        abort_unless((string) $association->user_id === $actor->subject, 409, 'ASSOCIATION_OWNER_CONFLICT');
    }

    private function attributes(array $input, ?array $existingDocument = null): array
    {
        $document = $existingDocument ?? ['schemaVersion' => 1];
        foreach (['addressDetails', 'serviceId', 'notificationDetails'] as $property) {
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
