<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;

class ImportClientsRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $originalInput = json_decode($this->getContent(), true);
        foreach (['clients', 'vehicles'] as $collection) {
            $records = $this->input($collection);
            if (! is_array($records)) {
                continue;
            }
            $isClient = $collection === 'clients';
            $knownFields = $isClient
                ? ['id', 'createdAt', 'firstName', 'lastName', 'phone', 'email', 'address', 'data']
                : ['id', 'createdAt', 'clientId', 'make', 'model', 'year', 'vin', 'licensePlate', 'data'];
            $fieldRules = $isClient ? SaveClientRequest::clientRules() : SaveVehicleRequest::vehicleRules();
            foreach ($records as $index => $record) {
                if (! is_array($record)) {
                    continue;
                }
                $originalRecord = $originalInput[$collection][$index] ?? $record;
                $records[$index] = $this->archiveHistoricalProperties($record, $originalRecord, $knownFields, $fieldRules);
            }
            $this->merge([$collection => $records]);
        }
    }

    /** @param array<string, mixed> $record
     * @param  array<string, mixed>  $originalRecord
     * @param  array<int, string>  $knownFields
     * @param  array<string, array<int, string>>  $fieldRules
     * @return array<string, mixed>
     */
    private function archiveHistoricalProperties(array $record, array $originalRecord, array $knownFields, array $fieldRules): array
    {
        $historicalProperties = array_diff_key($originalRecord, array_flip($knownFields));
        $originalArchive = Arr::get($originalRecord, 'data.legacyProperties');
        if (is_array($originalArchive)) {
            Arr::set($record, 'data.legacyProperties', $originalArchive);
        }
        foreach ($fieldRules as $path => $rules) {
            foreach ($rules as $rule) {
                if (! str_starts_with($rule, 'array:')) {
                    continue;
                }
                $originalValue = Arr::get($originalRecord, $path);
                $currentValue = Arr::get($record, $path);
                if (! is_array($originalValue) || ! is_array($currentValue)) {
                    continue;
                }
                $allowedFields = explode(',', substr($rule, strlen('array:')));
                if ($path === 'data') {
                    $allowedFields[] = 'legacyProperties';
                }
                $unknownFields = array_diff_key($originalValue, array_flip($allowedFields));
                if ($unknownFields !== []) {
                    Arr::set($historicalProperties, $path, $unknownFields);
                }
                Arr::set($record, $path, Arr::only($currentValue, $allowedFields));
            }
        }
        if ($historicalProperties !== [] && (! isset($record['data']) || is_array($record['data']))) {
            $document = $record['data'] ?? ['schemaVersion' => 1];
            if (! isset($document['legacyProperties']) || is_array($document['legacyProperties'])) {
                $document['legacyProperties'] = array_replace_recursive($document['legacyProperties'] ?? [], $historicalProperties);
            }
            $record['data'] = $document;
        }

        return $record;
    }

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $rules = [
            'clients' => ['present', 'array', 'max:1000'],
            'vehicles' => ['present', 'array', 'max:5000'],
            'clients.*.id' => ['required', 'uuid', 'distinct'],
            'clients.*.createdAt' => ['required', 'date'],
            'vehicles.*.id' => ['required', 'uuid', 'distinct'],
            'vehicles.*.clientId' => ['required', 'uuid'],
            'vehicles.*.createdAt' => ['required', 'date'],
        ];
        foreach (SaveClientRequest::clientRules() as $field => $fieldRules) {
            $fieldRules = array_values(array_diff($fieldRules, ['email:rfc']));
            $rules['clients.*.'.$field] = array_map(
                fn (string $rule): string => str_replace(['present_with:address', 'exclude_if:address,'], ['present_with:clients.*.address', 'exclude_if:clients.*.address,'], $rule),
                $fieldRules,
            );
        }
        foreach (SaveVehicleRequest::vehicleRules() as $field => $fieldRules) {
            $rules['vehicles.*.'.$field] = $fieldRules;
        }

        foreach (['clients', 'vehicles'] as $collection) {
            $rules[$collection.'.*.data'][1] .= ',legacyProperties';
            $rules[$collection.'.*.data.legacyProperties'] = ['sometimes', 'array', function (string $attribute, mixed $value, \Closure $fail): void {
                if (strlen(json_encode($value, JSON_THROW_ON_ERROR)) > 65536) {
                    $fail('Legacy properties must not exceed 64 KiB.');
                }
            }];
        }

        return $rules;
    }
}
