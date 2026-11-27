<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Validator;

class ImportAppointmentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $original = json_decode($this->getContent(), true);
        $records = $original['appointments'] ?? null;
        if (! is_array($records)) {
            return;
        }
        $known = [...array_keys(SaveAppointmentRequest::appointmentRules()), 'id', 'createdAt', 'updatedAt', 'version'];
        foreach ($records as &$record) {
            if (! is_array($record)) {
                continue;
            }
            $unknown = array_diff_key($record, array_flip($known));
            foreach (SaveAppointmentRequest::appointmentRules() as $path => $rules) {
                foreach ($rules as $rule) {
                    if (! is_string($rule) || ! str_starts_with($rule, 'array:')) {
                        continue;
                    }
                    $value = Arr::get($record, $path);
                    if (! is_array($value)) {
                        continue;
                    }
                    $allowed = explode(',', substr($rule, 6));
                    if ($path === 'data') {
                        $allowed[] = 'legacyProperties';
                    }
                    $extra = array_diff_key($value, array_flip($allowed));
                    if ($extra !== []) {
                        Arr::set($unknown, $path, $extra);
                    }
                    Arr::set($record, $path, Arr::only($value, $allowed));
                }
            }
            $record = Arr::only($record, $known);
            if ($unknown !== [] && (! isset($record['data']) || is_array($record['data']))) {
                $record['data'] ??= ['schemaVersion' => 1];
                if (! isset($record['data']['legacyProperties']) || is_array($record['data']['legacyProperties'])) {
                    $record['data']['legacyProperties'] = array_replace_recursive($record['data']['legacyProperties'] ?? [], $unknown);
                }
            }
        }
        unset($record);
        $this->merge(['appointments' => $records]);
    }

    public function rules(): array
    {
        $rules = [
            'appointments' => ['present', 'array', 'max:1000'],
            'appointments.*.id' => ['required', 'uuid', 'distinct'],
            'appointments.*.createdAt' => ['required', 'date'],
            'appointments.*.updatedAt' => ['required', 'date', 'after_or_equal:appointments.*.createdAt'],
        ];
        foreach (SaveAppointmentRequest::appointmentRules() as $property => $validation) {
            $rules['appointments.*.'.$property] = array_map(
                fn ($rule) => $rule === 'after:startTime' ? 'after:appointments.*.startTime' : $rule,
                $validation,
            );
        }
        $rules['appointments.*.data'] = ['sometimes', 'array:schemaVersion,legacyProperties', 'required_array_keys:schemaVersion'];
        $rules['appointments.*.data.legacyProperties'] = ['sometimes', 'array'];

        return $rules;
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            foreach ($this->input('appointments', []) as $index => $record) {
                if (strlen(json_encode($record['data']['legacyProperties'] ?? [])) > 65536) {
                    $validator->errors()->add('appointments.'.$index.'.data', 'LEGACY_DOCUMENT_TOO_LARGE');
                }
            }
        }];
    }
}
