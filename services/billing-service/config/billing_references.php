<?php

return [
    'interventions_url' => env('BILLING_INTERVENTIONS_URL'),
    'ca_certificate_file' => env('BILLING_INTERVENTIONS_CA_FILE'),
    'client_certificate_file' => env('BILLING_INTERVENTIONS_CERT_FILE'),
    'client_key_file' => env('BILLING_INTERVENTIONS_KEY_FILE'),
    'inventory_url' => env('BILLING_INVENTORY_URL'),
    'inventory_signing_key_file' => env('BILLING_TO_INVENTORY_SIGNING_KEY_FILE'),
];
