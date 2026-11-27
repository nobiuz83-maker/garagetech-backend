<?php

namespace App\Services;

use App\Auth\CompanyActor;
use App\Auth\SecurityFailure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class FreezeBillingInventory
{
    public function __construct(private CompanyRequestSignature $signature) {}

    /** @return list<array<string, mixed>> */
    public function freeze(Request $request, CompanyActor $actor, string $interventionId, string $invoiceId, int $laborSubtotalCents, ?string $expectedLinesHash = null): array
    {
        return $this->retrieve($request, $actor, $interventionId, $invoiceId, $laborSubtotalCents, $expectedLinesHash);
    }

    /** @return list<array<string, mixed>> */
    public function preview(Request $request, CompanyActor $actor, string $interventionId, int $laborSubtotalCents): array
    {
        return $this->retrieve($request, $actor, $interventionId, null, $laborSubtotalCents);
    }

    /** @return list<array<string, mixed>> */
    private function retrieve(Request $request, CompanyActor $actor, string $interventionId, ?string $invoiceId, int $laborSubtotalCents, ?string $expectedLinesHash = null): array
    {
        $url = config('billing_references.inventory_url');
        $parts = is_string($url) ? parse_url($url) : false;
        $keyFile = config('billing_references.inventory_signing_key_file');
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true) || ! is_string($keyFile) || ! is_readable($keyFile)
            || strlen($key = (string) file_get_contents($keyFile)) < 32) {
            throw new SecurityFailure('INVENTORY_SERVICE_UNAVAILABLE');
        }
        foreach (['ca_certificate_file', 'client_certificate_file', 'client_key_file'] as $setting) {
            $path = config('billing_references.'.$setting);
            if (! is_string($path) || ! is_readable($path)) {
                throw new SecurityFailure('INVENTORY_SERVICE_UNAVAILABLE');
            }
        }
        $cookieName = config('company_security.session_cookie');
        $cookie = $request->cookies->get($cookieName);
        $csrf = $request->header('X-XSRF-TOKEN');
        if (! is_string($cookie) || $cookie === '' || ! is_string($csrf) || $csrf === '') {
            throw new SecurityFailure('UNAUTHENTICATED', 401);
        }
        $isPreview = $invoiceId === null;
        $target = '/api/internal/v1/inventory/interventions/'.$interventionId.($isPreview ? '/preview' : '/freeze');
        $body = $isPreview ? '{}' : json_encode(['invoiceId' => $invoiceId, 'laborSubtotalCents' => $laborSubtotalCents,
            'expectedLinesHash' => $expectedLinesHash], JSON_THROW_ON_ERROR);
        $headers = [
            'Accept' => 'application/json', 'X-Internal-Service' => 'billing-service',
            'X-Company-ID' => $actor->companyId, 'X-Request-ID' => $actor->requestId,
            'X-Internal-Timestamp' => (string) now()->timestamp, 'X-Internal-Nonce' => Str::random(32),
            'Cookie' => $cookieName.'='.rawurlencode($cookie), 'X-XSRF-TOKEN' => $csrf,
        ];
        $headers['Content-Type'] = 'application/json';
        $headers['Idempotency-Key'] = $request->header('Idempotency-Key');
        $headers['X-Internal-Signature'] = hash_hmac('sha256', $this->signature->canonical('POST', $target, $headers, $body), $key);
        try {
            $client = Http::withOptions([
                'verify' => config('billing_references.ca_certificate_file'), 'cert' => config('billing_references.client_certificate_file'),
                'ssl_key' => config('billing_references.client_key_file'), 'connect_timeout' => 0.5, 'timeout' => 3, 'allow_redirects' => false,
            ])->withHeaders($headers);
            $response = $client->withBody($body, 'application/json')->post(rtrim($url, '/').$target);
        } catch (ConnectionException) {
            throw new SecurityFailure('INVENTORY_SERVICE_UNAVAILABLE');
        }
        if ($response->status() === 409) {
            throw new SecurityFailure('INTERVENTION_INVOICED', 409);
        }
        $data = $response->json('data');
        if ($response->status() !== 200 || ! is_array($data) || (! $isPreview && ($data['invoiceId'] ?? null) !== $invoiceId)
            || ($data['interventionId'] ?? null) !== $interventionId || ! is_array($data['lines'] ?? null)
            || ! array_is_list($data['lines']) || count($data['lines']) > 1000) {
            throw new SecurityFailure('INVENTORY_SERVICE_UNAVAILABLE');
        }
        foreach ($data['lines'] as $line) {
            if (! is_array($line) || ! Str::isUuid($line['id'] ?? '') || ! Str::isUuid($line['productId'] ?? '')
                || ! is_string($line['sku'] ?? null) || ! is_string($line['nameFr'] ?? null)
                || ! (is_string($line['nameEn'] ?? null) || ($line['nameEn'] ?? null) === null)
                || ! is_int($line['quantityUnits'] ?? null) || $line['quantityUnits'] < 1
                || ! is_int($line['unitPriceCents'] ?? null) || $line['unitPriceCents'] < 0
                || ! is_int($line['totalCents'] ?? null) || $line['totalCents'] < 0
                || ($line['unitPriceCents'] !== 0 && $line['quantityUnits'] > intdiv(PHP_INT_MAX - 500, $line['unitPriceCents']))
                || $line['totalCents'] !== intdiv($line['quantityUnits'] * $line['unitPriceCents'] + 500, 1000)) {
                throw new SecurityFailure('INVENTORY_SERVICE_UNAVAILABLE');
            }
        }

        return $data['lines'];
    }
}
