<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveAppointmentScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'schemaVersion' => ['required', 'integer', 'in:1'],
            'schedule' => ['required', 'array:workDays,openingTime,closingTime,defaultDurationMinutes,intervalMinutes,breakStart,breakEnd,preventOverlaps,bookingLeadMinutes'],
            'schedule.workDays' => ['present', 'array', 'max:7'],
            'schedule.workDays.*' => ['integer', 'between:0,6', 'distinct'],
            'schedule.openingTime' => ['required', 'date_format:H:i'],
            'schedule.closingTime' => ['required', 'date_format:H:i', 'after:schedule.openingTime'],
            'schedule.defaultDurationMinutes' => ['required', 'integer', 'between:1,1439'],
            'schedule.intervalMinutes' => ['required', 'integer', 'between:0,1439'],
            'schedule.preventOverlaps' => ['required', 'boolean'],
            'schedule.breakStart' => ['present', 'nullable', 'date_format:H:i', 'required_with:schedule.breakEnd'],
            'schedule.breakEnd' => ['present', 'nullable', 'date_format:H:i', 'required_with:schedule.breakStart', 'after:schedule.breakStart'],
            'schedule.bookingLeadMinutes' => ['sometimes', 'integer', 'between:0,525600'],
        ];
    }
}
