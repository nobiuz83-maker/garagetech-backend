<?php

return ['clock_skew_seconds' => 30, 'require_mtls' => true, 'nonce_lifetime_seconds' => 90, 'callers' => [
    'appointments-service' => ['key_file' => env('APPOINTMENTS_RELATIONSHIP_KEY_FILE')],
]];
