<?php

use App\Http\Controllers\Api\CurrentUserController;
use App\Http\Controllers\Internal\CompanySessionValidationController;
use App\Http\Controllers\Internal\SessionValidationController;
use App\Http\Middleware\VerifyInternalServiceRequest;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn (): array => ['service' => 'auth-service', 'status' => 'ok']);
Route::get('/user', CurrentUserController::class)->middleware('auth:sanctum');

Route::middleware(['web', VerifyInternalServiceRequest::class, 'auth:web', 'throttle:120,1'])
    ->prefix('internal/v1')
    ->group(function (): void {
        Route::get('session/validate', SessionValidationController::class);
        Route::post('session/validate', SessionValidationController::class);
    });

// V2 remains unavailable until an isolated deployment is explicitly configured for it.
if (config('company_security.enabled')) {
    Route::middleware(['company.boundary', 'company.machine:session.validate', 'web', 'company.session'])
        ->prefix('internal/v2')->group(function (): void {
            Route::match(['GET', 'POST'], 'session/validate', CompanySessionValidationController::class);
        });
}
