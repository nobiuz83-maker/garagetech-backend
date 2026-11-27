<?php

namespace App\Services;

use App\Auth\CompanyActor;
use App\Jobs\SendAppointmentNotification;
use App\Models\Appointment;
use App\Models\AppointmentNotification;
use App\Models\OwnerSubject;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class AppointmentNotificationService
{
    public function saveSettings(CompanyActor $actor, array $settings): array
    {
        OwnerSubject::ensureExists($actor->subject);
        DB::table('appointment_notification_settings')->updateOrInsert(
            ['company_id' => $actor->companyId],
            ['owner_subject' => $actor->subject, 'settings' => json_encode($settings, JSON_THROW_ON_ERROR), 'updated_at' => now(), 'created_at' => now()],
        );
        foreach (['email' => 'emailEnabled', 'sms' => 'smsEnabled'] as $channel => $setting) {
            if (! $settings[$setting]) {
                AppointmentNotification::query()->where('company_id', $actor->companyId)->where('channel', $channel)
                    ->whereIn('status', ['pending', 'queued'])->update(['status' => 'cancelled']);
            }
        }
        if (! $settings['confirmationEnabled']) {
            AppointmentNotification::query()->where('company_id', $actor->companyId)->where('type', 'confirmation')
                ->whereIn('status', ['pending', 'queued'])->update(['status' => 'cancelled']);
        }
        if (! $settings['reminderEnabled']) {
            AppointmentNotification::query()->where('company_id', $actor->companyId)->where('type', 'reminder')
                ->whereIn('status', ['pending', 'queued'])->update(['status' => 'cancelled']);
        }
        if (! $settings['onlineCancellationEnabled']) {
            DB::table('appointment_cancellation_tokens')->where('company_id', $actor->companyId)
                ->whereNull('used_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);
        }

        return $settings;
    }

    public function settings(CompanyActor $actor): array
    {
        $record = DB::table('appointment_notification_settings')->where('company_id', $actor->companyId)->first();

        return $record ? json_decode($record->settings, true, flags: JSON_THROW_ON_ERROR) : self::defaultSettings();
    }

    public static function defaultSettings(): array
    {
        return ['emailEnabled' => false, 'smsEnabled' => false, 'confirmationEnabled' => true, 'reminderEnabled' => true,
            'reminderHours' => 24, 'onlineCancellationEnabled' => true, 'garageEmail' => '', 'senderName' => 'ViCarTech', 'language' => 'fr'];
    }

    public function synchronize(Appointment $appointment, array $details): void
    {
        AppointmentNotification::query()->where('appointment_id', $appointment->id)->whereIn('status', ['pending', 'queued'])->update(['status' => 'cancelled']);
        DB::table('appointment_cancellation_tokens')->where('appointment_id', $appointment->id)->whereNull('used_at')->update(['revoked_at' => now()]);
        $storedSettings = DB::table('appointment_notification_settings')->where('company_id', $appointment->company_id)->value('settings');
        $companySettings = [...self::defaultSettings(), ...(is_string($storedSettings) ? json_decode($storedSettings, true, flags: JSON_THROW_ON_ERROR) : [])];
        $details['channels'] = array_values(array_filter($details['channels'] ?? [], fn (string $channel): bool => $channel === 'email'
            ? $companySettings['emailEnabled'] : $companySettings['smsEnabled']));
        $details['confirmation'] = ! empty($details['confirmation']) && $companySettings['confirmationEnabled'];
        $details['reminder'] = ! empty($details['reminder']) && $companySettings['reminderEnabled'];
        $details['onlineCancellation'] = ! empty($details['onlineCancellation']) && $companySettings['onlineCancellationEnabled'];
        if ($appointment->status === 'cancelled' || empty($appointment->company_id) || empty($details['enabled']) || $details['channels'] === []) {
            return;
        }
        $localStart = CarbonImmutable::createFromFormat('!Y-m-d H:i', $appointment->date.' '.substr($appointment->start_time, 0, 5), 'America/Toronto');
        $startUtc = $localStart->utc();
        $channels = array_intersect(['email', 'sms'], $details['channels'] ?? []);
        if (empty($details['confirmation']) && empty($details['reminder'])) {
            return;
        }
        $cancelToken = Str::random(64);
        if (! empty($details['onlineCancellation'])) {
            DB::table('appointment_cancellation_tokens')->insert(['id' => (string) Str::uuid(), 'company_id' => $appointment->company_id,
                'appointment_id' => $appointment->id, 'token_hash' => hash('sha256', $cancelToken), 'expires_at' => $startUtc,
                'created_at' => now(), 'updated_at' => now()]);
        }
        $payload = [...$details, 'startAtUtc' => $startUtc->toIso8601String(),
            'durationMinutes' => $localStart->diffInMinutes(CarbonImmutable::createFromFormat('!Y-m-d H:i', $appointment->date.' '.substr($appointment->end_time, 0, 5), 'America/Toronto')),
            'cancellationUrl' => ! empty($details['onlineCancellation']) ? rtrim((string) config('app.frontend_url', config('app.url')), '/').'/cancel-appointment/'.$cancelToken : null];
        foreach ($channels as $channel) {
            $recipient = $channel === 'email' ? ($details['email'] ?? '') : ($details['phone'] ?? '');
            if (! $recipient) {
                continue;
            }
            if (! empty($details['confirmation'])) {
                $this->create($appointment, 'confirmation', $channel, $recipient, $payload, now()->utc());
            }
            if (! empty($details['reminder']) && $startUtc->greaterThanOrEqualTo(now()->utc()->addHours((int) ($details['reminderHours'] ?? 24)))) {
                $this->create($appointment, 'reminder', $channel, $recipient, $payload, $startUtc->subHours((int) ($details['reminderHours'] ?? 24)));
            }
        }
    }

    public function dispatchDue(): int
    {
        $notifications = AppointmentNotification::query()->where('status', 'pending')->where('scheduled_at', '<=', now()->utc())
            ->orderBy('scheduled_at')->limit(200)->get();
        foreach ($notifications as $notification) {
            try {
                DB::transaction(function () use ($notification): void {
                    $claimed = AppointmentNotification::query()->whereKey($notification->id)->where('status', 'pending')->update(['status' => 'queued']);
                    if ($claimed) {
                        SendAppointmentNotification::dispatch($notification->id)->onQueue('notifications');
                    }
                });
            } catch (Throwable) {
                Log::warning('Appointment notification dispatch will be retried by the scheduler.', ['notificationId' => $notification->id]);
            }
        }

        return $notifications->count();
    }

    public function history(CompanyActor $actor, Appointment $appointment): array
    {
        return AppointmentNotification::query()->where('company_id', $actor->companyId)->where('appointment_id', $appointment->id)
            ->orderByDesc('created_at')->get()->map(fn (AppointmentNotification $notification): array => [
                'id' => $notification->id, 'type' => $notification->type, 'channel' => $notification->channel,
                'recipient' => $notification->recipient, 'scheduledAt' => $notification->scheduled_at?->toISOString(),
                'sentAt' => $notification->sent_at?->toISOString(), 'status' => $notification->status,
                'failureReason' => $notification->failure_reason, 'attempts' => $notification->attempts,
            ])->all();
    }

    public function recordCancellation(Appointment $appointment, array $details): void
    {
        if (empty($appointment->company_id)) {
            return;
        }
        $settingsJson = DB::table('appointment_notification_settings')->where('company_id', $appointment->company_id)->value('settings');
        $settings = [...self::defaultSettings(), ...(is_string($settingsJson) ? json_decode($settingsJson, true, flags: JSON_THROW_ON_ERROR) : [])];
        $channels = array_filter(array_intersect(['email', 'sms'], $details['channels'] ?? []), fn (string $channel): bool => $channel === 'email'
            ? $settings['emailEnabled'] : $settings['smsEnabled']);
        foreach ($channels as $channel) {
            $recipient = $channel === 'email' ? ($details['email'] ?? '') : ($details['phone'] ?? '');
            if ($recipient) {
                $this->create($appointment, 'cancellation', $channel, $recipient, $details, now()->utc());
            }
        }
        if ($settings['emailEnabled'] && ! empty($details['garageEmail'])) {
            $this->create($appointment, 'cancellation', 'email', $details['garageEmail'], $details, now()->utc());
        }
    }

    private function create(Appointment $appointment, string $type, string $channel, string $recipient, array $payload, CarbonImmutable $scheduledAt): void
    {
        $dedupeKey = hash('sha256', implode('|', [$appointment->id, $type, $channel, strtolower($recipient)]));
        $notification = AppointmentNotification::query()->updateOrCreate(['dedupe_key' => $dedupeKey], [
            'id' => (string) Str::uuid(), 'company_id' => $appointment->company_id, 'recipient' => $recipient,
            'appointment_id' => $appointment->id, 'type' => $type, 'channel' => $channel, 'payload' => $payload,
            'scheduled_at' => $scheduledAt, 'status' => 'pending', 'attempts' => 0, 'failure_reason' => null,
        ]);
        DB::afterCommit(function () use ($notification, $scheduledAt): void {
            try {
                DB::transaction(function () use ($notification, $scheduledAt): void {
                    $claimed = AppointmentNotification::query()->whereKey($notification->id)->where('status', 'pending')->update(['status' => 'queued']);
                    if ($claimed) {
                        SendAppointmentNotification::dispatch($notification->id)->delay($scheduledAt)->onQueue('notifications');
                    }
                });
            } catch (Throwable) {
                Log::warning('Appointment notification dispatch will be retried by the scheduler.', ['notificationId' => $notification->id]);
            }
        });
    }
}
