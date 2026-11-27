<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class AdjustInventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id' => ['required', 'uuid'],
            'quantityDeltaUnits' => ['required', 'integer', 'min:-9007199254740991', 'max:9007199254740991', 'not_in:0'],
            'reason' => ['required', 'string', 'max:1000'],
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
