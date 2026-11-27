<?php

namespace App\Http\Requests;

use App\Support\HybridDocument;
use Illuminate\Foundation\Http\FormRequest;

class SaveVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $rules = self::vehicleRules() + ['clientId' => ['required', 'uuid']];
        if ($this->isMethod('PATCH')) {
            foreach (['make', 'model', 'year', 'vin', 'licensePlate', 'clientId'] as $field) {
                $rules[$field] = array_values(array_diff($rules[$field], ['present']));
                array_unshift($rules[$field], 'sometimes');
            }
        }

        return $rules;
    }

    public static function vehicleRules(): array
    {
        return [
            'make' => ['required', 'string', 'max:255'],
            'model' => ['required', 'string', 'max:255'],
            'year' => ['required', 'integer', 'between:1886,'.(now()->year + 1)],
            'vin' => ['present', 'nullable', 'string', 'max:255'],
            'licensePlate' => ['present', 'nullable', 'string', 'max:255'],
        ] + HybridDocument::rules(true);
    }
}
