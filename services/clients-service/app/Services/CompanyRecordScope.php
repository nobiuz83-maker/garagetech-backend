<?php

namespace App\Services;

use App\Auth\CompanyActor;
use App\Models\Client;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class CompanyRecordScope
{
    /** @param Builder<Client> $query */
    public function clients(Builder $query, CompanyActor $actor): Builder
    {
        return $query->where('user_id', $actor->subject)->where(function (Builder $companyQuery) use ($actor): void {
            $companyQuery->where('company_id', $actor->companyId)
                ->orWhere(function (Builder $legacyQuery) use ($actor): void {
                    $legacyQuery->whereNull('company_id')->whereIn('id', DB::table('legacy_client_company_assignments')
                        ->select('client_id')->where('company_id', $actor->companyId));
                });
        });
    }

    public function canAccessClient(CompanyActor $actor, Client $client): bool
    {
        if ((string) $client->user_id !== $actor->subject) {
            return false;
        }
        if ($client->company_id === $actor->companyId) {
            return true;
        }

        return $client->company_id === null && DB::table('legacy_client_company_assignments')
            ->where('client_id', $client->id)->where('company_id', $actor->companyId)->exists();
    }
}
