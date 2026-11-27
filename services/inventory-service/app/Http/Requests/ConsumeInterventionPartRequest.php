<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class ConsumeInterventionPartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id' => ['required', 'uuid'], 'movementId' => ['required', 'uuid'],
            'productId' => ['required', 'uuid'], 'quantityUnits' => ['required', 'integer', 'min:1', 'max:9007199254740991'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), array_keys($this->rules())) !== []) {
                $validator->errors()->add('request', 'INVENTORY_UNKNOWN_PROPERTY');
            }
        }];
    }
}
