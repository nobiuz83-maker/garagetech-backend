<?php

return [
    'clock_skew_seconds' => 30,
    'require_mtls' => true,
    'nonce_lifetime_seconds' => 90,
    'permissions' => [
        'clients.read',
        'clients.write',
        'appointments.read',
        'appointments.write',
    ],
    'callers' => [
        'clients-service' => [
            'key_file' => env('INTERNAL_AUTH_CLIENTS_KEY_FILE'),
            'permissions' => ['clients.read', 'clients.write'],
        ],
        'appointments-service' => [
            'key_file' => env('INTERNAL_AUTH_APPOINTMENTS_KEY_FILE'),
            'permissions' => ['appointments.read', 'appointments.write'],
        ],
    ],
];
