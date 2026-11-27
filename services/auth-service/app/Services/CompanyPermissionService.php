<?php

namespace App\Services;

use App\Auth\SecurityFailure;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class CompanyPermissionService
{
    /** @return array{membershipId: string, authorizationVersion: int, permissions: list<string>} */
    public function context(User $user, string $companyId): array
    {
        return DB::transaction(function () use ($user, $companyId): array {
            $company = DB::table('companies')->where('id', $companyId)->sharedLock()->first();
            $membership = DB::table('company_memberships')->where('company_id', $companyId)->where('user_id', $user->getAuthIdentifier())->first();
            if ($company === null || $company->status !== 'active' || $membership === null || $membership->status !== 'active') {
                throw new SecurityFailure('COMPANY_ACCESS_DENIED', 403);
            }
            $catalog = config('company_permissions.permissions');
            $roles = config('company_permissions.roles');
            $granted = [];
            foreach (DB::table('company_membership_roles')->where('membership_id', $membership->id)->whereNull('revoked_at')->pluck('role_id') as $role) {
                if (! isset($roles[$role])) {
                    throw new SecurityFailure('FORBIDDEN', 403);
                }
                $granted = [...$granted, ...$roles[$role]];
            }
            $granted = array_values(array_unique([...$granted, ...DB::table('company_permission_grants')->where('membership_id', $membership->id)->whereNull('revoked_at')->pluck('permission')->all()]));
            foreach ($granted as $permission) {
                if (! isset($catalog[$permission]) || array_diff($catalog[$permission]['requires'], $granted) !== []) {
                    throw new SecurityFailure('FORBIDDEN', 403);
                }
            }

            return ['membershipId' => $membership->id, 'authorizationVersion' => (int) $company->authorization_version, 'permissions' => $granted];
        });
    }

    public function requireRecentAuthentication(mixed $confirmedAt): void
    {
        $window = config('company_security.recent_authentication_seconds');
        if (! is_int($confirmedAt) || ! is_int($window) || $window <= 0 || $confirmedAt > now()->timestamp
            || now()->timestamp - $confirmedAt > $window) {
            throw new SecurityFailure('RECENT_AUTHENTICATION_REQUIRED', 403);
        }
    }
}
