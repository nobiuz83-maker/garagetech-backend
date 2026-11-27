<?php

namespace App\Http\Requests;

use App\Services\CompanyCommercialSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class IssueInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id' => ['required', 'uuid'], 'interventionId' => ['required', 'uuid'],
            'previewHash' => ['sometimes', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'gstRate' => ['required', 'integer', 'min:0', 'max:100000'],
            'qstRate' => ['required', 'integer', 'min:0', 'max:100000'],
            'sellerName' => ['required', 'string', 'max:255'],
            'customerName' => ['required', 'string', 'max:255'],
            'vehicleDescription' => ['required', 'string', 'max:255'],
            'details' => ['required', 'array:sellerLegalName,sellerAddress,sellerPhone,sellerEmail,gstNumber,qstNumber,customerAddress,customerPhone,customerEmail,vehicleVin,vehiclePlate,paymentTerms'],
            'details.sellerLegalName' => ['present', 'nullable', 'string', 'max:255'],
            'details.sellerAddress' => ['present', 'nullable', 'string', 'max:1000'],
            'details.sellerPhone' => ['present', 'nullable', 'string', 'max:100'],
            'details.sellerEmail' => ['present', 'nullable', 'string', 'max:255'],
            'details.gstNumber' => ['present', 'nullable', 'string', 'max:100'],
            'details.qstNumber' => ['present', 'nullable', 'string', 'max:100'],
            'details.customerAddress' => ['present', 'nullable', 'string', 'max:1000'],
            'details.customerPhone' => ['present', 'nullable', 'string', 'max:100'],
            'details.customerEmail' => ['present', 'nullable', 'string', 'max:255'],
            'details.vehicleVin' => ['present', 'nullable', 'string', 'max:100'],
            'details.vehiclePlate' => ['present', 'nullable', 'string', 'max:100'],
            'details.paymentTerms' => ['present', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), array_keys($this->rules())) !== []) {
                $validator->errors()->add('request', 'INVOICE_UNKNOWN_PROPERTY');
            }
            $hasServerSettings = app(CompanyCommercialSettings::class)->read($this->user()->companyId)['version'] > 0;
            if (! $hasServerSettings && (int) $this->input('gstRate') > 0 && ! filled($this->input('details.gstNumber'))) {
                $validator->errors()->add('details.gstNumber', 'GST_NUMBER_REQUIRED');
            }
            if (! $hasServerSettings && (int) $this->input('qstRate') > 0 && ! filled($this->input('details.qstNumber'))) {
                $validator->errors()->add('details.qstNumber', 'QST_NUMBER_REQUIRED');
            }
        }];
    }
}
