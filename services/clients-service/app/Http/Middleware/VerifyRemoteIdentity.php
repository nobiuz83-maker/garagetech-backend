<?php

namespace App\Http\Middleware;

use App\Auth\AuthenticatedActor;
use App\Services\RemoteIdentityVerifier;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class VerifyRemoteIdentity
{
    public function __construct(private readonly RemoteIdentityVerifier $identityVerifier) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        Auth::forgetGuards();
        $verification = $this->identityVerifier->verify($request, $permission);
        if ($verification->actor === null) {
            $status = $verification->failureStatus ?? 503;
            $error = match ($status) {
                401 => 'UNAUTHENTICATED',
                403 => 'FORBIDDEN',
                419 => 'CSRF_FAILED',
                default => 'AUTH_SERVICE_UNAVAILABLE',
            };

            $response = response()->json(['error' => $error], $status);
        } else {
            $request->attributes->set('authenticated_actor', $verification->actor);
            $request->setUserResolver(fn (): AuthenticatedActor => $verification->actor);
            try {
                $response = $next($request);
            } finally {
                Auth::forgetGuards();
            }
        }

        $response->headers->set('Cache-Control', 'no-store');
        foreach ($verification->cookies as $cookie) {
            $response->headers->set('Set-Cookie', $cookie, false);
        }

        return $response;
    }
}
