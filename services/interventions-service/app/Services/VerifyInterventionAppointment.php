<?php

namespace App\Services;

use App\Auth\CompanyActor;
use App\Auth\SecurityFailure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

final class VerifyInterventionAppointment
{
    public function verify(Request $request, CompanyActor $actor, ?string $appointmentId, string $clientId, string $vehicleId): void
    {
        if ($appointmentId === null) {
            return;
        }

        $referenceExists = DB::table('intervention_appointment_references')
            ->where('company_id', $actor->companyId)
            ->where('client_id', $clientId)
            ->where('vehicle_id', $vehicleId)
            ->where('appointment_id', $appointmentId)
            ->exists();
        if ($referenceExists) {
            return;
        }

        $cookieName = config('company_security.session_cookie');
        $session = $request->cookies->get($cookieName);
        if (! is_string($session) || $session === '') {
            throw new SecurityFailure('UNAUTHENTICATED', 401);
        }

        $baseUrl = config('intervention_references.appointments_url');
        $response = $this->fetchAppointment($baseUrl, $request, $actor, $cookieName, $session, $appointmentId);
        if (in_array($response->status(), [403, 404], true)) {
            throw ValidationException::withMessages(['appointmentId' => 'INTERVENTION_APPOINTMENT_REQUIRED']);
        }

        $appointment = $response->json('data');
        if ($response->status() !== 200 || ! is_array($appointment) || ($appointment['id'] ?? null) !== $appointmentId) {
            throw new SecurityFailure('APPOINTMENTS_SERVICE_UNAVAILABLE');
        }
        if (($appointment['clientId'] ?? null) !== $clientId || ($appointment['vehicleId'] ?? null) !== $vehicleId) {
            throw ValidationException::withMessages(['appointmentId' => 'INTERVENTION_APPOINTMENT_ASSOCIATION_INVALID']);
        }
    }

    private function fetchAppointment(
        mixed $baseUrl,
        Request $request,
        CompanyActor $actor,
        string $cookieName,
        string $session,
        string $appointmentId,
    ): \Illuminate\Http\Client\Response {
        $parts = is_string($baseUrl) ? parse_url($baseUrl) : false;
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw new SecurityFailure('APPOINTMENTS_SERVICE_UNAVAILABLE');
        }

        foreach (['ca_certificate_file', 'client_certificate_file', 'client_key_file'] as $setting) {
            $path = config('intervention_references.'.$setting);
            if (! is_string($path) || ! is_file($path) || ! is_readable($path)) {
                throw new SecurityFailure('APPOINTMENTS_SERVICE_UNAVAILABLE');
            }
        }

        try {
            return Http::withOptions([
                'verify' => config('intervention_references.ca_certificate_file'),
                'cert' => config('intervention_references.client_certificate_file'),
                'ssl_key' => config('intervention_references.client_key_file'),
                'connect_timeout' => 0.5,
                'timeout' => 2,
                'allow_redirects' => false,
            ])->withHeaders([
                'Accept' => 'application/json',
                'Cookie' => $cookieName.'='.rawurlencode($session),
                'X-Company-ID' => $actor->companyId,
                'X-Request-ID' => $actor->requestId,
            ])->get(rtrim($baseUrl, '/').'/api/v1/appointments/'.rawurlencode($appointmentId));
        } catch (ConnectionException) {
            throw new SecurityFailure('APPOINTMENTS_SERVICE_UNAVAILABLE');
        }
    }
}
