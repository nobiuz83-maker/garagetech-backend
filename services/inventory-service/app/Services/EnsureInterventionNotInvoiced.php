<?php

namespace App\Services;

use App\Auth\CompanyActor;
use App\Auth\SecurityFailure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class EnsureInterventionNotInvoiced
{
    public function __construct(private CompanyRequestSignature $signature) {}

    public function check(CompanyActor $actor, string $interventionId): void
    {
        $url = config('inventory_references.billing_url');
        $parts = is_string($url) ? parse_url($url) : false;
        $keyFile = config('inventory_references.billing_signing_key_file');
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true) || ! is_string($keyFile) || ! is_readable($keyFile)
            || strlen($key = (string) file_get_contents($keyFile)) < 32) {
            throw new SecurityFailure('BILLING_SERVICE_UNAVAILABLE');
        }
        foreach (['ca_certificate_file', 'client_certificate_file', 'client_key_file'] as $setting) {
            $path = config('inventory_references.'.$setting);
            if (! is_string($path) || ! is_readable($path)) {
                throw new SecurityFailure('BILLING_SERVICE_UNAVAILABLE');
            }
        }
        $target = '/api/internal/v1/billing/interventions/'.$interventionId.'/status';
        $headers = [
            'X-Internal-Service' => 'inventory-service', 'X-Company-ID' => $actor->companyId, 'X-Request-ID' => $actor->requestId,
            'X-Internal-Timestamp' => (string) now()->timestamp, 'X-Internal-Nonce' => Str::random(32),
        ];
        $headers['X-Internal-Signature'] = hash_hmac('sha256', $this->signature->canonical('GET', $target, $headers, ''), $key);
        try {
            $response = Http::withOptions([
                'verify' => config('inventory_references.ca_certificate_file'), 'cert' => config('inventory_references.client_certificate_file'),
                'ssl_key' => config('inventory_references.client_key_file'), 'connect_timeout' => 0.5, 'timeout' => 2, 'allow_redirects' => false,
            ])->withHeaders($headers)->get(rtrim($url, '/').$target);
        } catch (ConnectionException) {
            throw new SecurityFailure('BILLING_SERVICE_UNAVAILABLE');
        }
        $data = $response->json('data');
        if ($response->status() !== 200 || ! is_array($data) || ($data['schemaVersion'] ?? null) !== 1
            || ($data['interventionId'] ?? null) !== $interventionId || ! is_bool($data['invoiced'] ?? null)) {
            throw new SecurityFailure('BILLING_SERVICE_UNAVAILABLE');
        }
        if ($data['invoiced']) {
            throw new SecurityFailure('INTERVENTION_INVOICED', 409);
        }
    }
}
