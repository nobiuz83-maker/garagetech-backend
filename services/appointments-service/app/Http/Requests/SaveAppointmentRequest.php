<?php

namespace App\Http\Requests;

use App\Models\Appointment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public static function appointmentRules(): array
    {
        return [
            'clientId' => ['required', 'uuid'],
            'vehicleId' => ['required', 'uuid'],
            'date' => ['required', 'date_format:Y-m-d'],
            'startTime' => ['required', 'date_format:H:i'],
            'endTime' => ['required', 'date_format:H:i', 'after:startTime'],
            'status' => ['required', Rule::in(Appointment::STATUSES)],
            'serviceType' => ['required', 'string', 'max:255'],
            'serviceId' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:10000'],
            'notes' => ['present', 'nullable', 'string', 'max:10000'],
            'address' => ['present', 'nullable', 'string', 'max:2000'],
            'addressDetails' => ['sometimes', 'nullable', 'array:formatted,houseNumber,street,city,province,postalCode,country,countryCode,provinceCode,latitude,longitude,source,manualConfirmed', 'required_array_keys:formatted,houseNumber,street,city,province,postalCode,country,countryCode,provinceCode'],
            ...collect(['formatted', 'houseNumber', 'street', 'city', 'province', 'postalCode', 'country', 'countryCode', 'provinceCode'])
                ->mapWithKeys(fn (string $property): array => ['addressDetails.'.$property => ['sometimes', 'nullable', 'string', 'max:2000']])->all(),
            'addressDetails.latitude' => ['sometimes', 'numeric', 'between:-90,90'],
            'addressDetails.longitude' => ['sometimes', 'numeric', 'between:-180,180'],
            'addressDetails.source' => ['sometimes', Rule::in(['manual', 'geoapify'])],
            'addressDetails.manualConfirmed' => ['sometimes', 'boolean'],
            'data' => ['sometimes', 'array:schemaVersion', 'required_array_keys:schemaVersion'],
            'data.schemaVersion' => ['sometimes', 'integer', 'in:1'],
            'notificationDetails' => ['sometimes', 'array:enabled,confirmation,reminder,reminderHours,onlineCancellation,channels,email,phone,clientName,appointmentDate,appointmentStart,vehicle,serviceName,serviceAddress,companyName,companyLogo,companyEmail,companyPhone,senderName,garageEmail,language'],
            'notificationDetails.enabled' => ['required_with:notificationDetails', 'boolean'],
            'notificationDetails.confirmation' => ['required_with:notificationDetails', 'boolean'],
            'notificationDetails.reminder' => ['required_with:notificationDetails', 'boolean'],
            'notificationDetails.reminderHours' => ['sometimes', 'integer', 'between:1,168'],
            'notificationDetails.onlineCancellation' => ['sometimes', 'boolean'],
            'notificationDetails.channels' => ['sometimes', 'array', 'max:2'],
            'notificationDetails.channels.*' => ['string', Rule::in(['email', 'sms'])],
            'notificationDetails.email' => ['nullable', 'email', 'max:255'],
            'notificationDetails.phone' => ['nullable', 'string', 'max:32'],
            'notificationDetails.clientName' => ['nullable', 'string', 'max:255'],
            'notificationDetails.appointmentDate' => ['nullable', 'date_format:Y-m-d'],
            'notificationDetails.appointmentStart' => ['nullable', 'date_format:H:i'],
            'notificationDetails.vehicle' => ['nullable', 'string', 'max:255'],
            'notificationDetails.serviceName' => ['nullable', 'string', 'max:255'],
            'notificationDetails.serviceAddress' => ['nullable', 'string', 'max:2000'],
            'notificationDetails.companyName' => ['nullable', 'string', 'max:255'],
            'notificationDetails.companyLogo' => ['nullable', 'string', 'max:700000'],
            'notificationDetails.companyEmail' => ['nullable', 'email', 'max:255'],
            'notificationDetails.companyPhone' => ['nullable', 'string', 'max:32'],
            'notificationDetails.senderName' => ['nullable', 'string', 'max:255'],
            'notificationDetails.garageEmail' => ['nullable', 'email', 'max:255'],
            'notificationDetails.language' => ['sometimes', 'in:fr,en'],
        ];
    }

    public function rules(): array
    {
        return self::appointmentRules() + ['version' => [$this->route('appointment') ? 'required' : 'prohibited', 'integer', 'min:1']];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $address = $this->input('addressDetails');
            if (is_array($address)) {
                $hasAddress = collect(['formatted', 'houseNumber', 'street', 'city', 'province', 'postalCode', 'country'])
                    ->contains(fn (string $property): bool => ! empty($address[$property]));
                $incomplete = collect(['houseNumber', 'street', 'city', 'country'])
                    ->contains(fn (string $property): bool => empty($address[$property]));
                if ($hasAddress && empty($address['manualConfirmed']) && (($address['source'] ?? '') === 'manual'
                    || (($address['source'] ?? '') === 'geoapify' && $incomplete))) {
                    $validator->errors()->add('addressDetails', 'APPOINTMENT_ADDRESS_CONFIRMATION');
                }
                $isCanada = strtoupper($address['countryCode'] ?? '') === 'CA'
                    || (empty($address['countryCode']) && strtolower($address['country'] ?? '') === 'canada');
                if ($isCanada && ! empty($address['postalCode'])
                    && ! preg_match('/^[ABCEGHJ-NPRSTVXY]\d[ABCEGHJ-NPRSTV-Z] ?\d[ABCEGHJ-NPRSTV-Z]\d$/i', $address['postalCode'])) {
                    $validator->errors()->add('addressDetails.postalCode', 'APPOINTMENT_POSTAL_INVALID');
                }
            }
            $notificationDetails = $this->input('notificationDetails');
            if (is_array($notificationDetails) && ! empty($notificationDetails['enabled'])) {
                $channels = $notificationDetails['channels'] ?? [];
                if (in_array('email', $channels, true) && empty($notificationDetails['email'])) {
                    $validator->errors()->add('notificationDetails.email', 'APPOINTMENT_NOTIFICATION_EMAIL_REQUIRED');
                }
                if (in_array('sms', $channels, true) && ! preg_match('/^\+[1-9]\d{7,14}$/', preg_replace('/[\s().-]/', '', (string) ($notificationDetails['phone'] ?? '')))) {
                    $validator->errors()->add('notificationDetails.phone', 'APPOINTMENT_NOTIFICATION_PHONE_INVALID');
                }
            }
            $allowed = [...array_keys(self::appointmentRules()), 'version', 'notificationDetails'];
            foreach (array_keys($this->all()) as $property) {
                if (! in_array($property, $allowed, true)) {
                    $validator->errors()->add($property, 'UNKNOWN_PROPERTY');
                }
            }
        }];
    }
}
