<?php

namespace App\Jobs;

use App\Models\AppointmentNotification;
use App\Notifications\AppointmentNotice;
use App\Notifications\EmailProviderConfiguration;
use App\Notifications\SmsProvider;
use App\Services\AppointmentBrandAssetStorage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;
use Throwable;

class SendAppointmentNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public function __construct(public readonly string $notificationId) {}

    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(SmsProvider $smsProvider): void
    {
        $notification = AppointmentNotification::query()->findOrFail($this->notificationId);
        if (in_array($notification->status, ['sent', 'cancelled'], true)) {
            return;
        }
        $notification->increment('attempts');
        $payload = $notification->payload;
        $message = $this->message($notification, $payload, app(AppointmentBrandAssetStorage::class));
        $providerReference = null;
        if ($notification->channel === 'email') {
            abort_unless(EmailProviderConfiguration::isConfigured(), 503, 'EMAIL_PROVIDER_NOT_CONFIGURED');
            $logoPath = ! empty($payload['companyLogoPath']) ? storage_path('app/private/'.$payload['companyLogoPath']) : null;
            Notification::route('mail', $notification->recipient)->notify(new AppointmentNotice(
                ($payload['companyName'] ?? 'ViCarTech').' — '.$this->localizedType($notification->type, $payload),
                $message, $payload['senderName'] ?? null, $logoPath,
            ));
        } else {
            $plainText = html_entity_decode(strip_tags(preg_replace('/<\/(p|h[1-6])>/i', "\n", $message) ?? $message));
            $providerReference = $smsProvider->send($notification->recipient, trim($plainText).(! empty($payload['cancellationUrl']) ? "\n".$payload['cancellationUrl'] : ''));
        }
        $notification->forceFill(['status' => 'sent', 'sent_at' => now()->utc(), 'failure_reason' => null, 'provider_reference' => $providerReference])->save();
    }

    public function failed(Throwable $exception): void
    {
        AppointmentNotification::query()->whereKey($this->notificationId)->whereNotIn('status', ['sent', 'cancelled'])
            ->update(['status' => 'failed', 'failure_reason' => 'NOTIFICATION_PROVIDER_UNAVAILABLE']);
    }

    private function message(AppointmentNotification $notification, array $payload, AppointmentBrandAssetStorage $brandAssets): string
    {
        $isFrench = ($payload['language'] ?? 'fr') === 'fr';
        $dateTime = e(($payload['appointmentDate'] ?? '').' '.($payload['appointmentStart'] ?? ''));
        $companyName = e($payload['companyName'] ?? 'ViCarTech');
        $clientName = e($payload['clientName'] ?? '');
        $vehicle = e($payload['vehicle'] ?? '');
        $service = e($payload['serviceName'] ?? '');
        $address = e($payload['serviceAddress'] ?? '');
        $duration = (int) ($payload['durationMinutes'] ?? 0);
        $cancelLink = ! empty($payload['cancellationUrl']) ? '<p><a href="'.e($payload['cancellationUrl']).'">'.($isFrench ? 'Annuler gratuitement' : 'Cancel for free').'</a></p>' : '';
        $logoData = $brandAssets->dataUri($payload['companyLogoPath'] ?? null);
        $logo = $logoData ? '<img alt="" style="max-width:180px;max-height:90px" src="'.e($logoData).'">' : '';
        $garage = e($payload['companyPhone'] ?? '').' '.e($payload['companyEmail'] ?? '');

        $greeting = $isFrench ? 'Bonjour' : 'Hello';
        if ($notification->type === 'cancellation') {
            $appointmentLine = $isFrench
                ? 'Votre rendez-vous du '.$dateTime.' a été annulé.'
                : 'Your appointment on '.$dateTime.' has been cancelled.';
        } else {
            $appointmentLine = $isFrench
                ? 'Votre rendez-vous ('.$this->localizedType($notification->type, $payload).') est prévu le '.$dateTime.' pour environ '.$duration.' minutes.'
                : 'Your appointment ('.$this->localizedType($notification->type, $payload).') is scheduled for '.$dateTime.' and is estimated at '.$duration.' minutes.';
        }

        return "<html><body>{$logo}<h2>{$companyName}</h2><p>{$greeting} {$clientName},</p><p>{$appointmentLine}</p><p>{$service} — {$vehicle}</p><p>{$address}</p><p>{$garage}</p>{$cancelLink}</body></html>";
    }

    private function localizedType(string $type, array $payload): string
    {
        $translations = ($payload['language'] ?? 'fr') === 'fr'
            ? ['confirmation' => 'confirmation', 'reminder' => 'rappel', 'cancellation' => 'annulation']
            : ['confirmation' => 'confirmation', 'reminder' => 'reminder', 'cancellation' => 'cancellation'];

        return $translations[$type] ?? $type;
    }
}
