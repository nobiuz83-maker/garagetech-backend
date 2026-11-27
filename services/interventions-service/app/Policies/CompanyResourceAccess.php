<?php

namespace App\Policies;

use App\Auth\CompanyActor;
use App\Auth\SecurityFailure;
use Illuminate\Database\Eloquent\Builder;

final class CompanyResourceAccess
{
    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function scope(CompanyActor $actor, Builder $query): Builder
    {
        return $query->where($query->getModel()->qualifyColumn('company_id'), $actor->companyId);
    }

    public function authorize(CompanyActor $actor, string $companyId, string $permission): void
    {
        if ($actor->companyId !== $companyId) {
            throw new SecurityFailure('RESOURCE_NOT_FOUND', 404);
        }
        if (! $actor->allows($permission)) {
            throw new SecurityFailure('FORBIDDEN', 403);
        }
    }
}
