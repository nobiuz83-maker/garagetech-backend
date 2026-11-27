<?php

return [
    'enabled' => (bool) env('COMPANY_SECURITY_ENABLED', false),
    'service_name' => 'appointments-service',
    'permission_prefixes' => ['appointments'],
    'auth_url' => env('AUTH_SERVICE_V2_URL'),
    'session_cookie' => 'vicartech-auth-session',
    'ca_certificate_file' => env('AUTH_V2_CA_CERTIFICATE_FILE'),
    'client_certificate_file' => env('AUTH_V2_CLIENT_CERTIFICATE_FILE'),
    'client_key_file' => env('AUTH_V2_CLIENT_KEY_FILE'),
    'signing_key_file' => env('AUTH_V2_SIGNING_KEY_FILE'),
    'connect_timeout_seconds' => (float) env('AUTH_V2_CONNECT_TIMEOUT_SECONDS', 0.5),
    'timeout_seconds' => (float) env('AUTH_V2_TIMEOUT_SECONDS', 2),
    'clock_skew_seconds' => (int) env('INTERNAL_V2_CLOCK_SKEW_SECONDS', 30),
    'nonce_seconds' => (int) env('INTERNAL_V2_NONCE_SECONDS', 90),
    'callers' => [],
    'distinct_author_permissions' => [],
];
