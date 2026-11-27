<?php

namespace App\Auth;

use Illuminate\Auth\GenericUser;

final class AuthenticatedActor extends GenericUser
{
    public function __construct(public readonly string $subject)
    {
        parent::__construct(['id' => $subject]);
    }
}
