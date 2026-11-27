<?php

namespace App\Services;

use App\Auth\CompanyActor;
use App\Models\Appointment;
use App\Models\AppointmentSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class AppointmentScheduling
{
    public function __construct(private CompanyRecordScope $scope) {}

    public function validate(CompanyActor $actor, array $input, ?Appointment $existing): void
    {
        foreach (['startTime', 'endTime'] as $property) {
            $localTime = CarbonImmutable::createFromFormat('!Y-m-d H:i', $input['date'].' '.$input[$property], 'America/Toronto');
            if ($localTime->format('Y-m-d H:i') !== $input['date'].' '.$input[$property]) {
                throw ValidationException::withMessages([$property => 'APPOINTMENT_TIME_INVALID']);
            }
        }
        if ($input['status'] === 'cancelled') {
            return;
        }
        $schedule = $this->scope->schedules(AppointmentSchedule::query(), $actor)->where('company_id', $actor->companyId)->first()?->data['schedule']
            ?? $this->scope->schedules(AppointmentSchedule::query(), $actor)->whereNull('company_id')->first()?->data['schedule']
            ?? null;
        $timeChanged = $existing === null || $existing->date !== $input['date']
            || substr($existing->start_time, 0, 5) !== $input['startTime'] || substr($existing->end_time, 0, 5) !== $input['endTime'];
        if ($schedule !== null && $timeChanged) {
            $this->validateWorkingHours($input, $schedule);
        }
        if (($schedule['preventOverlaps'] ?? true) === false) {
            return;
        }
        $gap = $schedule['intervalMinutes'] ?? 0;
        $appointments = $this->scope->appointments(Appointment::query(), $actor)->where('date', $input['date'])
            ->where('status', '!=', 'cancelled')->when($existing, fn (Builder $query): Builder => $query->where('id', '!=', $existing->id))->get();
        foreach ($appointments as $appointment) {
            if ($this->minutes($appointment->start_time) < $this->minutes($input['endTime']) + $gap
                && $this->minutes($input['startTime']) < $this->minutes($appointment->end_time) + $gap) {
                throw ValidationException::withMessages(['startTime' => 'APPOINTMENT_CONFLICT']);
            }
        }
    }

    private function validateWorkingHours(array $input, array $schedule): void
    {
        $start = CarbonImmutable::createFromFormat('!Y-m-d H:i', $input['date'].' '.$input['startTime'], 'America/Toronto');
        if ($start->format('Y-m-d H:i') !== $input['date'].' '.$input['startTime']) {
            throw ValidationException::withMessages(['startTime' => 'APPOINTMENT_TIME_INVALID']);
        }
        if (($schedule['bookingLeadMinutes'] ?? 0) > 0 && $start->lessThan(now('America/Toronto')->addMinutes($schedule['bookingLeadMinutes']))) {
            throw ValidationException::withMessages(['startTime' => 'APPOINTMENT_BOOKING_LEAD']);
        }
        if (! in_array($start->dayOfWeek, $schedule['workDays'], true)
            || $input['startTime'] < $schedule['openingTime'] || $input['endTime'] > $schedule['closingTime']
            || (! empty($schedule['breakStart']) && $input['startTime'] < $schedule['breakEnd'] && $input['endTime'] > $schedule['breakStart'])) {
            throw ValidationException::withMessages(['startTime' => 'APPOINTMENT_OUTSIDE_HOURS']);
        }
    }

    private function minutes(string $time): int
    {
        return ((int) substr($time, 0, 2)) * 60 + (int) substr($time, 3, 2);
    }
}
