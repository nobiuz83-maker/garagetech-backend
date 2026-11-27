<?php

use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\AppointmentScheduleController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\ClientImportController;
use App\Http\Controllers\Api\VehicleController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'application' => 'ViCarTech API',
        'version' => '1.0.0',
    ]);
});

Route::middleware(['auth:sanctum', 'throttle:120,1'])->prefix('v1')->group(function (): void {
    Route::post('clients/import', ClientImportController::class)->middleware('throttle:10,1,clients-import:');
    Route::get('clients/{client}/vehicles', [VehicleController::class, 'forClient'])->name('clients.vehicles.index');
    Route::get('appointment-schedule', [AppointmentScheduleController::class, 'show']);
    Route::put('appointment-schedule', [AppointmentScheduleController::class, 'update']);
    Route::post('appointments/import', [AppointmentController::class, 'import'])->middleware('throttle:10,1,appointments-import:');
    Route::post('appointments/{appointment}/cancel', [AppointmentController::class, 'cancel']);
    Route::apiResource('appointments', AppointmentController::class);
    Route::apiResource('clients', ClientController::class);
    Route::apiResource('vehicles', VehicleController::class);
});
