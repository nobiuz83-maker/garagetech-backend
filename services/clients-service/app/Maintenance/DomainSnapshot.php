<?php

namespace App\Maintenance;

use Illuminate\Support\Facades\Validator;
use RuntimeException;
use stdClass;

/** Immutable maintenance protocol; this file is independently shipped by each application. */
class DomainSnapshot
{
    public const TABLES = [
        'clients' => [
            'clients' => ['id', 'user_id', 'first_name', 'last_name', 'phone', 'email', 'data', 'created_at', 'updated_at', 'deleted_at'],
            'vehicles' => ['id', 'client_id', 'make', 'model', 'year', 'vin', 'license_plate', 'data', 'created_at', 'updated_at', 'deleted_at'],
            'appointment_subjects' => ['appointment_id', 'owner_subject_id'],
            'appointment_references' => ['appointment_id', 'client_id', 'vehicle_id', 'owner_subject_id', 'recorded_at'],
        ],
        'appointments' => [
            'client_vehicle_associations' => ['client_id', 'vehicle_id', 'user_id'],
            'appointments' => ['id', 'user_id', 'client_id', 'vehicle_id', 'date', 'start_time', 'end_time', 'status', 'service_type', 'description', 'notes', 'data', 'version', 'created_at', 'updated_at', 'deleted_at'],
            'appointment_schedules' => ['id', 'user_id', 'data', 'created_at', 'updated_at'],
        ],
    ];

    public array $tables;

    public array $owners;

    public string $domain;

    public function __construct(public stdClass $document, public string $sha256)
    {
        self::exactKeys((array) $document, ['schemaVersion', 'migrationId', 'sourceSha256', 'referenceDigest', 'domain', 'ownerSubjects', 'tables']);
        self::require($document->schemaVersion === 1 && $document->domain === config('maintenance_import.domain'));
        $this->domain = $document->domain;
        self::uuid($document->migrationId);
        foreach ([$document->sourceSha256, $document->referenceDigest, $sha256] as $digest) {
            self::require(is_string($digest) && preg_match('/^[a-f0-9]{64}$/', $digest) === 1);
        }
        self::require(is_array($document->ownerSubjects) && count(array_unique($document->ownerSubjects, SORT_REGULAR)) === count($document->ownerSubjects));
        $this->owners = $document->ownerSubjects;
        foreach ($this->owners as $owner) {
            self::subject($owner);
        }
        self::require($document->tables instanceof stdClass);
        self::exactKeys((array) $document->tables, array_keys(self::TABLES[$this->domain]));
        $this->tables = [];
        foreach (self::TABLES[$this->domain] as $table => $columns) {
            $records = $document->tables->$table;
            self::require(is_array($records));
            $identities = [];
            foreach ($records as $record) {
                self::require($record instanceof stdClass);
                $row = (array) $record;
                self::exactKeys($row, $columns);
                $this->validateRow($table, $row);
                $identity = $this->identity($table, $row);
                self::require(! isset($identities[$identity]));
                $identities[$identity] = true;
                $this->tables[$table][] = $row;
            }
            $this->tables[$table] ??= [];
        }
        $this->validateRelationships();
    }

