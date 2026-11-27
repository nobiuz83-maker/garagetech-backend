<?php

namespace App\Services;

use App\Models\User;

class PermissionService
{
    public function allows(User $user, string $serviceName, string $permission): bool
    {
        $servicePermissions = config("internal_auth.callers.{$serviceName}.permissions", []);

        return in_array($permission, $servicePermissions, true) && $user->exists;
    }
}
