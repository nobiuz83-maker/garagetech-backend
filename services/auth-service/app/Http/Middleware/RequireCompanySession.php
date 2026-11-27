<?php

namespace App\Http\Middleware;

use App\Auth\SecurityFailure;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireCompanySession
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user('web') === null) {
            throw new SecurityFailure('UNAUTHENTICATED', 401);
        }
        $startedAt = $request->session()->get('company_security.started_at');
        $lifetime = config('company_security.absolute_session_seconds');
        if (! is_int($startedAt) || ! is_int($lifetime) || $lifetime <= 0 || $startedAt > now()->timestamp
            || now()->timestamp - $startedAt >= $lifetime) {
            throw new SecurityFailure('UNAUTHENTICATED', 401);
        }

        return $next($request);
    }
}
