<?php

namespace App\Auth;

use RuntimeException;

final class SecurityFailure extends RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly int $status = 503)
    {
        parent::__construct($errorCode);
    }
}
