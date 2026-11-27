<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveAppointmentScheduleRequest;
use App\Models\AppointmentSchedule;
use App\Models\OwnerSubject;
use App\Services\CompanyRecordScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AppointmentScheduleController extends Controller
{
    public function show(Request $request, CompanyRecordScope $scope): JsonResponse
    {
        return response()->json(['data' => $scope->schedules(AppointmentSchedule::query(), $request->user())
            ->where('company_id', $request->user()->companyId)->first()?->data
            ?? $scope->schedules(AppointmentSchedule::query(), $request->user())->whereNull('company_id')->first()?->data]);
    }

    public function update(SaveAppointmentScheduleRequest $request): JsonResponse
    {
        $data = DB::transaction(function () use ($request): array {
            OwnerSubject::ensureExists($request->user()->id);
            OwnerSubject::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $schedule = AppointmentSchedule::firstOrNew(['user_id' => $request->user()->id, 'company_id' => $request->user()->companyId]);
            $schedule->user_id = $request->user()->id;
            $schedule->company_id = $request->user()->companyId;
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
