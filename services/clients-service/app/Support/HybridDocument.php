<?php

namespace App\Support;

class HybridDocument
{
    /** @param array<string, mixed>|null $current
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    public static function merge(?array $current, array $changes): array
    {
        return array_replace_recursive($current ?? ['schemaVersion' => 1], $changes);
    }

    /** @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    public static function forApi(array $document): array
    {
        foreach (['preferences', 'additionalInformation', 'engine', 'transmission', 'tires', 'locksmith', 'legacyProperties'] as $section) {
            if (isset($document[$section]) && $document[$section] === []) {
                $document[$section] = (object) [];
            }
        }

        return $document;
    }

    /** @return array<string, array<int, string>> */
    public static function rules(bool $vehicle): array
    {
        $sections = $vehicle
            ? ['engine' => ['displacement' => 'numeric|min:0', 'fuelType' => 'string|max:100', 'engineCode' => 'string|max:100'],
                'transmission' => ['type' => 'string|max:100', 'gears' => 'integer|between:1,30'],
                'tires' => ['front' => 'string|max:100', 'rear' => 'string|max:100'],
                'locksmith' => ['keyType' => 'string|max:100', 'immobilizerGeneration' => 'string|max:100', 'remoteFrequencyMHz' => 'numeric|min:0']]
            : ['preferences' => ['language' => 'in:fr,en', 'preferredContactMethod' => 'in:sms,email,phone', 'appointmentNotifications' => 'in:none,email,sms,both'],
                'additionalInformation' => ['notes' => 'string|max:10000']];
        $rules = ['data' => ['sometimes', 'array:schemaVersion,'.implode(',', array_keys($sections)), 'required_array_keys:schemaVersion'],
            'data.schemaVersion' => ['sometimes', 'integer', 'in:1']];
        foreach ($sections as $section => $fields) {
            $rules['data.'.$section] = ['sometimes', 'array:'.implode(',', array_keys($fields))];
            foreach ($fields as $field => $validation) {
                $rules['data.'.$section.'.'.$field] = ['sometimes', ...(str_starts_with($validation, 'string') ? ['nullable'] : []), ...explode('|', $validation)];
            }
        }

        return $rules;
    }
}