    public static function read(string $path, string $expectedSha256): self
    {
        $bytes = file_get_contents($path);
        self::require(is_string($bytes) && hash_equals($expectedSha256, hash('sha256', $bytes)));
        $tokens = preg_replace('/"(?:[^"\\\\]|\\\\.)*"/s', '""', $bytes);
        preg_match_all('/-?[0-9]+(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/', $tokens, $numbers);
        foreach ($numbers[0] as $number) {
            if (! str_contains($number, '.') && ! str_contains(strtolower($number), 'e')) {
                $absolute = ltrim($number, '-');
                $limit = str_starts_with($number, '-') ? '9223372036854775808' : '9223372036854775807';
                self::require(strlen($absolute) < 19 || (strlen($absolute) === 19 && strcmp($absolute, $limit) <= 0));
            }
        }
        $document = json_decode($bytes, false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        self::require($document instanceof stdClass);

        return new self($document, $expectedSha256);
    }

    public function identity(string $table, array $row): string
    {
        $columns = match ($table) {
            'appointment_references' => ['appointment_id', 'client_id', 'vehicle_id'],
            'appointment_subjects' => ['appointment_id'],
            'client_vehicle_associations' => ['client_id', 'vehicle_id'],
            default => ['id'],
        };

        return implode('|', array_map(fn (string $column): string => (string) $row[$column], $columns));
    }

    public static function canonical(mixed $value): string
    {
        $normalize = function (mixed $item) use (&$normalize): mixed {
            if ($item instanceof stdClass) {
                $properties = (array) $item;
                ksort($properties);

                return (object) array_map($normalize, $properties);
            }
            if (is_array($item)) {
                if (! array_is_list($item)) {
                    ksort($item);
                }

                return array_map($normalize, $item);
            }

            return $item;
        };

        return json_encode($normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    public static function require(bool $condition): void
    {
        if (! $condition) {
            throw new RuntimeException('Maintenance validation failed; no source data may be overwritten.');
        }
    }

    public static function exactKeys(array $value, array $keys): void
    {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);
        self::require($actual === $keys);
    }

    public static function uuid(mixed $value): void
    {
        self::require(is_string($value) && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/', $value) === 1);
    }

    public static function subject(mixed $value): void
    {
        self::require(is_string($value) && preg_match('/^[1-9][0-9]{0,19}$/', $value) === 1);
        self::require(strlen($value) < 20 || strcmp($value, '18446744073709551615') <= 0);
    }

    private function validateRow(string $table, array $row): void
    {
        foreach ($row as $column => $value) {
            if (in_array($column, ['user_id', 'owner_subject_id'], true)) {
                self::subject($value);
                self::require(in_array($value, $this->owners, true));
            } elseif (in_array($column, ['id', 'client_id', 'vehicle_id', 'appointment_id'], true)) {
                if ($table === 'appointment_schedules' && $column === 'id') {
                    self::subject($value);
                } else {
                    self::uuid($value);
                }
            } elseif ($column === 'data') {
                self::require(($value === null && in_array($table, ['clients', 'vehicles'], true))
                    || ($value instanceof stdClass && ($value->schemaVersion ?? null) === 1));
            } elseif (in_array($column, ['created_at', 'updated_at', 'recorded_at', 'deleted_at'], true)) {
                if ($column === 'deleted_at' && $value === null) {
                    continue;
                }
                $format = $column === 'deleted_at' ? 'Y-m-d H:i:s' : 'Y-m-d H:i:s.v';
                self::require(is_string($value) && Validator::make(['value' => $value], ['value' => 'required|date_format:'.$format])->passes());
                self::require($value >= '1970-01-01 00:00:01' && $value <= '2038-01-19 03:14:07.999');
            } elseif ($column === 'year' || $column === 'version') {
                self::require(is_int($value) && $value >= ($column === 'year' ? 0 : 1) && $value <= ($column === 'year' ? 65535 : 4294967295));
            } else {
                self::require(is_string($value));
                $maximumLength = in_array($column, ['notes', 'description'], true) ? 65535 : 255;
                self::require((in_array($column, ['notes', 'description'], true) ? strlen($value) : mb_strlen($value)) <= $maximumLength);
            }
        }
        if ($table === 'appointments') {
            self::require(Validator::make($row, ['date' => 'required|date_format:Y-m-d', 'start_time' => 'required|date_format:H:i:s', 'end_time' => 'required|date_format:H:i:s'])->passes());
            self::require($row['start_time'] < $row['end_time']);
            self::require(in_array($row['status'], ['scheduled', 'confirmed', 'inProgress', 'completed', 'cancelled'], true));
        }
    }

    private function validateRelationships(): void
    {
        $references = [];
        if ($this->domain === 'clients') {
            $clients = array_column($this->tables['clients'], null, 'id');
            $vehicles = array_column($this->tables['vehicles'], null, 'id');
            $subjects = array_column($this->tables['appointment_subjects'], 'owner_subject_id', 'appointment_id');
            foreach ($vehicles as $vehicle) {
                self::require(isset($clients[$vehicle['client_id']]));
            }
            foreach ($this->tables['appointment_references'] as $reference) {
                $client = $clients[$reference['client_id']] ?? null;
                $vehicle = $vehicles[$reference['vehicle_id']] ?? null;
                self::require($client !== null && $vehicle !== null && $vehicle['client_id'] === $client['id']);
                self::require($reference['owner_subject_id'] === $client['user_id'] && ($subjects[$reference['appointment_id']] ?? null) === $client['user_id']);
                self::require(! isset($references[$reference['appointment_id']]));
                $references[$reference['appointment_id']] = $reference;
            }
            self::require(count($subjects) === count($references));
        } else {
            $associations = [];
            foreach ($this->tables['client_vehicle_associations'] as $association) {
                $associations[$this->identity('client_vehicle_associations', $association)] = $association['user_id'];
            }
            $usedAssociations = [];
            foreach ($this->tables['appointments'] as $appointment) {
                $key = $this->identity('client_vehicle_associations', $appointment);
                self::require(($associations[$key] ?? null) === $appointment['user_id']);
                $usedAssociations[$key] = true;
                $references[$appointment['id']] = ['appointment_id' => $appointment['id'], 'client_id' => $appointment['client_id'], 'vehicle_id' => $appointment['vehicle_id'], 'owner_subject_id' => $appointment['user_id']];
            }
            self::require(count($usedAssociations) === count($associations));
            $scheduleOwners = array_column($this->tables['appointment_schedules'], 'user_id');
            self::require(count(array_unique($scheduleOwners)) === count($scheduleOwners));
        }
        $lines = array_map(fn (array $reference): string => implode('|', [$reference['appointment_id'], $reference['client_id'], $reference['vehicle_id'], $reference['owner_subject_id']]), array_values($references));
        sort($lines, SORT_STRING);
        self::require(hash_equals($this->document->referenceDigest, hash('sha256', implode("\n", $lines))));
    }
}
