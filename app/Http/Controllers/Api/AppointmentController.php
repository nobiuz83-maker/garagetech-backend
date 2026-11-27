<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ImportAppointmentsRequest;
use App\Http\Requests\ListAppointmentsRequest;
use App\Http\Requests\SaveAppointmentRequest;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Services\AppointmentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class AppointmentController extends Controller
{
    public function index(ListAppointmentsRequest $request): AnonymousResourceCollection
    {
        $query = Appointment::where('user_id', $request->user()->id);
        foreach (['date' => 'date', 'status' => 'status', 'clientId' => 'client_id', 'vehicleId' => 'vehicle_id'] as $property => $column) {
            if ($request->filled($property)) {
                $query->where($column, $request->validated($property));
            }
        }
        foreach (['from' => '>=', 'to' => '<='] as $property => $operator) {
            if ($request->filled($property)) {
                $query->where('date', $operator, $request->validated($property));
            }
        }
        if ($request->filled('search')) {
            $term = '%'.addcslashes($request->validated('search'), '%_\\').'%';
            $query->where(function (Builder $matches) use ($term): void {
                foreach (['description', 'notes', 'service_type'] as $column) {
                    $matches->orWhere($column, 'like', $term);
                }
                $matches->orWhere('data', 'like', $term)
                    ->orWhereHas('client', fn (Builder $clients) => $clients->where('first_name', 'like', $term)->orWhere('last_name', 'like', $term))
                    ->orWhereHas('vehicle', fn (Builder $vehicles) => $vehicles->where('make', 'like', $term)->orWhere('model', 'like', $term)->orWhere('license_plate', 'like', $term));
            });
        }

        return AppointmentResource::collection($query->orderBy('date')->orderBy('start_time')->orderBy('id')->paginate($request->integer('perPage', 100)));
    }

    public function show(Appointment $appointment): AppointmentResource
    {
        Gate::authorize('view', $appointment);

        return new AppointmentResource($appointment);
    }

    public function store(SaveAppointmentRequest $request, AppointmentService $service): AppointmentResource
    {
        return new AppointmentResource($service->save($request->user(), $request->validated()));
    }

    public function update(SaveAppointmentRequest $request, Appointment $appointment, AppointmentService $service): AppointmentResource
    {
        Gate::authorize('update', $appointment);

        return new AppointmentResource($service->save($request->user(), $request->validated(), $appointment));
    }

    public function cancel(Request $request, Appointment $appointment, AppointmentService $service): AppointmentResource
    {
        Gate::authorize('update', $appointment);
        $input = $request->validate(['version' => ['required', 'integer', 'min:1']]);

        return new AppointmentResource($service->cancel($request->user(), $appointment, $input['version']));
    }

    public function destroy(Request $request, Appointment $appointment, AppointmentService $service): Response
    {
        Gate::authorize('delete', $appointment);
        $input = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $service->archive($request->user(), $appointment, $input['version']);

        return response()->noContent();
    }

    public function import(ImportAppointmentsRequest $request, AppointmentService $service): JsonResponse
    {
        return response()->json(['data' => $service->import($request->user(), $request->validated('appointments'))]);
    }
}
