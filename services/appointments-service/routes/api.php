<?php

use App\Auth\AuthenticatedActor;
use App\Http\Controllers\Api\AppointmentAvailabilityController;
use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\AppointmentNotificationController;
use App\Http\Controllers\Api\AppointmentScheduleController;
use App\Http\Controllers\Api\PublicAppointmentCancellationController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn (): array => ['service' => 'appointments-service', 'status' => 'ok']);
Route::prefix('v1/public/appointment-cancellations')->middleware('throttle:30,1')->group(function (): void {
    Route::get('{token}', [PublicAppointmentCancellationController::class, 'show']);
    Route::post('{token}', [PublicAppointmentCancellationController::class, 'cancel'])->middleware('throttle:10,1');
});

Route::get('/internal/identity-check', function (Request $request): array {
    /** @var AuthenticatedActor $actor */
    $actor = $request->attributes->get('authenticated_actor');

    return ['subject' => $actor->subject];
})->middleware('remote.identity:appointments.read');

Route::post('/internal/identity-check', function (Request $request): array {
    /** @var AuthenticatedActor $actor */
    $actor = $request->attributes->get('authenticated_actor');

    return ['subject' => $actor->subject];
})->middleware('remote.identity:appointments.write');

Route::middleware(['company.boundary', 'company.permission:appointments.read'])->prefix('v1')->group(function (): void {
    Route::get('appointments', [AppointmentController::class, 'index']);
    Route::get('appointments/{appointment}', [AppointmentController::class, 'show']);
    Route::get('appointment-schedule', [AppointmentScheduleController::class, 'show']);
    Route::get('appointment-availability', AppointmentAvailabilityController::class);
    Route::get('appointment-notification-settings', [AppointmentNotificationController::class, 'settings']);
    Route::get('appointment-notification-providers', [AppointmentNotificationController::class, 'providers']);
    Route::get('appointments/{appointment}/notifications', [AppointmentNotificationController::class, 'history']);
});
Route::middleware(['company.boundary', 'company.permission:appointments.write'])->prefix('v1')->group(function (): void {
    Route::post('appointments', [AppointmentController::class, 'store']);
    Route::put('appointments/{appointment}', [AppointmentController::class, 'update']);
    Route::post('appointments/import', [AppointmentController::class, 'import'])->middleware('throttle:10,1');
    Route::post('appointments/{appointment}/notifications/retry', [AppointmentNotificationController::class, 'retry']);
});
Route::middleware(['company.boundary', 'company.permission:appointments.cancel'])->prefix('v1')->group(function (): void {
    Route::post('appointments/{appointment}/cancel', [AppointmentController::class, 'cancel']);
});
Route::middleware(['company.boundary', 'company.permission:appointments.archive'])->prefix('v1')->group(function (): void {
    Route::delete('appointments/{appointment}', [AppointmentController::class, 'destroy']);
});
Route::middleware(['company.boundary', 'company.permission:appointments.settings.manage'])->prefix('v1')->group(function (): void {
    Route::put('appointment-schedule', [AppointmentScheduleController::class, 'update']);
    Route::put('appointment-notification-settings', [AppointmentNotificationController::class, 'updateSettings']);
    Route::post('appointment-notification-test', [AppointmentNotificationController::class, 'sendTest'])->middleware('throttle:3,1');
});
