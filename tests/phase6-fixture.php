<?php

use App\Models\Appointment;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'vicartech_test_phase6') {
    throw new RuntimeException('Phase 6 fixtures require the dedicated Docker test database.');
}

if (($argv[1] ?? '') === 'prepare') {
    $accounts = [];
    foreach (['owner', 'other'] as $role) {
        $password = bin2hex(random_bytes(24));
        $user = User::factory()->create(['email' => 'phase6-'.$role.'-'.bin2hex(random_bytes(6)).'@example.test', 'password' => $password]);
        $accounts[] = ['role' => $role, 'email' => $user->email, 'password' => $password];
    }
    echo json_encode(['database' => DB::connection()->getDatabaseName(), 'accounts' => $accounts], JSON_THROW_ON_ERROR);
} elseif (($argv[1] ?? '') === 'verify') {
    $expected = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $appointment = Appointment::findOrFail($expected['appointmentId']);
    $client = $appointment->client;
    $vehicles = $client->vehicles()->get();
    if ($appointment->notes !== 'Updated through HTTP' || $appointment->status !== 'cancelled'
        || $appointment->version !== 3 || $client->id !== $expected['clientId']
        || $vehicles->count() !== 2
        || ! $vehicles->contains(fn ($vehicle): bool => $vehicle->make === 'Mazda' && $vehicle->year === 2025)
        || ! $vehicles->contains(fn ($vehicle): bool => $vehicle->make === 'Honda' && $vehicle->year === 2026)) {
        throw new RuntimeException('Direct SQL persistence verification failed.');
    }
    echo "MariaDB verified: appointment, versions, client relation, Mazda 2025 and Honda 2026.\n";
} else {
    throw new RuntimeException('Expected prepare or verify.');
}
