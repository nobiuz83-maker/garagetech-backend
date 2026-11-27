<?php

namespace App\Notifications;

use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final class TwilioSmsProvider implements SmsProvider
{
    public function isConfigured(): bool
    {
        return (bool) (config('services.twilio.sid') && config('services.twilio.token') && config('services.twilio.from'));
    }

    public function send(string $recipient, string $message): ?string
    {
        if (! $this->isConfigured()) {
            throw new ServiceUnavailableHttpException(null, 'SMS_PROVIDER_NOT_CONFIGURED');
        }
        $accountSid = (string) config('services.twilio.sid');
        $response = Http::asForm()->withBasicAuth($accountSid, (string) config('services.twilio.token'))
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Messages.json", [
                'To' => $recipient, 'From' => config('services.twilio.from'), 'Body' => $message,
            ]);
        $response->throw();

        return $response->json('sid');
    }
}
