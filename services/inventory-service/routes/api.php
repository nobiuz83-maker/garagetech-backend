<?php

use App\Http\Controllers\Api\FreezeInterventionInventoryController;
use App\Http\Controllers\Api\InterventionPartsController;
use App\Http\Controllers\Api\InventoryProductController;
use App\Http\Controllers\Api\PreviewInterventionInventoryController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn (): array => ['service' => 'inventory-service', 'status' => 'ok']);

Route::middleware(['company.boundary', 'company.machine:inventory.invoice.freeze', 'company.permission:inventory.stock.consume'])
    ->post('internal/v1/inventory/interventions/{intervention}/freeze', FreezeInterventionInventoryController::class);
Route::middleware(['company.boundary', 'company.machine:inventory.invoice.freeze', 'company.permission:inventory.stock.consume'])
    ->post('internal/v1/inventory/interventions/{intervention}/preview', PreviewInterventionInventoryController::class);

Route::middleware(['company.boundary', 'company.permission:inventory.read'])->prefix('v1')->group(function (): void {
    Route::get('inventory/products', [InventoryProductController::class, 'index']);
    Route::get('inventory/products/{product}', [InventoryProductController::class, 'show']);
    Route::get('inventory/products/{product}/movements', [InventoryProductController::class, 'movements']);
    Route::get('inventory/interventions/{intervention}/lines', [InterventionPartsController::class, 'index']);
});
Route::middleware(['company.boundary', 'company.permission:inventory.stock.consume'])->prefix('v1')->group(function (): void {
    Route::post('inventory/interventions/{intervention}/lines', [InterventionPartsController::class, 'store']);
});
Route::middleware(['company.boundary', 'company.permission:inventory.catalog.manage'])->prefix('v1')->group(function (): void {
    Route::post('inventory/products', [InventoryProductController::class, 'store']);
    Route::put('inventory/products/{product}', [InventoryProductController::class, 'update']);
});
Route::middleware(['company.boundary', 'company.permission:inventory.stock.adjust'])->prefix('v1')->group(function (): void {
    Route::post('inventory/products/{product}/adjustments', [InventoryProductController::class, 'adjust']);
    Route::post('inventory/interventions/{intervention}/lines/{line}/reverse', [InterventionPartsController::class, 'reverse']);
});
