<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class CreateInterventionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id' => ['required', 'uuid'],
            'clientId' => ['required', 'uuid'],
            'vehicleId' => ['required', 'uuid'],
            'appointmentId' => ['sometimes', 'nullable', 'uuid'],
            'startDate' => ['required', 'date_format:Y-m-d'],
            'address' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'addressDetails' => ['sometimes', 'nullable', 'array:formatted,houseNumber,street,city,province,postalCode,country,countryCode,provinceCode,latitude,longitude,source,manualConfirmed'],
            'addressDetails.formatted' => ['required_with:addressDetails', 'string', 'max:1000'],
            'addressDetails.houseNumber' => ['required_with:addressDetails', 'string', 'max:50'],
            'addressDetails.street' => ['required_with:addressDetails', 'string', 'max:255'],
            'addressDetails.city' => ['required_with:addressDetails', 'string', 'max:100'],
            'addressDetails.province' => ['required_with:addressDetails', 'string', 'max:100'],
            'addressDetails.postalCode' => ['required_with:addressDetails', 'string', 'max:20'],
            'addressDetails.country' => ['required_with:addressDetails', 'string', 'max:100'],
            'addressDetails.countryCode' => ['required_with:addressDetails', 'string', 'max:2'],
            'addressDetails.provinceCode' => ['required_with:addressDetails', 'string', 'max:10'],
            'addressDetails.latitude' => ['sometimes', 'numeric', 'between:-90,90'],
            'addressDetails.longitude' => ['sometimes', 'numeric', 'between:-180,180'],
            'addressDetails.source' => ['sometimes', 'in:geoapify,manual'],
            'addressDetails.manualConfirmed' => ['sometimes', 'boolean'],
            'description' => ['required', 'string', 'max:10000'],
            'diagnosis' => ['present', 'nullable', 'string', 'max:10000'],
            'notes' => ['present', 'nullable', 'string', 'max:10000'],
            'laborRateCents' => ['required', 'integer', 'min:0', 'max:9007199254740991'],
            'laborHours' => ['required', 'numeric', 'min:0', 'max:99999999.9999', 'decimal:0,4'],
            'version' => [$this->isMethod('PUT') ? 'required' : 'prohibited', 'integer', 'min:1'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), array_keys($this->rules())) !== []) {
                $validator->errors()->add('request', 'INTERVENTION_UNKNOWN_PROPERTY');
            }
        }];
    }
}
