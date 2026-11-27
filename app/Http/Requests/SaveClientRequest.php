<?php

namespace App\Http\Requests;

use App\Support\HybridDocument;
use Illuminate\Foundation\Http\FormRequest;

class SaveClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $rules = self::clientRules() + ['vehicles' => ['required', 'array', 'min:1', 'max:100']];
        foreach (SaveVehicleRequest::vehicleRules() as $field => $fieldRules) {
            $rules['vehicles.*.'.$field] = $fieldRules;
        }
        $rules['vehicles.*.id'] = ['sometimes', 'uuid', 'distinct'];

        if ($this->isMethod('PATCH')) {
            foreach (['firstName', 'lastName', 'phone', 'email', 'vehicles'] as $field) {
                $rules[$field] = array_values(array_diff($rules[$field], ['present']));
                array_unshift($rules[$field], 'sometimes');
            }
        }

        return $rules;
    }

    public static function clientRules(): array
    {
        $rules = [
            'firstName' => ['required', 'string', 'max:255'],
            'lastName' => ['required', 'string', 'max:255'],
            'phone' => ['present', 'nullable', 'string', 'max:255'],
            'email' => ['present', 'nullable', 'string', 'email:rfc', 'max:255'],
            'address' => ['sometimes', 'nullable', 'array:formatted,houseNumber,street,city,province,postalCode,country,countryCode,provinceCode,latitude,longitude,source,manualConfirmed'],
            'address.latitude' => ['sometimes', 'numeric', 'between:-90,90'],
            'address.longitude' => ['sometimes', 'numeric', 'between:-180,180'],
            'address.source' => ['sometimes', 'in:geoapify,manual'],
            'address.manualConfirmed' => ['sometimes', 'boolean'],
        ];
        foreach (['formatted', 'houseNumber', 'street', 'city', 'province', 'postalCode', 'country', 'countryCode', 'provinceCode'] as $field) {
            $rules['address.'.$field] = ['exclude_if:address,null', 'present_with:address', 'nullable', 'string', 'max:1000'];
        }

        return $rules + HybridDocument::rules(false);
    }
}
