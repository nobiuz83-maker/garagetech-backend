<?php

use App\Http\Controllers\Api\CompanyCommercialSettingsController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Internal\InterventionInvoiceStatusController;
use Illuminate\Support\Facades\Route;
use App\Auth\CompanyActor;
use App\Services\CompanyCommercialSettings;
use Illuminate\Http\Request;

Route::get('/health', fn (): array => ['service' => 'billing-service', 'status' => 'ok']);

Route::middleware(['company.boundary', 'company.machine:billing.invoice.check'])
    ->get('internal/v1/billing/interventions/{intervention}/status', InterventionInvoiceStatusController::class);

Route::middleware(['company.boundary', 'company.permission:billing.read'])->prefix('v1')->group(function (): void {
    Route::get('invoices/company-settings', [CompanyCommercialSettingsController::class, 'show']);
    Route::get('company-logo/{company}', function (Request $request, string $company, CompanyCommercialSettings $settings) {
        /** @var CompanyActor $actor */
        $actor = $request->user();
        abort_unless(hash_equals($actor->companyId, $company), 404);
        $logo = $settings->logoFile($actor->companyId);
        abort_if($logo === null, 404);
        return response($logo['contents'], 200, ['Content-Type' => $logo['mime'], 'Cache-Control' => 'private, max-age=300', 'X-Content-Type-Options' => 'nosniff']);
    });
    Route::get('invoices', [InvoiceController::class, 'index']);
    Route::get('invoices/{invoice}', [InvoiceController::class, 'show']);
});
Route::middleware(['company.boundary', 'company.permission:billing.settings.manage'])->prefix('v1')->group(function (): void {
    Route::put('invoices/company-settings', [CompanyCommercialSettingsController::class, 'update']);
});
Route::middleware(['company.boundary', 'company.permission:billing.invoices.issue'])->prefix('v1')->group(function (): void {
    Route::post('invoices/preview', [InvoiceController::class, 'preview']);
    Route::post('invoices', [InvoiceController::class, 'store']);
});
Route::middleware(['company.boundary', 'company.permission:billing.payments.record'])->prefix('v1')->group(function (): void {
    Route::post('invoices/{invoice}/payments', [InvoiceController::class, 'payment']);
});
