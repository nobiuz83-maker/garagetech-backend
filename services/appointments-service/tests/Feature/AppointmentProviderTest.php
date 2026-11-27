<?php

namespace Tests\Feature;

use App\Notifications\EmailProviderConfiguration;
use App\Notifications\TwilioSmsProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AppointmentProviderTest extends TestCase
{
    public function test_twilio_provider_uses_the_fake_transport_and_returns_the_provider_reference(): void
    {
        config()->set('services.twilio.sid', 'not-a-real-twilio-account-sid');
        config()->set('services.twilio.token', 'test-token');
        config()->set('services.twilio.from', '+15145550100');
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM00000000000000000000000000000000'], 201)]);

        $reference = app(TwilioSmsProvider::class)->send('+15145550101', 'Test reminder');

        $this->assertSame('SM00000000000000000000000000000000', $reference);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request['To'] === '+15145550101' && $request['Body'] === 'Test reminder');
    }

    public function test_email_provider_stays_disabled_without_explicit_smtp_configuration(): void
    {
        config()->set('mail.default', 'log');
        config()->set('mail.mailers.smtp.host', '127.0.0.1');
        config()->set('mail.from.address', null);

        $this->assertFalse(EmailProviderConfiguration::isConfigured());
    }
}
