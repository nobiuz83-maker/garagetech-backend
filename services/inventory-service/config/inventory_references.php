<?php

return [
    'interventions_url' => env('INVENTORY_INTERVENTIONS_URL'),
    'ca_certificate_file' => env('INVENTORY_INTERVENTIONS_CA_FILE'),
    'client_certificate_file' => env('INVENTORY_INTERVENTIONS_CERT_FILE'),
    'client_key_file' => env('INVENTORY_INTERVENTIONS_KEY_FILE'),
    'billing_url' => env('INVENTORY_BILLING_URL'),
    'billing_signing_key_file' => env('INVENTORY_TO_BILLING_SIGNING_KEY_FILE'),
];
