<?php

namespace App\Http\Middleware;

use App\Auth\SecurityFailure;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class CompanySecurityBoundary
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $request->header('X-Request-ID') ?? (string) Str::uuid();
        if (! Str::isUuid($requestId)) {
            throw new SecurityFailure('INVALID_REQUEST_ID', 400);
        }
        $request->headers->set('X-Request-ID', $requestId);
        if (! config('company_security.enabled')) {
            throw new SecurityFailure('MIGRATION_NOT_READY');
        }
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Request-ID', $requestId);

        return $response;
    }
}
