<?php

namespace App\Services;

use App\Auth\CompanyActor;
use App\Auth\SecurityFailure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class CompanyProjection
{
    public function requireActive(CompanyActor $actor): void
    {
        try {
            $company = DB::table('company_subjects')->where('company_id', $actor->companyId)->first();
            if ($company === null || $company->status !== 'active') {
                throw new SecurityFailure('MIGRATION_NOT_READY');
            }
        } catch (QueryException) {
            throw new SecurityFailure('AUTHORIZATION_UNAVAILABLE');
        }
    }
}
