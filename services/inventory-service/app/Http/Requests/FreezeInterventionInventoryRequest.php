<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class FreezeInterventionInventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['invoiceId' => ['required', 'uuid'], 'laborSubtotalCents' => ['required', 'integer', 'min:0', 'max:10000000000'],
            'expectedLinesHash' => ['sometimes', 'nullable', 'string', 'regex:/^[a-f0-9]{64}$/D']];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), ['invoiceId', 'laborSubtotalCents', 'expectedLinesHash']) !== []) {
                $validator->errors()->add('request', 'INVENTORY_UNKNOWN_PROPERTY');
            }
        }];
    }
}
