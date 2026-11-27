<?php

namespace App\Services;

use App\Auth\CompanyActor;
use App\Auth\SecurityFailure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class SensitiveOperationAuthorization
{
    public function __construct(private SecurityAudit $audit) {}

    public function authorize(CompanyActor $actor, string $permission, ?string $preparedBy): void
    {
        if (! $actor->allows($permission)) {
            throw new SecurityFailure('FORBIDDEN', 403);
        }
        // preparedBy must come from the locked source record, never from a request field.
        $distinctAuthorRequired = config('company_security.distinct_author_permissions')[$permission] ?? false;
        try {
            $policy = DB::table('company_security_policies')->where('company_id', $actor->companyId)->first();
        } catch (QueryException) {
            throw new SecurityFailure('AUTHORIZATION_UNAVAILABLE');
        }
        if ($policy !== null) {
            $data = json_decode($policy->data, true);
            if (! is_array($data) || count($data) !== 2 || (int) $policy->version < 1 || ($data['schemaVersion'] ?? null) !== 1 || ! is_array($data['distinctAuthorPermissions'] ?? null)) {
                throw new SecurityFailure('AUTHORIZATION_UNAVAILABLE');
            }
            $catalog = config('company_permissions.permissions');
            foreach ($data['distinctAuthorPermissions'] as $policyPermission => $required) {
                if (! is_string($policyPermission) || ! isset($catalog[$policyPermission]) || ! $catalog[$policyPermission]['mutating']
                    || ! str_starts_with($policyPermission, config('company_security.permission_prefix').'.') || ! is_bool($required)) {
                    throw new SecurityFailure('AUTHORIZATION_UNAVAILABLE');
                }
            }
            $distinctAuthorRequired = $data['distinctAuthorPermissions'][$permission] ?? $distinctAuthorRequired;
        }
        if (! is_bool($distinctAuthorRequired)) {
            throw new SecurityFailure('AUTHORIZATION_UNAVAILABLE');
        }
        if ($distinctAuthorRequired && ($preparedBy === null || $actor->subject === $preparedBy)) {
            $this->audit->record('sensitive.authorization.denied', $actor->companyId, $actor->subject, $actor->requestId, ['permission' => $permission]);
            throw new SecurityFailure('SEPARATION_OF_DUTIES_REQUIRED', 403);
        }
        $this->audit->record('sensitive.authorization.granted', $actor->companyId, $actor->subject, $actor->requestId, ['permission' => $permission, 'preparedBy' => $preparedBy]);
    }
}
