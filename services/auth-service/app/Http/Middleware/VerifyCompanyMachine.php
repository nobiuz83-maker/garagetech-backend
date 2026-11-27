<?php

namespace App\Http\Middleware;

use App\Auth\SecurityFailure;
use App\Services\CompanyNonceStore;
use App\Services\CompanyRequestSignature;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class VerifyCompanyMachine
{
    public function __construct(private CompanyRequestSignature $signature, private CompanyNonceStore $nonces) {}

    public function handle(Request $request, Closure $next, string $capability): Response
    {
        $skew = config('company_security.clock_skew_seconds');
        $nonceLifetime = config('company_security.nonce_seconds');
        if (! is_int($skew) || $skew < 1 || $skew > 120 || ! is_int($nonceLifetime) || $nonceLifetime < 2 * $skew + 1) {
            throw new SecurityFailure('AUTHORIZATION_UNAVAILABLE');
        }
        $caller = $request->header('X-Internal-Service');
        $configuration = is_string($caller) ? config('company_security.callers.'.$caller) : null;
        if (! is_array($configuration) || ! in_array($capability, $configuration['capabilities'], true)
            || ! $request->isSecure() || $request->server('SSL_CLIENT_VERIFY') !== 'SUCCESS'
            || $request->server('SSL_CLIENT_S_DN_CN') !== $caller) {
            throw new SecurityFailure('MACHINE_AUTHENTICATION_FAILED', 401);
        }
        $timestamp = $request->header('X-Internal-Timestamp');
        $nonce = $request->header('X-Internal-Nonce');
        $signature = $request->header('X-Internal-Signature');
        if (! is_string($timestamp) || ! preg_match('/^[0-9]{10}$/D', $timestamp)
            || abs(now()->timestamp - (int) $timestamp) > config('company_security.clock_skew_seconds')
            || ! is_string($nonce) || ! preg_match('/^[A-Za-z0-9_-]{20,80}$/D', $nonce)
            || ! is_string($signature) || ! preg_match('/^[a-f0-9]{64}$/D', $signature)
            || ! Str::isUuid($request->header('X-Company-ID', ''))
            || ! Str::isUuid($request->header('X-Request-ID', ''))) {
            throw new SecurityFailure('MACHINE_AUTHENTICATION_FAILED', 401);
        }
        $keyPath = $configuration['key_file'];
        if (! is_string($keyPath) || ! is_readable($keyPath)
            || strlen($key = (string) file_get_contents($keyPath)) < 32) {
            throw new SecurityFailure('AUTHORIZATION_UNAVAILABLE');
        }
        if (! hash_equals(hash_hmac('sha256', $this->signature->fromRequest($request), $key), $signature)) {
            throw new SecurityFailure('MACHINE_AUTHENTICATION_FAILED', 401);
        }
        $this->nonces->claim($caller, $nonce);
        $request->attributes->set('verified_machine', $caller);

        return $next($request);
    }
}
