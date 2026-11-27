<?php

namespace App\Notifications;

interface SmsProvider
{
    public function isConfigured(): bool;

    public function send(string $recipient, string $message): ?string;
}
