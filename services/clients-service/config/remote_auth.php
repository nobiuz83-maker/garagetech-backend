<?php

return [
    'url' => env('AUTH_SERVICE_URL'),
    'service_name' => 'clients-service',
    'session_cookie' => 'vicartech-auth-session',
    'ca_certificate_file' => env('AUTH_CA_CERTIFICATE_FILE'),
    'client_certificate_file' => env('AUTH_CLIENT_CERTIFICATE_FILE'),
    'client_key_file' => env('AUTH_CLIENT_KEY_FILE'),
    'signing_key_file' => env('AUTH_SIGNING_KEY_FILE'),
    'permissions' => ['clients.read', 'clients.write'],
];
