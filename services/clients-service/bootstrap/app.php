<?php

use App\Auth\SecurityFailure;
use App\Http\Middleware\CompanySecurityBoundary;
use App\Http\Middleware\RequireCompanyPermission;
use App\Http\Middleware\VerifyCompanyMachine;
use App\Http\Middleware\VerifyInternalServiceRequest;
use App\Http\Middleware\VerifyRemoteIdentity;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prependToPriorityList(SubstituteBindings::class, VerifyRemoteIdentity::class);
        $middleware->prependToPriorityList(VerifyRemoteIdentity::class, VerifyInternalServiceRequest::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, RequireCompanyPermission::class);
        $middleware->prependToPriorityList(RequireCompanyPermission::class, CompanySecurityBoundary::class);
        $middleware->alias([
            'remote.identity' => VerifyRemoteIdentity::class,
            'company.boundary' => CompanySecurityBoundary::class,
            'company.permission' => RequireCompanyPermission::class,
            'company.machine' => VerifyCompanyMachine::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (QueryException $exception): bool {
            Log::warning('ClientsService storage unavailable.', ['exception' => $exception::class]);

            return false;
        });
        $exceptions->render(function (QueryException $exception): JsonResponse {
            return response()->json(['error' => 'CLIENTS_STORAGE_UNAVAILABLE'], 503)
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
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
