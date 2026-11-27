<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class RecordPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id' => ['required', 'uuid'], 'amountCents' => ['required', 'integer', 'min:1', 'max:9007199254740991'],
            'method' => ['required', Rule::in(['cash', 'debit', 'credit', 'interac', 'bank', 'cheque', 'other'])],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), array_keys($this->rules())) !== []) {
                $validator->errors()->add('request', 'PAYMENT_UNKNOWN_PROPERTY');
            }
        }];
    }
}
