<?php

use App\Auth\CompanyActor;
use App\Auth\SecurityFailure;
use App\Policies\CompanyResourceAccess;
use App\Services\SecurityAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

// This file is mounted only by the isolated integration overlay; production never requires it.
if (app()->environment() !== 'integration' || getenv('VICARTECH_SYNTHETIC_INTEGRATION') !== 'true') {
    throw new LogicException('Security probes require the isolated synthetic integration environment.');
}
$prefix = config('company_security.permission_prefix');
$writePermission = match ($prefix) {
    'inventory' => 'inventory.stock.adjust', 'interventions' => 'interventions.write',
    'billing' => 'billing.invoices.write', 'accounting' => 'accounting.bills.write',
};
$identity = function (Request $request): array {
    /** @var CompanyActor $actor */
    $actor = $request->attributes->get('company_actor');
    $policy = DB::table('company_security_policies')->where('company_id', $actor->companyId)->first();

    return ['data' => ['subject' => $actor->subject, 'companyId' => $actor->companyId,
        'requestId' => $actor->requestId, 'authorizationVersion' => $actor->authorizationVersion, 'permissions' => $actor->permissions,
        'policyVersion' => (int) $policy->version, 'service' => config('company_security.service_name')]];
};
Route::middleware(['company.boundary', 'company.permission:'.$prefix.'.read'])->get('integration/v2/access', $identity);
Route::middleware(['company.boundary', 'company.permission:'.$prefix.'.read'])->get('integration/v2/resources/{companyId}', function (Request $request, string $companyId) use ($identity, $prefix): array {
    app(CompanyResourceAccess::class)->authorize($request->user(), $companyId, $prefix.'.read');

    return $identity($request);
});
$additionalRead = match ($prefix) {
    'inventory' => 'inventory.valuation.read', 'interventions' => 'interventions.pricing.read',
    'billing' => 'billing.read', 'accounting' => 'accounting.reports.read',
};
Route::middleware(['company.boundary', 'company.permission:'.$additionalRead])->get('integration/v2/financial-read', $identity);
Route::middleware(['company.boundary', 'company.permission:'.$writePermission])->put('integration/v2/security-policy', function (Request $request) use ($writePermission) {
    if (! preg_match('/^"v([1-9][0-9]*)"$/D', $request->header('If-Match', ''), $matches)) {
        throw new SecurityFailure('PRECONDITION_REQUIRED', 428);
    }
    return DB::transaction(function () use ($request, $writePermission, $matches) {
        $actor = $request->user();
        $policy = DB::table('company_security_policies')->where('company_id', $actor->companyId)->lockForUpdate()->first();
        if ((int) $policy->version !== (int) $matches[1]) {
            throw new SecurityFailure('VERSION_CONFLICT', 412);
        }
        $version = (int) $policy->version + 1;
        DB::table('company_security_policies')->where('company_id', $actor->companyId)->update(['version' => $version]);
        app(SecurityAudit::class)->record('integration.policy.changed', $actor->companyId, $actor->subject, $actor->requestId, ['version' => $version]);

        return response()->json(['data' => ['version' => $version]])->header('ETag', '"v'.$version.'"');
    });
});
Route::middleware(['company.boundary', 'company.machine:integration.identity', 'company.permission:'.$prefix.'.read'])
    ->get('internal/integration/v2/identity', $identity);

$sensitivePermission = match ($prefix) {
    'billing' => 'billing.refunds.record', 'accounting' => 'accounting.tax_policies.manage',
    'inventory' => 'inventory.stock.adjust', 'interventions' => 'interventions.pricing.manage',
};
Route::middleware(['company.boundary', 'company.permission:'.$sensitivePermission])->post('integration/v2/sensitive', $identity);
