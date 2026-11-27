<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AppointmentAvailabilityRequest;
use App\Services\AppointmentAvailability;
use Illuminate\Http\JsonResponse;

class AppointmentAvailabilityController extends Controller
{
    public function __invoke(AppointmentAvailabilityRequest $request, AppointmentAvailability $availability): JsonResponse
    {
        $input = $request->validated();

        return response()->json(['data' => $availability->forRange(
            $request->user(),
            $input['from'],
            $input['to'],
            (int) $input['durationMinutes'],
            $input['excludeId'] ?? null
        )]);
    }
}
