<?php

namespace App\Services;

use App\Auth\SecurityFailure;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CompanyMembershipManager
{
    public function __construct(private CompanyPermissionService $permissions, private SecurityAudit $audit) {}

    /**
     * No HTTP or automatic import entry point is exposed. Initial ownership requires separate approval.
     *
     * @param  list<string>  $roleIds
     * @param  list<string>  $permissionGrants
     */
    public function change(Request $request, string $companyId, string $targetSubject, array $roleIds, array $permissionGrants, string $status): string
    {
        $actor = $request->user('web');
        if (! $actor instanceof User || ! Str::isUuid($request->header('X-Request-ID', '')) || ! in_array($status, ['active', 'suspended'], true)) {
            throw new SecurityFailure('FORBIDDEN', 403);
        }
        $this->permissions->requireRecentAuthentication($request->session()->get('auth.password_confirmed_at'));

        return DB::transaction(function () use ($request, $actor, $companyId, $targetSubject, $roleIds, $permissionGrants, $status): string {
            $company = DB::table('companies')->where('id', $companyId)->lockForUpdate()->first();
            if ($company === null) {
                throw new SecurityFailure('COMPANY_ACCESS_DENIED', 403);
            }
            $context = $this->permissions->context($actor, $companyId);
            foreach (['identity.members.manage', 'identity.roles.assign'] as $required) {
                if (! in_array($required, $context['permissions'], true)) {
                    throw new SecurityFailure('FORBIDDEN', 403);
                }
            }
            $roles = config('company_permissions.roles');
            $catalog = config('company_permissions.permissions');
            $requestedPermissions = $permissionGrants;
            foreach ($roleIds as $roleId) {
                if (! is_string($roleId) || ! isset($roles[$roleId])) {
                    throw new SecurityFailure('VALIDATION_FAILED', 422);
                }
                $requestedPermissions = [...$requestedPermissions, ...$roles[$roleId]];
            }
            foreach ($requestedPermissions as $permission) {
                if (! is_string($permission) || ! isset($catalog[$permission]) || ! in_array($permission, $context['permissions'], true)) {
                    throw new SecurityFailure('FORBIDDEN', 403);
                }
                if (array_diff($catalog[$permission]['requires'], $requestedPermissions) !== []) {
                    throw new SecurityFailure('VALIDATION_FAILED', 422);
                }
            }
            $membership = DB::table('company_memberships')->where('company_id', $companyId)->where('user_id', $targetSubject)->first();
            $wasOwner = $membership !== null && DB::table('company_membership_roles')->where('membership_id', $membership->id)->where('role_id', 'owner')->whereNull('revoked_at')->exists();
            if (($wasOwner || in_array('owner', $roleIds, true)) && ! in_array('identity.owners.manage', $context['permissions'], true)) {
                throw new SecurityFailure('FORBIDDEN', 403);
            }
            if ($wasOwner && ($status !== 'active' || ! in_array('owner', $roleIds, true))) {
                $otherOwners = DB::table('company_memberships')->join('company_membership_roles', 'company_memberships.id', '=', 'company_membership_roles.membership_id')
                    ->where('company_id', $companyId)->where('status', 'active')->where('role_id', 'owner')->whereNull('revoked_at')->where('company_memberships.id', '!=', $membership->id)->exists();
                if (! $otherOwners) {
                    throw new SecurityFailure('LAST_OWNER_REQUIRED', 409);
                }
            }
            if (! User::query()->whereKey($targetSubject)->exists()) {
                throw new SecurityFailure('RELATIONSHIP_INVALID', 422);
            }
            $previousRoleIds = $membership === null ? [] : DB::table('company_membership_roles')->where('membership_id', $membership->id)->whereNull('revoked_at')->pluck('role_id')->all();
            $previousGrants = $membership === null ? [] : DB::table('company_permission_grants')->where('membership_id', $membership->id)->whereNull('revoked_at')->pluck('permission')->all();
            $membershipId = $membership?->id ?? (string) Str::uuid();
            if ($membership === null) {
                DB::table('company_memberships')->insert(['id' => $membershipId, 'company_id' => $companyId, 'user_id' => $targetSubject, 'status' => $status]);
            } else {
                DB::table('company_memberships')->where('id', $membershipId)->update(['status' => $status]);
            }
            // Retain revoked links; audit identifies each explicit replacement.
            DB::table('company_membership_roles')->where('membership_id', $membershipId)->update(['revoked_at' => now()]);
            DB::table('company_permission_grants')->where('membership_id', $membershipId)->update(['revoked_at' => now()]);
            foreach (array_unique($roleIds) as $roleId) {
                DB::table('company_membership_roles')->updateOrInsert(['membership_id' => $membershipId, 'role_id' => $roleId], ['revoked_at' => null]);
            }
            foreach (array_unique($permissionGrants) as $permission) {
                DB::table('company_permission_grants')->updateOrInsert(['membership_id' => $membershipId, 'permission' => $permission], ['revoked_at' => null]);
            }
            DB::table('companies')->where('id', $companyId)->increment('authorization_version');
            $this->audit->record('membership.changed', $companyId, (string) $actor->getAuthIdentifier(), $request->header('X-Request-ID'),
                ['targetSubject' => $targetSubject, 'previousRoleIds' => $previousRoleIds, 'previousGrants' => $previousGrants, 'previousStatus' => $membership?->status, 'status' => $status, 'roleIds' => $roleIds, 'permissionGrants' => $permissionGrants]);

            return $membershipId;
        });
    }
}
