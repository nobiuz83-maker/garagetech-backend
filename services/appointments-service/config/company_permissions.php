<?php

return [
    'permissions' => [
        'appointments.read' => ['mutating' => false, 'requires' => []],
        'appointments.write' => ['mutating' => true, 'requires' => ['appointments.read']],
        'appointments.cancel' => ['mutating' => true, 'requires' => ['appointments.read']],
        'appointments.archive' => ['mutating' => true, 'requires' => ['appointments.read']],
        'appointments.settings.manage' => ['mutating' => true, 'requires' => ['appointments.read']],
    ],
];
