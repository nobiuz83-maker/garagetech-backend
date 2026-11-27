<?php

namespace App\Auth;

final readonly class IdentityVerification
{
    /** @param list<string> $cookies */
    private function __construct(
        public ?AuthenticatedActor $actor,
        public ?int $failureStatus,
        public array $cookies,
    ) {}

    /** @param list<string> $cookies */
    public static function authenticated(string $subject, array $cookies = []): self
    {
        return new self(new AuthenticatedActor($subject), null, $cookies);
    }

    /** @param list<string> $cookies */
    public static function failed(int $status, array $cookies = []): self
    {
        return new self(null, $status, $cookies);
    }
}
