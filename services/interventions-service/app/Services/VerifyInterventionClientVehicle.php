<?php

namespace App\Services;

use App\Auth\CompanyActor;
use App\Auth\SecurityFailure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

final class VerifyInterventionClientVehicle
{
    public function verify(Request $request, CompanyActor $actor, string $clientId, string $vehicleId): void
    {
        $mapping = DB::table('intervention_legacy_owner_mappings')->where('company_id', $actor->companyId)->first();
        if ($mapping === null || (string) $mapping->owner_subject_id !== $actor->subject) {
            throw new SecurityFailure('LEGACY_OWNER_MAPPING_REQUIRED', 403);
        }

        $baseUrl = config('intervention_references.clients_url');
        $parts = is_string($baseUrl) ? parse_url($baseUrl) : false;
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw new SecurityFailure('CLIENTS_SERVICE_UNAVAILABLE');
        }
        foreach (['ca_certificate_file', 'client_certificate_file', 'client_key_file'] as $setting) {
            $path = config('intervention_references.'.$setting);
            if (! is_string($path) || ! is_file($path) || ! is_readable($path)) {
                throw new SecurityFailure('CLIENTS_SERVICE_UNAVAILABLE');
            }
        }
        $cookieName = config('company_security.session_cookie');
        $session = $request->cookies->get($cookieName);
        if (! is_string($session) || $session === '') {
            throw new SecurityFailure('UNAUTHENTICATED', 401);
        }

        try {
            $response = Http::withOptions([
                'verify' => config('intervention_references.ca_certificate_file'),
                'cert' => config('intervention_references.client_certificate_file'),
                'ssl_key' => config('intervention_references.client_key_file'),
                'connect_timeout' => 0.5, 'timeout' => 2, 'allow_redirects' => false,
            ])->withHeaders(['Accept' => 'application/json', 'Cookie' => $cookieName.'='.rawurlencode($session)])
                ->withHeaders(['X-Company-ID' => $actor->companyId, 'X-Request-ID' => $actor->requestId])
                ->get(rtrim($baseUrl, '/').'/api/v1/clients/'.$clientId);
        } catch (ConnectionException) {
            throw new SecurityFailure('CLIENTS_SERVICE_UNAVAILABLE');
        }

        if (in_array($response->status(), [403, 404], true)) {
            throw ValidationException::withMessages(['clientId' => 'INTERVENTION_CLIENT_REQUIRED']);
        }
        $client = $response->json('data');
        if ($response->status() !== 200 || ! is_array($client) || ($client['id'] ?? null) !== $clientId
            || ! is_array($client['vehicles'] ?? null) || ! array_is_list($client['vehicles'])) {
            throw new SecurityFailure('CLIENTS_SERVICE_UNAVAILABLE');
        }
        foreach ($client['vehicles'] as $vehicle) {
            if (is_array($vehicle) && ($vehicle['id'] ?? null) === $vehicleId && ($vehicle['clientId'] ?? null) === $clientId) {
                return;
            }
        }
        throw ValidationException::withMessages(['vehicleId' => 'INTERVENTION_VEHICLE_REQUIRED']);
    }
}
