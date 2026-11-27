<?php

namespace App\Services;

use App\Auth\SecurityFailure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SecurityAudit
{
    /** @param array<string, scalar|array|null> $details */
    public function record(string $action, string $companyId, ?string $subject, string $requestId, array $details = []): void
    {
        try {
            DB::table('company_security_audits')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $companyId, 'actor_subject' => $subject,
                'request_id' => $requestId, 'action' => $action,
                'details' => json_encode(['schemaVersion' => 1, ...$details], JSON_THROW_ON_ERROR),
                'occurred_at' => now(),
            ]);
        } catch (QueryException) {
            throw new SecurityFailure('AUTHORIZATION_UNAVAILABLE');
        }
    }
}
