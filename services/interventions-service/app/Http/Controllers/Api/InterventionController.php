<?php

namespace App\Http\Controllers\Api;

use App\Auth\CompanyActor;
use App\Auth\SecurityFailure;
use App\Http\Requests\CreateInterventionRequest;
use App\Http\Resources\InterventionResource;
use App\Models\Intervention;
use App\Policies\CompanyResourceAccess;
use App\Services\IdempotentInterventionOperation;
use App\Services\VerifyInterventionAppointment;
use App\Services\VerifyInterventionClientVehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

final class InterventionController
{
    public function store(CreateInterventionRequest $request, VerifyInterventionClientVehicle $references, VerifyInterventionAppointment $appointments, IdempotentInterventionOperation $idempotency): JsonResponse
    {
        /** @var CompanyActor $actor */
        $actor = $request->user();
        $input = $request->validated();
        $references->verify($request, $actor, $input['clientId'], $input['vehicleId']);
        $appointments->verify($request, $actor, $input['appointmentId'] ?? null, $input['clientId'], $input['vehicleId']);
        $result = $idempotency->execute($actor, 'intervention.create', $request->header('Idempotency-Key'), $request->getContent(), function () use ($actor, $input, $request): array {
            DB::table('intervention_client_vehicle_references')->upsert([[
                'company_id' => $actor->companyId, 'client_id' => $input['clientId'], 'vehicle_id' => $input['vehicleId'],
            ]], ['company_id', 'client_id', 'vehicle_id'], ['vehicle_id']);
            if (! empty($input['appointmentId'])) {
                DB::table('intervention_appointment_references')->upsert([[
                    'company_id' => $actor->companyId, 'client_id' => $input['clientId'],
                    'vehicle_id' => $input['vehicleId'], 'appointment_id' => $input['appointmentId'],
                ]], ['company_id', 'client_id', 'vehicle_id', 'appointment_id'], ['appointment_id']);
            }
            $record = Intervention::create([
                'id' => $input['id'], 'company_id' => $actor->companyId,
                'client_id' => $input['clientId'], 'vehicle_id' => $input['vehicleId'],
                'appointment_id' => $input['appointmentId'] ?? null,
                'start_date' => $input['startDate'], 'status' => 'planned', 'description' => $input['description'],
                'diagnosis' => $input['diagnosis'] ?? '', 'notes' => $input['notes'] ?? '',
                'address' => $input['address'] ?? '', 'address_details' => $input['addressDetails'] ?? null,
                'labor_rate_cents' => $input['laborRateCents'], 'labor_hours' => $input['laborHours'],
                'data' => ['schemaVersion' => 1],
            ]);

            return (new InterventionResource($record->refresh()))->resolve($request);
        });

        return response()->json(['data' => $result], 201);
    }

    public function index(Request $request, CompanyResourceAccess $access): AnonymousResourceCollection
    {
        /** @var CompanyActor $actor */
        $actor = $request->user();
        $search = $request->validate(['search' => ['sometimes', 'string', 'max:100']])['search'] ?? '';
        $query = $access->scope($actor, Intervention::query());
        if ($search !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            $query->where(function ($query) use ($pattern): void {
                $query->whereRaw("description LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("diagnosis LIKE ? ESCAPE '!'", [$pattern]);
            });
        }

        return InterventionResource::collection($query->orderByDesc('start_date')->orderBy('id')->paginate(50));
    }

    public function update(CreateInterventionRequest $request, string $intervention, VerifyInterventionClientVehicle $references, VerifyInterventionAppointment $appointments, IdempotentInterventionOperation $idempotency): JsonResponse
    {
        /** @var CompanyActor $actor */
        $actor = $request->user();
        $input = $request->validated();
        abort_unless($input['id'] === $intervention, 422, 'INTERVENTION_ID_MISMATCH');
        $references->verify($request, $actor, $input['clientId'], $input['vehicleId']);
        $appointments->verify($request, $actor, $input['appointmentId'] ?? null, $input['clientId'], $input['vehicleId']);
        $result = $idempotency->execute($actor, 'intervention.update.'.$intervention, $request->header('Idempotency-Key'), $request->getContent(), function () use ($actor, $input, $intervention, $request): array {
            $record = Intervention::where('company_id', $actor->companyId)->whereKey($intervention)->lockForUpdate()->first();
            if ($record === null) {
                throw new SecurityFailure('RESOURCE_NOT_FOUND', 404);
            }
            if ($record->version !== $input['version']) {
                throw new SecurityFailure('VERSION_CONFLICT', 409);
            }
            if ($record->status !== 'planned' || $record->client_id !== $input['clientId'] || $record->vehicle_id !== $input['vehicleId']) {
                throw new SecurityFailure('INTERVENTION_UPDATE_NOT_ALLOWED', 409);
            }
            if (! empty($input['appointmentId'])) {
                DB::table('intervention_appointment_references')->upsert([[
                    'company_id' => $actor->companyId, 'client_id' => $input['clientId'],
                    'vehicle_id' => $input['vehicleId'], 'appointment_id' => $input['appointmentId'],
                ]], ['company_id', 'client_id', 'vehicle_id', 'appointment_id'], ['appointment_id']);
            }
            $record->fill([
                'appointment_id' => array_key_exists('appointmentId', $input) ? $input['appointmentId'] : $record->appointment_id,
                'start_date' => $input['startDate'], 'description' => $input['description'],
                'diagnosis' => $input['diagnosis'] ?? '', 'notes' => $input['notes'] ?? '',
                'address' => array_key_exists('address', $input) ? ($input['address'] ?? '') : $record->address,
                'address_details' => array_key_exists('addressDetails', $input) ? $input['addressDetails'] : $record->address_details,
                'labor_rate_cents' => $input['laborRateCents'], 'labor_hours' => $input['laborHours'],
                'version' => $record->version + 1,
            ])->save();

            return (new InterventionResource($record->refresh()))->resolve($request);
        });

        return response()->json(['data' => $result]);
    }

    public function show(Request $request, string $intervention, CompanyResourceAccess $access): JsonResponse
    {
        /** @var CompanyActor $actor */
        $actor = $request->user();
        $record = $access->scope($actor, Intervention::query())->findOrFail($intervention);
        $access->authorize($actor, $record->company_id, 'interventions.read');

        return response()->json(['data' => (new InterventionResource($record))->resolve($request)]);
    }
}
