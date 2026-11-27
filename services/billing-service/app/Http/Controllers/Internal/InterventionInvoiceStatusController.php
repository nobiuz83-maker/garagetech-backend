<?php

namespace App\Http\Controllers\Internal;

use App\Auth\SecurityFailure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class InterventionInvoiceStatusController
{
    public function __invoke(Request $request, string $intervention): JsonResponse
    {
        if (! Str::isUuid($intervention)) {
            throw new SecurityFailure('RESOURCE_NOT_FOUND', 404);
        }
        $companyId = $request->header('X-Company-ID');
        $active = DB::table('company_subjects')->where('company_id', $companyId)->where('status', 'active')->exists();
        if (! $active) {
            throw new SecurityFailure('MIGRATION_NOT_READY');
        }

        return response()->json(['data' => [
            'schemaVersion' => 1, 'interventionId' => $intervention,
            'invoiced' => DB::table('billing_invoices')->where('company_id', $companyId)->where('intervention_id', $intervention)->exists(),
        ]]);
    }
}
