<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Services\AppointmentBrandAssetStorage;
use App\Services\AppointmentNotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PublicAppointmentCancellationController extends Controller
{
    public function show(string $token, AppointmentBrandAssetStorage $brandAssets): array
    {
        $record = $this->validToken($token);
        $appointment = Appointment::query()->whereKey($record->appointment_id)->where('company_id', $record->company_id)->firstOrFail();
        $details = $appointment->data['notificationDetails'] ?? [];

        return ['data' => ['companyName' => $details['companyName'] ?? 'ViCarTech', 'companyLogo' => $brandAssets->dataUri($details['companyLogoPath'] ?? null),
            'date' => $appointment->date, 'startTime' => substr($appointment->start_time, 0, 5), 'serviceName' => $appointment->service_type,
            'vehicle' => $details['vehicle'] ?? '', 'canCancel' => $appointment->status !== 'cancelled']];
    }

    public function cancel(Request $request, string $token, AppointmentNotificationService $notifications): array
    {
        $input = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        return DB::transaction(function () use ($token, $input, $notifications): array {
            $record = DB::table('appointment_cancellation_tokens')->where('token_hash', hash('sha256', $token))->lockForUpdate()->first();
            abort_unless($record && ! $record->used_at && ! $record->revoked_at && CarbonImmutable::parse($record->expires_at)->isFuture(), 410, 'CANCELLATION_TOKEN_INVALID');
            $appointment = Appointment::query()->whereKey($record->appointment_id)->where('company_id', $record->company_id)->lockForUpdate()->firstOrFail();
            abort_if($appointment->status === 'cancelled' || $appointment->status === 'completed', 409, 'APPOINTMENT_ALREADY_CLOSED');
            $appointment->status = 'cancelled';
            $appointment->version++;
            $details = $appointment->data['notificationDetails'] ?? [];
            $appointment->data = [...$appointment->data, 'cancellationOrigin' => 'client'];
            if (! empty($input['reason'])) {
                $details['cancellationReason'] = $input['reason'];
                $appointment->data = [...$appointment->data, 'notificationDetails' => $details];
            }
            $appointment->save();
            DB::table('appointment_cancellation_tokens')->where('id', $record->id)->update(['used_at' => now(), 'updated_at' => now()]);
            DB::table('appointment_notifications')->where('appointment_id', $appointment->id)->whereIn('status', ['pending', 'queued'])->update(['status' => 'cancelled']);
            $notifications->recordCancellation($appointment, $details);

            return ['data' => ['cancelled' => true]];
        });
    }

    private function validToken(string $token): object
    {
        $record = DB::table('appointment_cancellation_tokens')->where('token_hash', hash('sha256', $token))
            ->whereNull('used_at')->whereNull('revoked_at')->first();
        abort_unless($record && CarbonImmutable::parse($record->expires_at)->isFuture(), 410, 'CANCELLATION_TOKEN_INVALID');

        return $record;
    }
}
