<?php

namespace App\Services;

use App\Auth\IdentityVerification;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;
use Throwable;

class RemoteIdentityVerifier
{
    public function verify(Request $request, string $permission): IdentityVerification
    {
        $sessionCookieName = config('remote_auth.session_cookie');
        $sessionCookie = $request->cookies->get($sessionCookieName);
        if (! is_string($sessionCookie) || $sessionCookie === '') {
            return IdentityVerification::failed(401);
        }

        if (! in_array($permission, config('remote_auth.permissions'), true) || ! $this->hasValidConfiguration()) {
            return IdentityVerification::failed(503);
        }

        $isRead = $request->isMethod('GET') || $request->isMethod('HEAD');
        $method = $isRead ? 'GET' : 'POST';
        $requestTarget = '/api/internal/v1/session/validate';
        $body = '';
        if ($isRead) {
            $requestTarget .= '?permission='.rawurlencode($permission);
        } else {
            $body = json_encode(['permission' => $permission], JSON_THROW_ON_ERROR);
        }

        $timestamp = (string) now()->timestamp;
        $nonce = Str::random(32);
        $canonicalRequest = implode("\n", [
            $method,
            $requestTarget,
            $timestamp,
            $nonce,
            hash('sha256', $body),
        ]);
        $signingKey = file_get_contents(config('remote_auth.signing_key_file'));
        if (! is_string($signingKey) || strlen($signingKey) < 32) {
            return IdentityVerification::failed(503);
        }

        $signature = hash_hmac('sha256', $canonicalRequest, $signingKey);
        $headers = [
            'Accept' => 'application/json',
            'Cookie' => $sessionCookieName.'='.rawurlencode($sessionCookie),
            'X-Internal-Service' => config('remote_auth.service_name'),
            'X-Internal-Timestamp' => $timestamp,
            'X-Internal-Nonce' => $nonce,
            'X-Internal-Signature' => $signature,
        ];
        if (! $isRead && $request->hasHeader('X-XSRF-TOKEN')) {
            $headers['X-XSRF-TOKEN'] = $request->header('X-XSRF-TOKEN');
        }

        try {
            $client = $this->httpClient()->withHeaders($headers);
            $url = rtrim(config('remote_auth.url'), '/').$requestTarget;
            $response = $isRead
                ? $client->get($url)
                : $client->withBody($body, 'application/json')->post($url);
        } catch (Throwable $exception) {
            Log::warning('AuthService validation request failed.', ['exception' => $exception::class]);

            return IdentityVerification::failed(503);
        }

        return $this->interpretResponse($response, $permission);
    }

    private function hasValidConfiguration(): bool
    {
        $url = config('remote_auth.url');
        if (! is_string($url) || ! str_starts_with($url, 'https://')) {
            return false;
        }

        foreach (['ca_certificate_file', 'client_certificate_file', 'client_key_file', 'signing_key_file'] as $setting) {
            $file = config("remote_auth.{$setting}");
            if (! is_string($file) || ! is_readable($file)) {
                return false;
            }
        }

        return strlen((string) file_get_contents(config('remote_auth.signing_key_file'))) >= 32;
    }

    private function httpClient(): PendingRequest
    {
        return Http::withOptions([
            'verify' => config('remote_auth.ca_certificate_file'),
            'cert' => config('remote_auth.client_certificate_file'),
            'ssl_key' => config('remote_auth.client_key_file'),
            'connect_timeout' => 0.5,
            'timeout' => 2,
            'allow_redirects' => false,
        ]);
    }

    private function interpretResponse(Response $response, string $permission): IdentityVerification
    {
        $cookies = [];
        foreach ($response->header('Set-Cookie') === '' ? [] : $response->toPsrResponse()->getHeader('Set-Cookie') as $header) {
            try {
                $cookie = Cookie::fromString($header);
            } catch (Throwable) {
                return IdentityVerification::failed(503);
            }

            if (! in_array($cookie->getName(), [config('remote_auth.session_cookie'), 'XSRF-TOKEN'], true)
                || $cookie->getPath() !== '/'
                || $cookie->getDomain() !== null
                || ($cookie->getName() === config('remote_auth.session_cookie') && ! $cookie->isHttpOnly())) {
                return IdentityVerification::failed(503);
            }

            $cookies[] = $header;
        }

        if ($response->status() === 401 && in_array($response->json('error'), [
            'UNAUTHORIZED_CALLER', 'INVALID_REQUEST_SIGNATURE', 'MUTUAL_TLS_REQUIRED', 'REPLAYED_REQUEST',
        ], true)) {
            return IdentityVerification::failed(503);
        }

        if (in_array($response->status(), [401, 403, 419], true)) {
            return IdentityVerification::failed($response->status(), $cookies);
        }

        if ($response->status() !== 200) {
            return IdentityVerification::failed(503);
        }

        $payload = $response->json();
        $subject = is_array($payload) ? ($payload['subject'] ?? null) : null;
        if (! is_string($subject)
            || ! preg_match('/^[1-9][0-9]{0,19}$/', $subject)
            || ($payload['permission'] ?? null) !== $permission
            || ($payload['allowed'] ?? null) !== true) {
            return IdentityVerification::failed(503);
        }

        return IdentityVerification::authenticated($subject, $cookies);
    }
}
