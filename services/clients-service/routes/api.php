<?php

use App\Auth\AuthenticatedActor;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\ClientImportController;
use App\Http\Controllers\Api\ClientRestoreController;
use App\Http\Controllers\Api\VehicleController;
use App\Http\Controllers\Internal\AppointmentReferenceController;
use App\Http\Middleware\VerifyInternalServiceRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn (): array => ['service' => 'clients-service', 'status' => 'ok']);

Route::get('/internal/identity-check', function (Request $request): array {
    /** @var AuthenticatedActor $actor */
    $actor = $request->attributes->get('authenticated_actor');

    return ['subject' => $actor->subject];
})->middleware('remote.identity:clients.read');

Route::post('/internal/identity-check', function (Request $request): array {
    /** @var AuthenticatedActor $actor */
    $actor = $request->attributes->get('authenticated_actor');

    return ['subject' => $actor->subject];
})->middleware('remote.identity:clients.write');

Route::middleware(['company.boundary', 'company.permission:clients.read'])->prefix('v1')->group(function (): void {
    Route::get('clients', [ClientController::class, 'index']);
    Route::get('clients/{client}', [ClientController::class, 'show']);
    Route::get('clients/{client}/vehicles', [VehicleController::class, 'forClient']);
});
Route::middleware(['company.boundary', 'company.permission:vehicles.read'])->prefix('v1')->group(function (): void {
    Route::get('vehicles', [VehicleController::class, 'index']);
    Route::get('vehicles/{vehicle}', [VehicleController::class, 'show']);
});
Route::middleware(['company.boundary', 'company.permission:clients.write'])->prefix('v1')->group(function (): void {
    Route::post('clients', [ClientController::class, 'store']);
    Route::match(['PUT', 'PATCH'], 'clients/{client}', [ClientController::class, 'update']);
});
Route::middleware(['company.boundary', 'company.permission:clients.archive'])->prefix('v1')->group(function (): void {
    Route::delete('clients/{client}', [ClientController::class, 'destroy']);
});
Route::middleware(['company.boundary', 'company.permission:clients.write,vehicles.write'])->prefix('v1')->group(function (): void {
    Route::post('clients/import', ClientImportController::class)->middleware('throttle:10,1');
});
Route::middleware(['company.boundary', 'company.permission:vehicles.write'])->prefix('v1')->group(function (): void {
    Route::post('vehicles', [VehicleController::class, 'store']);
    Route::match(['PUT', 'PATCH'], 'vehicles/{vehicle}', [VehicleController::class, 'update']);
});
Route::middleware(['company.boundary', 'company.permission:vehicles.archive'])->prefix('v1')->group(function (): void {
    Route::delete('vehicles/{vehicle}', [VehicleController::class, 'destroy']);
});

Route::post('v1/clients/{client}/restore', [ClientRestoreController::class, 'client'])->withTrashed()->middleware(['company.boundary', 'company.permission:clients.restore']);
Route::post('v1/vehicles/{vehicle}/restore', [ClientRestoreController::class, 'vehicle'])->withTrashed()->middleware(['company.boundary', 'company.permission:vehicles.restore']);

Route::middleware([VerifyInternalServiceRequest::class, 'company.boundary', 'company.permission:clients.read'])->prefix('internal/v1')->group(function (): void {
    Route::post('appointment-references', [AppointmentReferenceController::class, 'store']);
    Route::get('client-vehicle-search', [AppointmentReferenceController::class, 'search']);
});

Route::get('v1/clients/{client}/archive-history', [ClientRestoreController::class, 'clientHistory'])->withTrashed()->middleware(['company.boundary', 'company.permission:clients.read']);
Route::get('v1/vehicles/{vehicle}/archive-history', [ClientRestoreController::class, 'vehicleHistory'])->withTrashed()->middleware(['company.boundary', 'company.permission:vehicles.read']);
