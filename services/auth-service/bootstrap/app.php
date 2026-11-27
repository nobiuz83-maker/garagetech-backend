<?php

use App\Auth\SecurityFailure;
use App\Http\Middleware\CompanySecurityBoundary;
use App\Http\Middleware\PreventAuthenticationCaching;
use App\Http\Middleware\RequireCompanySession;
use App\Http\Middleware\VerifyCompanyMachine;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(PreventAuthenticationCaching::class);
        $middleware->alias([
            'company.boundary' => CompanySecurityBoundary::class,
            'company.machine' => VerifyCompanyMachine::class,
            'company.session' => RequireCompanySession::class,
        ]);
        $middleware->statefulApi();
        $middleware->redirectGuestsTo(fn (): null => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (LockTimeoutException|QueryException $exception): bool {
            // SQL exception messages may include the session payload; record only the failure class.
            Log::warning('AuthService storage or session lock unavailable.', ['exception' => $exception::class]);

            return false;
        });
        $exceptions->render(function (LockTimeoutException|QueryException $exception): JsonResponse {
            return response()->json(['error' => 'AUTH_SERVICE_UNAVAILABLE'], 503)
                ->header('Cache-Control', 'no-store');
        });
        $exceptions->dontReport([SecurityFailure::class]);
        $exceptions->render(function (SecurityFailure $failure, Request $request) {
            $requestId = $request->header('X-Request-ID', '');
            $requestId = Str::isUuid($requestId) ? $requestId : (string) Str::uuid();

            return response()->json(['error' => ['code' => $failure->errorCode,
                'messageKey' => 'errors.'.$failure->errorCode, 'requestId' => $requestId]], $failure->status)
                ->header('Cache-Control', 'no-store, private')->header('X-Request-ID', $requestId);
        });
        $exceptions->render(function (TokenMismatchException $exception, Request $request) {
            if (! $request->is('api/internal/v2/*', 'api/v2/*')) {
                return null;
            }
            $requestId = $request->header('X-Request-ID', '');
            $requestId = Str::isUuid($requestId) ? $requestId : (string) Str::uuid();

            return response()->json(['error' => ['code' => 'CSRF_TOKEN_MISMATCH', 'messageKey' => 'errors.CSRF_TOKEN_MISMATCH', 'requestId' => $requestId]], 419)
                ->header('Cache-Control', 'no-store, private');
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
