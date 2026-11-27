<?php

namespace App\Auth;

use Illuminate\Auth\GenericUser;

final class CompanyActor extends GenericUser
{
    /** @param list<string> $permissions */
    public function __construct(
        public readonly string $subject,
        public readonly string $companyId,
        public readonly string $membershipId,
        public readonly int $authorizationVersion,
        public readonly array $permissions,
        public readonly string $requestId,
    ) {
        parent::__construct(['id' => $subject]);
    }

    public function allows(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }
}
