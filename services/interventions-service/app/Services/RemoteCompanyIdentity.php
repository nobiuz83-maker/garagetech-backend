<?php

namespace App\Services;

use App\Auth\CompanyActor;
use App\Auth\SecurityFailure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

final class RemoteCompanyIdentity
{
    public function __construct(private CompanyRequestSignature $signature) {}

    /** @param list<string> $permissions */
    public function verify(Request $request, array $permissions, string $purpose): CompanyActor
    {
        $cookieName = config('company_security.session_cookie');
        $cookie = $request->cookies->get($cookieName);
        if (! is_string($cookie) || $cookie === '') {
            throw new SecurityFailure('UNAUTHENTICATED', 401);
        }
        $companyId = $request->header('X-Company-ID', '');
        if (! Str::isUuid($companyId)) {
            throw new SecurityFailure($companyId === '' ? 'COMPANY_CONTEXT_REQUIRED' : 'COMPANY_CONTEXT_INVALID', 400);
        }
        $read = $purpose === 'resource.read';
        if (! $read && (! $request->hasHeader('X-XSRF-TOKEN') || $request->header('X-XSRF-TOKEN') === '')) {
            throw new SecurityFailure('CSRF_TOKEN_MISMATCH', 419);
        }
        $this->validateConfiguration();
        $method = $read ? 'GET' : 'POST';
        $target = '/api/internal/v2/session/validate';
        $body = '';
        if ($read) {
            $target .= '?'.http_build_query(['requiredPermissions' => implode(',', $permissions), 'purpose' => $purpose], '', '&', PHP_QUERY_RFC3986);
        } else {
            $body = json_encode(['requiredPermissions' => $permissions, 'purpose' => $purpose], JSON_THROW_ON_ERROR);
        }
        $headers = [
            'Accept' => 'application/json', 'X-Internal-Service' => config('company_security.service_name'),
            'X-Company-ID' => $companyId, 'X-Request-ID' => $request->header('X-Request-ID'),
            'X-Internal-Timestamp' => (string) now()->timestamp, 'X-Internal-Nonce' => Str::random(32),
            'Cookie' => $cookieName.'='.rawurlencode($cookie),
        ];
        if (! $read) {
            $headers['Content-Type'] = 'application/json';
            $headers['X-XSRF-TOKEN'] = $request->header('X-XSRF-TOKEN');
        }
        $headers['X-Internal-Signature'] = hash_hmac('sha256', $this->signature->canonical($method, $target, $headers, $body),
            file_get_contents(config('company_security.signing_key_file')));
        try {
            $client = Http::withOptions([
                'verify' => config('company_security.ca_certificate_file'),
                'cert' => config('company_security.client_certificate_file'),
                'ssl_key' => config('company_security.client_key_file'),
                'connect_timeout' => config('company_security.connect_timeout_seconds'),
                'timeout' => config('company_security.timeout_seconds'), 'allow_redirects' => false,
            ])->withHeaders($headers);
            $url = rtrim(config('company_security.auth_url'), '/').$target;
            $response = $read ? $client->get($url) : $client->withBody($body, 'application/json')->post($url);
        } catch (ConnectionException) {
            throw new SecurityFailure('AUTHORIZATION_UNAVAILABLE');
        }
        $payload = $response->json();
        if ($response->status() !== 200) {
            $this->rejectResponse($response, $payload, $request->header('X-Request-ID'));
        }
        $expectedKeys = ['schemaVersion', 'subject', 'companyId', 'membershipId', 'authorizationVersion', 'permissions', 'purpose', 'requestId', 'allowed'];
        if (! is_array($payload) || count($payload) !== count($expectedKeys) || array_diff($expectedKeys, array_keys($payload)) !== []
            || $payload['schemaVersion'] !== 2 || $payload['allowed'] !== true
            || ! is_string($payload['subject']) || ! preg_match('/^[1-9][0-9]{0,19}$/D', $payload['subject'])
            || $payload['companyId'] !== $companyId || $payload['requestId'] !== $request->header('X-Request-ID')
            || ! is_string($payload['membershipId']) || ! Str::isUuid($payload['membershipId'])
            || ! is_int($payload['authorizationVersion']) || $payload['authorizationVersion'] < 1 || $payload['authorizationVersion'] > 9007199254740991
            || $payload['purpose'] !== $purpose || ! is_array($payload['permissions']) || ! array_is_list($payload['permissions'])
            || $payload['permissions'] !== $permissions) {
            throw new SecurityFailure('DEPENDENCY_INVALID_RESPONSE');
        }
        $cookies = $this->cookies($response);
        $request->attributes->set('company_auth_cookies', $cookies);

        return new CompanyActor($payload['subject'], $companyId, $payload['membershipId'], $payload['authorizationVersion'], $permissions, $payload['requestId']);
    }

    private function validateConfiguration(): void
    {
        $url = config('company_security.auth_url');
        $parts = is_string($url) ? parse_url($url) : false;
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw new SecurityFailure('AUTHORIZATION_UNAVAILABLE');
        }
        foreach (['ca_certificate_file', 'client_certificate_file', 'client_key_file', 'signing_key_file'] as $setting) {
            $file = config('company_security.'.$setting);
            if (! is_string($file) || ! is_file($file) || ! is_readable($file)) {
                throw new SecurityFailure('AUTHORIZATION_UNAVAILABLE');
            }
        }
        if (strlen((string) file_get_contents(config('company_security.signing_key_file'))) < 32
            || config('company_security.connect_timeout_seconds') <= 0 || config('company_security.timeout_seconds') <= 0) {
            throw new SecurityFailure('AUTHORIZATION_UNAVAILABLE');
        }
    }

    private function rejectResponse(Response $response, mixed $payload, string $requestId): never
    {
        $code = is_array($payload) ? ($payload['error']['code'] ?? null) : null;
        $allowedErrors = [401 => ['UNAUTHENTICATED'], 403 => ['FORBIDDEN', 'COMPANY_ACCESS_DENIED', 'RECENT_AUTHENTICATION_REQUIRED'], 419 => ['CSRF_TOKEN_MISMATCH']];
        if (is_string($code) && in_array($code, $allowedErrors[$response->status()] ?? [], true)
            && ($payload['error']['requestId'] ?? null) === $requestId) {
            throw new SecurityFailure($code, $response->status());
        }
        throw new SecurityFailure('AUTHORIZATION_UNAVAILABLE');
    }

    /** @return list<Cookie> */
    private function cookies(Response $response): array
    {
        $cookies = [];
        foreach ($response->toPsrResponse()->getHeader('Set-Cookie') as $header) {
            try {
                $cookie = Cookie::fromString($header);
            } catch (\InvalidArgumentException) {
                throw new SecurityFailure('DEPENDENCY_INVALID_RESPONSE');
            }
            if (! in_array($cookie->getName(), [config('company_security.session_cookie'), 'XSRF-TOKEN'], true)
                || $cookie->getPath() !== '/' || $cookie->getDomain() !== null || ! $cookie->isSecure()
                || $cookie->getSameSite() !== 'lax'
                || ($cookie->getName() === config('company_security.session_cookie') && ! $cookie->isHttpOnly())) {
                throw new SecurityFailure('DEPENDENCY_INVALID_RESPONSE');
            }
            $cookies[] = $cookie;
        }

        return $cookies;
    }
}
