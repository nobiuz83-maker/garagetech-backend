<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AppointmentAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'durationMinutes' => ['required', 'integer', 'between:1,1439'],
            'excludeId' => ['sometimes', 'nullable', 'uuid'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) return;
            $from = \Carbon\CarbonImmutable::createFromFormat('!Y-m-d', $this->string('from')->toString());
            $to = \Carbon\CarbonImmutable::createFromFormat('!Y-m-d', $this->string('to')->toString());
            if ($from === false || $to === false || $from->diffInDays($to) > 30) {
                $validator->errors()->add('to', 'APPOINTMENT_AVAILABILITY_RANGE_INVALID');
            }
        }];
    }
}
