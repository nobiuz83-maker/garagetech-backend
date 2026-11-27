<?php

return [
    'paths' => ['api/user', 'sanctum/csrf-cookie', 'login', 'logout'],
    'allowed_methods' => ['GET', 'POST', 'OPTIONS'],
    'allowed_origins' => [env('FRONTEND_URL', 'http://localhost:5173')],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Accept', 'Accept-Language', 'Content-Type', 'X-XSRF-TOKEN', 'X-Requested-With'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];
