<?php

namespace App\Services;

use App\Auth\SecurityFailure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class CompanyNonceStore
{
    public function claim(string $caller, string $nonce): void
    {
        try {
            $inserted = DB::table('company_security_nonces')->insertOrIgnore([
                'caller' => $caller, 'nonce_hash' => hash('sha256', $nonce),
                'expires_at' => now()->addSeconds(config('company_security.nonce_seconds')),
            ]);
            if ($inserted !== 1) {
                throw new SecurityFailure('MACHINE_AUTHENTICATION_FAILED', 401);
            }
        } catch (QueryException) {
            throw new SecurityFailure('AUTHORIZATION_UNAVAILABLE');
        }
    }
}
