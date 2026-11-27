<?php

return [
    'defaults' => ['guard' => 'remote'],
    'guards' => [
        'remote' => ['driver' => 'remote-identity'],
        'company-identity' => ['driver' => 'company-identity'],
    ],
];
