<?php

namespace App\Services;

use App\Auth\CompanyActor;
use App\Auth\SecurityFailure;
use Closure;
use Illuminate\Support\Facades\DB;

final class IdempotentInterventionOperation
{
    /**
     * @param  Closure(): array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    public function execute(CompanyActor $actor, string $operationName, string $key, string $requestBody, Closure $operation): array
    {
        if (! preg_match('/^[A-Za-z0-9._:-]{16,128}$/D', $key) || ! preg_match('/^[a-z][a-z0-9._-]{0,63}$/D', $operationName)) {
            throw new SecurityFailure('IDEMPOTENCY_KEY_REQUIRED', 400);
        }

        return DB::transaction(function () use ($actor, $operationName, $key, $requestBody, $operation): array {
            // Serialize commands per company so concurrent retries cannot both execute the mutation.
            $company = DB::table('company_subjects')->where('company_id', $actor->companyId)->lockForUpdate()->first();
            if ($company === null || $company->status !== 'active') {
                throw new SecurityFailure('MIGRATION_NOT_READY');
            }
            $identity = [
                'company_id' => $actor->companyId,
                'actor_subject' => $actor->subject,
                'operation' => $operationName,
                'idempotency_key' => $key,
            ];
            $requestHash = hash('sha256', $requestBody);
            $existing = DB::table('intervention_idempotency_keys')->where($identity)->first();
            if ($existing !== null) {
                if (! hash_equals($existing->request_hash, $requestHash)) {
                    throw new SecurityFailure('IDEMPOTENCY_CONFLICT', 409);
                }

                return json_decode($existing->response, true, 512, JSON_THROW_ON_ERROR);
            }

            $response = $operation();
            DB::table('intervention_idempotency_keys')->insert([
                ...$identity,
                'request_hash' => $requestHash,
                'response' => json_encode($response, JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);

            return $response;
        });
    }
}
