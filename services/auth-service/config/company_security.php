<?php

return [
    'service_name' => 'auth-service',
    'enabled' => (bool) env('COMPANY_SECURITY_ENABLED', false),
    'clock_skew_seconds' => (int) env('INTERNAL_V2_CLOCK_SKEW_SECONDS', 30),
    'nonce_seconds' => (int) env('INTERNAL_V2_NONCE_SECONDS', 90),
    'absolute_session_seconds' => (int) env('COMPANY_ABSOLUTE_SESSION_SECONDS', 43200),
    'recent_authentication_seconds' => (int) env('COMPANY_RECENT_AUTHENTICATION_SECONDS', 300),
    'callers' => [
        'inventory-service' => ['key_file' => env('INTERNAL_AUTH_V2_INVENTORY_KEY_FILE'), 'capabilities' => ['session.validate'], 'prefix' => 'inventory'],
        'clients-service' => ['key_file' => env('INTERNAL_AUTH_V2_CLIENTS_KEY_FILE'), 'capabilities' => ['session.validate'], 'prefixes' => ['clients', 'vehicles']],
        'appointments-service' => ['key_file' => env('INTERNAL_AUTH_V2_APPOINTMENTS_KEY_FILE'), 'capabilities' => ['session.validate'], 'prefix' => 'appointments'],
        'interventions-service' => ['key_file' => env('INTERNAL_AUTH_V2_INTERVENTIONS_KEY_FILE'), 'capabilities' => ['session.validate'], 'prefix' => 'interventions'],
        'billing-service' => ['key_file' => env('INTERNAL_AUTH_V2_BILLING_KEY_FILE'), 'capabilities' => ['session.validate'], 'prefix' => 'billing'],
        'accounting-service' => ['key_file' => env('INTERNAL_AUTH_V2_ACCOUNTING_KEY_FILE'), 'capabilities' => ['session.validate'], 'prefix' => 'accounting'],
    ],
];
