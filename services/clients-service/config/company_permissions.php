<?php

return [
    'permissions' => [
        'clients.read' => ['mutating' => false, 'requires' => []],
        'clients.write' => ['mutating' => true, 'requires' => ['clients.read']],
        'clients.archive' => ['mutating' => true, 'requires' => ['clients.read']],
        'clients.restore' => ['mutating' => true, 'requires' => ['clients.read']],
        'vehicles.read' => ['mutating' => false, 'requires' => []],
        'vehicles.write' => ['mutating' => true, 'requires' => ['vehicles.read']],
        'vehicles.archive' => ['mutating' => true, 'requires' => ['vehicles.read']],
        'vehicles.restore' => ['mutating' => true, 'requires' => ['vehicles.read']],
    ],
];
