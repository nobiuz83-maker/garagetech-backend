<?php

namespace App\Services;

use App\Auth\CompanyActor;
use App\Auth\SecurityFailure;
use Closure;
use Illuminate\Support\Facades\DB;

final class IdempotentInventoryOperation
{
    /** @param Closure(): array<string, mixed> $operation
     * @return array<string, mixed>
     */
    public function execute(CompanyActor $actor, string $operationName, string $key, string $body, Closure $operation): array
    {
        return DB::transaction(function () use ($actor, $operationName, $key, $body, $operation): array {
            $company = DB::table('company_subjects')->where('company_id', $actor->companyId)->lockForUpdate()->first();
            if ($company === null || $company->status !== 'active') {
                throw new SecurityFailure('MIGRATION_NOT_READY');
            }
            $identity = ['company_id' => $actor->companyId, 'actor_subject' => $actor->subject, 'operation' => $operationName, 'idempotency_key' => $key];
            $requestHash = hash('sha256', $body);
            $existing = DB::table('inventory_idempotency_keys')->where($identity)->first();
            if ($existing !== null) {
                if (! hash_equals($existing->request_hash, $requestHash)) {
                    throw new SecurityFailure('IDEMPOTENCY_CONFLICT', 409);
                }

                return json_decode($existing->response, true, 512, JSON_THROW_ON_ERROR);
            }
            $response = $operation();
            DB::table('inventory_idempotency_keys')->insert([...$identity, 'request_hash' => $requestHash, 'response' => json_encode($response, JSON_THROW_ON_ERROR), 'created_at' => now()]);

            return $response;
        });
    }
}
