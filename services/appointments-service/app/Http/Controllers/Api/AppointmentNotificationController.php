<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendAppointmentNotification;
use App\Models\Appointment;
use App\Models\AppointmentNotification;
use App\Notifications\EmailProviderConfiguration;
use App\Notifications\SmsProvider;
use App\Services\AppointmentNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;

class AppointmentNotificationController extends Controller
{
    public function providers(SmsProvider $smsProvider): array
    {
        return ['data' => ['emailConfigured' => EmailProviderConfiguration::isConfigured(),
            'smsConfigured' => $smsProvider->isConfigured()]];
    }

    public function sendTest(Request $request, SmsProvider $smsProvider): array
    {
        $channel = $request->input('channel');
        $recipientRules = $channel === 'email' ? ['required', 'email', 'max:255'] : ['required', 'regex:/^\\+[1-9]\\d{7,14}$/', 'max:32'];
        $input = $request->validate(['channel' => ['required', 'in:email,sms'], 'recipient' => $recipientRules]);
        if ($input['channel'] === 'email') {
            abort_unless(EmailProviderConfiguration::isConfigured(), 503, 'EMAIL_PROVIDER_NOT_CONFIGURED');
            Mail::raw('ViCarTech notification test', fn ($mail) => $mail->to($input['recipient'])->subject('ViCarTech notification test'));
        } else {
            abort_unless($smsProvider->isConfigured(), 503, 'SMS_PROVIDER_NOT_CONFIGURED');
            $smsProvider->send($input['recipient'], 'ViCarTech SMS test');
        }

        return ['data' => ['sent' => true]];
    }

    public function settings(Request $request, AppointmentNotificationService $service): array
    {
        return ['data' => $service->settings($request->user())];
    }

    public function updateSettings(Request $request, AppointmentNotificationService $service): array
    {
        $settings = $request->validate([
            'emailEnabled' => ['required', 'boolean'], 'smsEnabled' => ['required', 'boolean'],
            'confirmationEnabled' => ['required', 'boolean'], 'reminderEnabled' => ['required', 'boolean'],
            'reminderHours' => ['required', 'integer', 'between:1,168'], 'onlineCancellationEnabled' => ['required', 'boolean'],
            'garageEmail' => ['nullable', 'email', 'max:255'], 'senderName' => ['required', 'string', 'max:255'],
            'language' => ['required', 'in:fr,en'],
        ]);

        return ['data' => $service->saveSettings($request->user(), $settings)];
    }

    public function history(Request $request, Appointment $appointment, AppointmentNotificationService $service): array
    {
        Gate::authorize('view', $appointment);

        return ['data' => $service->history($request->user(), $appointment)];
    }

    public function retry(Request $request, Appointment $appointment): array
    {
        Gate::authorize('update', $appointment);
        $input = $request->validate(['notificationId' => ['required', 'uuid']]);
        $notification = AppointmentNotification::query()->where('id', $input['notificationId'])
            ->where('company_id', $request->user()->companyId)->where('appointment_id', $appointment->id)->firstOrFail();
        DB::transaction(function () use ($notification): void {
            $notification = AppointmentNotification::query()->whereKey($notification->id)->lockForUpdate()->firstOrFail();
            abort_unless($notification->status === 'failed', 409, 'NOTIFICATION_NOT_RETRYABLE');
            $notification->update(['status' => 'queued', 'failure_reason' => null, 'scheduled_at' => now()->utc()]);
            SendAppointmentNotification::dispatch($notification->id)->onQueue('notifications');
        });

        return ['data' => ['status' => 'queued']];
    }
}
