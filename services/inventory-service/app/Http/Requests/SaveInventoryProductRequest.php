<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class SaveInventoryProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id' => ['required', 'uuid'],
            'sku' => ['required', 'string', 'max:100'],
            'nameFr' => ['required', 'string', 'max:255'],
            'nameEn' => ['present', 'nullable', 'string', 'max:255'],
            'purchasePriceCents' => ['required', 'integer', 'min:0', 'max:9007199254740991'],
            'salePriceCents' => ['required', 'integer', 'min:0', 'max:9007199254740991'],
            'version' => [$this->isMethod('PUT') ? 'required' : 'prohibited', 'integer', 'min:1'],
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
