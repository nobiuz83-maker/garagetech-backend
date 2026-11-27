<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class VerifyInternalServiceRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $serviceName = $request->header('X-Internal-Service');
        $timestamp = $request->header('X-Internal-Timestamp');
        $nonce = $request->header('X-Internal-Nonce');
        $signature = $request->header('X-Internal-Signature');
        $callers = config('internal_auth.callers');
        if (! is_string($serviceName) || ! array_key_exists($serviceName, $callers)) {
            return response()->json(['error' => 'UNAUTHORIZED_CALLER'], 401);
        }

        $keyPath = $callers[$serviceName]['key_file'];
        if (! is_string($keyPath) || ! is_readable($keyPath)) {
            return response()->json(['error' => 'INTERNAL_AUTH_UNAVAILABLE'], 503);
        }

        if (! ctype_digit((string) $timestamp)
            || abs(now()->timestamp - (int) $timestamp) > config('internal_auth.clock_skew_seconds')
            || ! is_string($nonce)
            || ! preg_match('/^[A-Za-z0-9_-]{20,80}$/', $nonce)
            || ! is_string($signature)
            || ! preg_match('/^[a-f0-9]{64}$/', $signature)) {
            return response()->json(['error' => 'INVALID_REQUEST_SIGNATURE'], 401);
        }

        if (config('internal_auth.require_mtls')
            && (! $request->isSecure()
                || $request->server('SSL_CLIENT_VERIFY') !== 'SUCCESS'
                || $request->server('SSL_CLIENT_S_DN_CN') !== $serviceName)) {
            return response()->json(['error' => 'MUTUAL_TLS_REQUIRED'], 401);
        }

        $signingKey = file_get_contents($keyPath);
        if ($signingKey === false || strlen($signingKey) < 32) {
            return response()->json(['error' => 'INTERNAL_AUTH_UNAVAILABLE'], 503);
        }

        $canonicalRequest = implode("\n", [
            $request->method(),
            $request->getRequestUri(),
            $timestamp,
            $nonce,
            hash('sha256', $request->getContent()),
        ]);
        $expectedSignature = hash_hmac('sha256', $canonicalRequest, $signingKey);

        if (! hash_equals($expectedSignature, $signature)) {
            return response()->json(['error' => 'INVALID_REQUEST_SIGNATURE'], 401);
        }

        $nonceKey = "internal-auth:{$serviceName}:{$nonce}";
        if (! Cache::store('database')->add($nonceKey, true, config('internal_auth.nonce_lifetime_seconds'))) {
            return response()->json(['error' => 'REPLAYED_REQUEST'], 401);
        }

        $request->attributes->set('internal_service_name', $serviceName);

        return $next($request);
    }
}
