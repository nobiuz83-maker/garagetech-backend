<?php

use App\Models\Client;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$database = DB::connection()->getDatabaseName();
if (DB::connection()->getDriverName() !== 'mysql' || ! str_starts_with($database, 'vicartech_test_e2e_')) {
    throw new RuntimeException('Browser fixtures require a dedicated vicartech_test_e2e_ database.');
}

$action = $argv[1] ?? '';
$credentialPath = getenv('VICARTECH_E2E_CREDENTIALS');
if (! $credentialPath) {
    throw new RuntimeException('VICARTECH_E2E_CREDENTIALS must point to a private temporary file.');
}

if ($action === 'prepare') {
    Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
    $password = bin2hex(random_bytes(24));
    $user = User::factory()->create(['email' => 'browser-'.bin2hex(random_bytes(6)).'@example.test', 'password' => $password]);
    $credentials = ['email' => $user->email, 'password' => $password, 'userId' => $user->id, 'database' => $database];
    $credentialFile = fopen($credentialPath, 'x');
    if ($credentialFile === false || ! chmod($credentialPath, 0600)) {
        throw new RuntimeException('Unable to create a private browser credential file.');
    }
    fwrite($credentialFile, json_encode($credentials, JSON_THROW_ON_ERROR));
    fclose($credentialFile);
    echo "Isolated browser fixture prepared.\n";
} elseif ($action === 'verify') {
    $expected = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $credentials = json_decode(file_get_contents($credentialPath), true, 512, JSON_THROW_ON_ERROR);
    $client = Client::where('user_id', $credentials['userId'])->findOrFail($expected['clientId']);
    $vehicles = $client->vehicles()->orderBy('make')->get();
    if ($client->first_name !== $expected['firstName'] || $vehicles->count() !== 2
        || ($client->data['address']['city'] ?? null) !== 'Shawinigan'
        || ($client->data['schemaVersion'] ?? null) !== 1
        || ! $vehicles->contains(fn (Vehicle $vehicle): bool => $vehicle->model === $expected['vehicleModel'])) {
        throw new RuntimeException('The browser changes were not persisted correctly in MariaDB.');
    }
    echo "MariaDB verified: client, two vehicle relations and versioned address JSON.\n";
} else {
    throw new RuntimeException('Expected prepare or verify.');
}
