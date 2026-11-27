<?php

namespace App\Services;

use App\Auth\CompanyActor;
use App\Auth\SecurityFailure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class VerifyBillingIntervention
{
    /** @return array<string, mixed> */
    public function verify(Request $request, CompanyActor $actor, string $interventionId): array
    {
        $baseUrl = config('billing_references.interventions_url');
        $parts = is_string($baseUrl) ? parse_url($baseUrl) : false;
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw new SecurityFailure('INTERVENTIONS_SERVICE_UNAVAILABLE');
        }
        foreach (['ca_certificate_file', 'client_certificate_file', 'client_key_file'] as $setting) {
            $path = config('billing_references.'.$setting);
            if (! is_string($path) || ! is_file($path) || ! is_readable($path)) {
                throw new SecurityFailure('INTERVENTIONS_SERVICE_UNAVAILABLE');
            }
        }
        $cookieName = config('company_security.session_cookie');
        $cookie = $request->cookies->get($cookieName);
        if (! is_string($cookie) || $cookie === '') {
            throw new SecurityFailure('UNAUTHENTICATED', 401);
        }
        try {
            $response = Http::withOptions([
                'verify' => config('billing_references.ca_certificate_file'),
                'cert' => config('billing_references.client_certificate_file'),
                'ssl_key' => config('billing_references.client_key_file'),
                'connect_timeout' => 0.5, 'timeout' => 2, 'allow_redirects' => false,
            ])->withHeaders([
                'Accept' => 'application/json', 'Cookie' => $cookieName.'='.rawurlencode($cookie),
                'X-Company-ID' => $actor->companyId, 'X-Request-ID' => $actor->requestId,
            ])->get(rtrim($baseUrl, '/').'/api/v1/interventions/'.$interventionId);
        } catch (ConnectionException) {
            throw new SecurityFailure('INTERVENTIONS_SERVICE_UNAVAILABLE');
        }
        if (in_array($response->status(), [403, 404], true)) {
            throw new SecurityFailure('RESOURCE_NOT_FOUND', 404);
        }
        $intervention = $response->json('data');
        if ($response->status() !== 200 || ! is_array($intervention) || ($intervention['id'] ?? null) !== $interventionId
            || ! Str::isUuid($intervention['clientId'] ?? '') || ! Str::isUuid($intervention['vehicleId'] ?? '')
            || ! is_int($intervention['laborRateCents'] ?? null) || $intervention['laborRateCents'] < 0
            || ! is_string($intervention['laborHours'] ?? null) || ! preg_match('/^[0-9]{1,8}\\.[0-9]{4}$/D', $intervention['laborHours'])
            || ! is_string($intervention['description'] ?? null)) {
            throw new SecurityFailure('INTERVENTIONS_SERVICE_UNAVAILABLE');
        }

        return $intervention;
    }
}
