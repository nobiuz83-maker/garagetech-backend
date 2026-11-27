<?php

use App\Services\CompanyRequestSignature;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('integration') || getenv('VICARTECH_SYNTHETIC_INTEGRATION') !== 'true') {
    throw new LogicException('Only the synthetic integration deployment is allowed.');
}
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$target = '/api/internal/integration/v2/identity';
$headers = ['Accept' => 'application/json', 'X-Internal-Service' => $input['claimedService'] ?? 'billing-service',
    'X-Company-ID' => $input['companyId'], 'X-Request-ID' => (string) Str::uuid(),
    'X-Internal-Timestamp' => (string) (now()->timestamp - ($input['ageSeconds'] ?? 0)),
    'X-Internal-Nonce' => Str::random(32), 'Cookie' => $input['cookie']];
$headers['X-Internal-Signature'] = hash_hmac('sha256', app(CompanyRequestSignature::class)->canonical('GET', $target, $headers, ''), file_get_contents('/run/integration/keys/billing-to-inventory.key'));
if ($input['tamper'] ?? false) { $headers['X-Company-ID'] = (string) Str::uuid(); }
$statuses = [];
for ($attempt = 0; $attempt < (($input['replay'] ?? false) ? 2 : 1); $attempt++) {
    $response = Http::withOptions(['verify' => '/run/integration/tls/ca.pem', 'cert' => '/run/integration/tls/client/cert.pem',
        'ssl_key' => '/run/integration/tls/client/key.pem', 'connect_timeout' => 0.5, 'timeout' => 3, 'allow_redirects' => false])
        ->withHeaders($headers)->get('https://inventory-service:8443'.$target);
    $statuses[] = $response->status();
}
echo json_encode(['statuses' => $statuses]);
