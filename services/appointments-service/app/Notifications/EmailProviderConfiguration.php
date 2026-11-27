<?php

namespace App\Notifications;

final class EmailProviderConfiguration
{
    public static function isConfigured(): bool
    {
        $host = (string) config('mail.mailers.smtp.host', '');

        return config('mail.default') === 'smtp'
            && $host !== '' && ! in_array($host, ['localhost', '127.0.0.1'], true)
            && (string) config('mail.from.address') !== '';
    }
}
