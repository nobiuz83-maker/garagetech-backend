<?php

namespace App\Http\Requests;

use Illuminate\Validation\Validator;

final class PreviewInvoiceRequest extends IssueInvoiceRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['id']);
        $rules['details.paymentTerms'] = ['present', 'nullable', 'string', 'max:1000'];

        return $rules;
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), array_keys($this->rules())) !== []) {
                $validator->errors()->add('request', 'INVOICE_UNKNOWN_PROPERTY');
            }
        }];
    }
}
