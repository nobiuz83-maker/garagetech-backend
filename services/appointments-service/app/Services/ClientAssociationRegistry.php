<?php

namespace App\Services;

use App\Auth\CompanyActor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Cookie;
use Throwable;

class ClientAssociationRegistry
{
    public function __construct(private Request $request) {}

    public function retain(CompanyActor $actor, string $appointmentId, array $input): void
    {
        $expected = ['appointmentId' => $appointmentId, 'clientId' => $input['clientId'], 'vehicleId' => $input['vehicleId']];
        $payload = $this->send('POST', '/api/internal/v1/appointment-references', $expected, $actor);
        abort_unless(($payload['schemaVersion'] ?? null) === 1 && ($payload['retained'] ?? null) === true
            && ($payload['ownerSubjectId'] ?? null) === $actor->subject, 503, 'CLIENTS_RESPONSE_INVALID');
        foreach ($expected as $property => $value) {
            abort_unless(($payload[$property] ?? null) === $value, 503, 'CLIENTS_RESPONSE_INVALID');
        }
    }

    public function search(CompanyActor $actor, string $search): array
    {
        $payload = $this->send('GET', '/api/internal/v1/client-vehicle-search?search='.rawurlencode($search), [], $actor);
        abort_unless(($payload['schemaVersion'] ?? null) === 1 && ($payload['ownerSubjectId'] ?? null) === $actor->subject, 503, 'CLIENTS_RESPONSE_INVALID');
        foreach (['clientIds', 'vehicleIds'] as $property) {
            $identifiers = $payload[$property] ?? null;
            abort_unless(is_array($identifiers) && array_is_list($identifiers) && count($identifiers) <= 1000, 503, 'CLIENTS_RESPONSE_INVALID');
            foreach ($identifiers as $identifier) {
                abort_unless(is_string($identifier) && Str::isUuid($identifier), 503, 'CLIENTS_RESPONSE_INVALID');
            }
        }

        return $payload;
    }

    private function send(string $method, string $target, array $input, CompanyActor $actor): array
    {
        $baseUrl = config('relationships.clients_url');
        abort_unless(is_string($baseUrl) && str_starts_with($baseUrl, 'https://'), 503, 'CLIENTS_CONFIGURATION_UNAVAILABLE');
        $keyFile = config('relationships.signing_key_file');
        abort_unless(is_string($keyFile) && is_readable($keyFile), 503, 'CLIENTS_CONFIGURATION_UNAVAILABLE');
        $key = file_get_contents($keyFile);
        abort_unless(is_string($key) && strlen($key) >= 32, 503, 'CLIENTS_CONFIGURATION_UNAVAILABLE');
        $body = $method === 'GET' ? '' : json_encode($input, JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->timestamp;
        $nonce = Str::random(32);
        $canonical = implode("\n", [$method, $target, $timestamp, $nonce, hash('sha256', $body)]);
        $cookieName = config('remote_auth.session_cookie');
        $cookie = $this->request->cookies->get($cookieName);
        abort_unless(is_string($cookie) && $cookie !== '', 401, 'UNAUTHENTICATED');
        $headers = [
            'Accept' => 'application/json', 'Cookie' => $cookieName.'='.rawurlencode($cookie),
            'X-Internal-Service' => 'appointments-service', 'X-Internal-Timestamp' => $timestamp,
            'X-Internal-Nonce' => $nonce, 'X-Internal-Signature' => hash_hmac('sha256', $canonical, $key),
            'X-Company-ID' => $actor->companyId, 'X-Request-ID' => $actor->requestId,
        ];
        if ($method === 'POST' && $this->request->hasHeader('X-XSRF-TOKEN')) {
            $headers['X-XSRF-TOKEN'] = $this->request->header('X-XSRF-TOKEN');
            $headers['Idempotency-Key'] = (string) Str::uuid();
        }
        try {
            $client = Http::withOptions([
                'verify' => config('remote_auth.ca_certificate_file'), 'cert' => config('remote_auth.client_certificate_file'),
                'ssl_key' => config('remote_auth.client_key_file'), 'connect_timeout' => .5, 'timeout' => 2, 'allow_redirects' => false,
            ])->withHeaders($headers);
            $response = $method === 'GET'
                ? $client->get(rtrim($baseUrl, '/').$target)
                : $client->withBody($body, 'application/json')->post(rtrim($baseUrl, '/').$target);
        } catch (Throwable $exception) {
            Log::warning('Clients relationship verification unavailable.', ['exception' => $exception::class]);
            abort(503, 'CLIENTS_SERVICE_UNAVAILABLE');
        }
        $payload = $response->json();
        abort_unless(is_array($payload), 503, 'CLIENTS_RESPONSE_INVALID');
        if ($response->status() === 422 && ($payload['message'] ?? null) === 'SEARCH_TOO_BROAD') {
            throw ValidationException::withMessages(['search' => 'SEARCH_TOO_BROAD']);
        }
        if ($response->status() === 422) {
            throw ValidationException::withMessages(isset($payload['errors']['clientId'])
                ? ['clientId' => 'APPOINTMENT_CLIENT_REQUIRED'] : ['vehicleId' => 'APPOINTMENT_VEHICLE_REQUIRED']);
        }
        if ($response->status() === 409) {
            abort(409, 'REFERENCE_CONFLICT');
        }
        if ($response->status() === 419) {
            abort(419, 'CSRF_FAILED');
        }
        if ($response->status() === 401 && ($payload['error'] ?? null) === 'UNAUTHENTICATED') {
            abort(401, 'UNAUTHENTICATED');
        }
        if ($response->status() === 403 && ($payload['error'] ?? null) === 'FORBIDDEN') {
            abort(403, 'FORBIDDEN');
        }
        abort_unless($response->status() === 200 && is_array($payload['data'] ?? null), 503, 'CLIENTS_SERVICE_UNAVAILABLE');
        $cookies = $response->toPsrResponse()->getHeader('Set-Cookie');
        foreach ($cookies as $header) {
            try {
                $cookie = Cookie::fromString($header);
            } catch (Throwable) {
                abort(503, 'CLIENTS_RESPONSE_INVALID');
            }
            abort_unless(in_array($cookie->getName(), [$cookieName, 'XSRF-TOKEN'], true)
                && $cookie->getPath() === '/' && $cookie->getDomain() === null
                && ($cookie->getName() !== $cookieName || $cookie->isHttpOnly()), 503, 'CLIENTS_RESPONSE_INVALID');
        }
        if ($cookies !== []) {
            $this->request->attributes->set('relationship_auth_cookies', $cookies);
        }

        return $payload['data'];
    }
}
