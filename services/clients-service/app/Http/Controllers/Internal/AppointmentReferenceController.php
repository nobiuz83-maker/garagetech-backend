<?php

namespace App\Http\Controllers\Internal;

use App\Auth\CompanyActor;
use App\Http\Requests\RetainAppointmentReferenceRequest;
use App\Http\Requests\SearchClientVehicleReferencesRequest;
use App\Models\Client;
use App\Models\OwnerSubject;
use App\Models\Vehicle;
use App\Services\CompanyRecordScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AppointmentReferenceController
{
    public function store(RetainAppointmentReferenceRequest $request, CompanyRecordScope $scope): JsonResponse
    {
        $input = $request->validated();
        /** @var CompanyActor $actor */
        $actor = $request->user();
        $subject = $actor->subject;
        DB::transaction(function () use ($input, $subject, $actor, $scope): void {
            OwnerSubject::ensureExists($subject);
            DB::table('appointment_subjects')->upsert([
                ['appointment_id' => $input['appointmentId'], 'owner_subject_id' => $subject],
            ], ['appointment_id'], ['appointment_id']);
            $owner = DB::table('appointment_subjects')->where('appointment_id', $input['appointmentId'])->lockForUpdate()->first();
            abort_unless((string) $owner->owner_subject_id === $subject, 409, 'REFERENCE_OWNER_CONFLICT');
            // This lock is shared with archive operations; no network call is made while holding it.
            $client = $scope->clients(Client::query(), $actor)->whereKey($input['clientId'])->lockForUpdate()->first();
            if ($client === null) {
                throw ValidationException::withMessages(['clientId' => 'APPOINTMENT_CLIENT_REQUIRED']);
            }
            if (! $client->vehicles()->whereKey($input['vehicleId'])->exists()) {
                throw ValidationException::withMessages(['vehicleId' => 'APPOINTMENT_VEHICLE_REQUIRED']);
            }
            DB::table('appointment_references')->upsert([
                'appointment_id' => $input['appointmentId'], 'client_id' => $client->id,
                'vehicle_id' => $input['vehicleId'], 'owner_subject_id' => $subject, 'recorded_at' => now(),
            ], ['appointment_id', 'client_id', 'vehicle_id'], ['appointment_id']);
        });

        return response()->json(['data' => ['schemaVersion' => 1, ...$input, 'ownerSubjectId' => $subject, 'retained' => true]])->header('Cache-Control', 'no-store');
    }

    public function search(SearchClientVehicleReferencesRequest $request, CompanyRecordScope $scope): JsonResponse
    {
        $input = $request->validated();
        /** @var CompanyActor $actor */
        $actor = $request->user();
        $subject = $actor->subject;
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $input['search']).'%';
        $clients = $scope->clients(Client::withTrashed(), $actor)->where(function (Builder $query) use ($pattern): void {
            $query->whereRaw("first_name LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("last_name LIKE ? ESCAPE '!'", [$pattern]);
        })->limit(1001)->pluck('id');
        $vehicles = Vehicle::withTrashed()->whereHas('client', fn (Builder $query): Builder => $scope->clients($query->withTrashed(), $actor))
            ->where(function (Builder $query) use ($pattern): void {
                foreach (['make', 'model', 'license_plate'] as $column) {
                    $query->orWhereRaw($query->getGrammar()->wrap($column)." LIKE ? ESCAPE '!'", [$pattern]);
                }
            })->limit(1001)->pluck('id');
        abort_if($clients->count() > 1000 || $vehicles->count() > 1000, 422, 'SEARCH_TOO_BROAD');

        return response()->json(['data' => ['schemaVersion' => 1, 'ownerSubjectId' => $subject, 'clientIds' => $clients, 'vehicleIds' => $vehicles]])->header('Cache-Control', 'no-store');
    }
}
