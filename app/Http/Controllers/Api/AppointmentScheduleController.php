<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveAppointmentScheduleRequest;
use App\Models\AppointmentSchedule;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AppointmentScheduleController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => AppointmentSchedule::where('user_id', $request->user()->id)->first()?->data]);
    }

    public function update(SaveAppointmentScheduleRequest $request): JsonResponse
    {
        $data = DB::transaction(function () use ($request): array {
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $schedule = AppointmentSchedule::firstOrNew(['user_id' => $request->user()->id]);
            $schedule->user_id = $request->user()->id;
            $document = $request->validated();
            $document['schedule']['breakStart'] ??= '';
            $document['schedule']['breakEnd'] ??= '';
            $schedule->data = $document;
            $schedule->save();

            return $schedule->data;
        });

        return response()->json(['data' => $data]);
    }
}
