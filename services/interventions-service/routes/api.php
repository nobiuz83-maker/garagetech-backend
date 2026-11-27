<?php

use App\Http\Controllers\Api\InterventionController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn (): array => ['service' => 'interventions-service', 'status' => 'ok']);

Route::middleware(['company.boundary', 'company.permission:interventions.read'])
    ->prefix('v1')->group(function (): void {
        Route::get('interventions', [InterventionController::class, 'index']);
        Route::get('interventions/{intervention}', [InterventionController::class, 'show']);
    });

Route::middleware(['company.boundary', 'company.permission:interventions.write'])
    ->prefix('v1')->group(function (): void {
        Route::post('interventions', [InterventionController::class, 'store']);
        Route::put('interventions/{intervention}', [InterventionController::class, 'update']);
    });
