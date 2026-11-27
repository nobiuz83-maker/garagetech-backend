<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class SaveCompanyCommercialSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:0'],
            'tradeName' => ['required', 'string', 'max:150'],
            'legalName' => ['nullable', 'string', 'max:150'],
            'legalForm' => ['required', 'in:sole_proprietorship,corporation'],
            'neq' => ['nullable', 'regex:/^\d{10}$/D'],
            'street' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'province' => ['nullable', 'string', 'max:100'],
            'postalCode' => ['nullable', 'string', 'max:20'],
            'country' => ['required', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:100'],
            'secondaryPhone' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'url:http,https', 'max:255'],
            'currency' => ['required', 'in:CAD'],
            'gstRegistered' => ['required', 'boolean'],
            'gstNumber' => ['nullable', 'regex:/^\d{9}RT\d{4}$/D'],
            'gstRate' => ['required', 'integer', 'min:0', 'max:100000'],
            'qstRegistered' => ['required', 'boolean'],
            'qstNumber' => ['nullable', 'regex:/^\d{10}TQ\d{4}$/D'],
            'qstRate' => ['required', 'integer', 'min:0', 'max:100000'],
            'taxEffectiveDate' => ['nullable', 'date_format:Y-m-d'],
            'invoicePrefix' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9-]*$/D'],
            'defaultPaymentTerms' => ['nullable', 'string', 'max:1000'],
            'paymentDueDays' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'thankYouMessage' => ['nullable', 'string', 'max:500'],
            'warrantyTerms' => ['nullable', 'string', 'max:2000'],
            'defaultLanguage' => ['required', 'in:fr-CA,en-CA'],
            'logo' => ['nullable', 'string', 'max:700000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), array_keys($this->rules())) !== []) {
                $validator->errors()->add('request', 'SETTINGS_UNKNOWN_PROPERTY');
            }
            foreach (['gst', 'qst'] as $tax) {
                if ($this->boolean($tax.'Registered') && ! filled($this->input($tax.'Number'))) {
                    $validator->errors()->add($tax.'Number', strtoupper($tax).'_NUMBER_REQUIRED');
                }
            }
            $logo = $this->input('logo');
            if ($logo !== null && $logo !== '' && ! $this->validLogo($logo)) {
                $validator->errors()->add('logo', 'LOGO_INVALID');
            }
        }];
    }

    private function validLogo(string $logo): bool
    {
        if (! preg_match('/^data:image\/(png|jpeg|webp);base64,([A-Za-z0-9+\/=]+)$/D', $logo, $matches)) {
            return false;
        }
        $bytes = base64_decode($matches[2], true);
        if ($bytes === false || strlen($bytes) > 500000 || strlen($bytes) === 0) {
            return false;
        }
        $image = @getimagesizefromstring($bytes);

        return $image !== false && ($image['mime'] ?? '') === 'image/'.$matches[1]
            && $image[0] <= 4000 && $image[1] <= 4000;
    }
}
