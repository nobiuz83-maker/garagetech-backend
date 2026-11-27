<?php

namespace App\Services;

use App\Auth\CompanyActor;
use App\Models\Appointment;
use App\Models\AppointmentSchedule;
use Carbon\CarbonImmutable;

final class AppointmentAvailability
{
    public function __construct(private CompanyRecordScope $scope) {}

    /** @return array{timeZone: string, slotStepMinutes: int, days: list<array<string, mixed>>} */
    public function forRange(CompanyActor $actor, string $from, string $to, int $durationMinutes, ?string $excludeId): array
    {
        $timeZone = (string) config('appointments.timezone', 'America/Toronto');
        $schedule = $this->scheduleFor($actor);
        $slotStepMinutes = 15;
        $occupiedAppointments = $this->scope->appointments(Appointment::query(), $actor)
            ->whereBetween('date', [$from, $to])
            ->where('status', '!=', 'cancelled')
            ->when($excludeId, fn ($query) => $query->where('id', '!=', $excludeId))
            ->get(['id', 'date', 'start_time', 'end_time', 'status']);
        $today = CarbonImmutable::now($timeZone)->startOfDay();
        $now = CarbonImmutable::now($timeZone);
        $days = [];
        $includeSlots = $from === $to;

        for ($date = CarbonImmutable::createFromFormat('!Y-m-d', $from, $timeZone);
            $date !== false && $date->format('Y-m-d') <= $to;
            $date = $date->addDay()) {
            $dateKey = $date->format('Y-m-d');
            $isWorkDay = in_array($date->dayOfWeek, $schedule['workDays'] ?? [], true);
            $isPastDate = $date->lt($today);
            $appointmentsForDate = $occupiedAppointments->where('date', $dateKey);
            $slots = [];
            $availableCount = 0;
            $eligibleCount = 0;
            $openingTime = (string) $schedule['openingTime'];
            $closingTime = (string) $schedule['closingTime'];
            $openingMinute = $this->minutes($openingTime);
            $closingMinute = $this->minutes($closingTime);
            $isOpen = $isWorkDay && $openingMinute < $closingMinute;
            $firstSlotMinute = (int) (ceil($openingMinute / $slotStepMinutes) * $slotStepMinutes);

            for ($startMinute = $firstSlotMinute; $isOpen && $startMinute < $closingMinute; $startMinute += $slotStepMinutes) {
                $endMinute = $startMinute + $durationMinutes;
                $startTime = $this->formatTime($startMinute);
                $endTime = $endMinute > 1439 ? '23:59' : $this->formatTime($endMinute);
                $reason = $this->unavailableReason(
                    $date,
                    $dateKey,
                    $startMinute,
                    $endMinute,
                    $schedule,
                    $isWorkDay,
                    $isPastDate,
                    $now,
                    $timeZone,
                    $durationMinutes
                );

                if ($reason === null) {
                    $eligibleCount++;
                    if (($schedule['preventOverlaps'] ?? true) && $this->overlapsAppointment(
                        $appointmentsForDate,
                        $startMinute,
                        $endMinute,
                        $schedule['intervalMinutes']
                    )) {
                        $reason = 'occupied';
                    }
                }

                $status = $reason === null ? 'available' : (in_array($reason, ['occupied'], true) ? 'unavailable' : 'closed');
                if ($status === 'available') $availableCount++;
                $slots[] = ['startTime' => $startTime, 'endTime' => $endTime, 'status' => $status, 'reason' => $reason];
            }

            $dayStatus = match (true) {
                $isPastDate || ! $isOpen => 'closed',
                $eligibleCount === 0 => 'unavailable',
                $availableCount === $eligibleCount => 'available',
                $availableCount > 0 => 'partial',
                default => 'unavailable',
            };

            $days[] = [
                'date' => $dateKey,
                'isOpen' => $isOpen,
                'openingTime' => $openingTime,
                'closingTime' => $closingTime,
                'status' => $dayStatus,
                'availableSlots' => $availableCount,
                'totalSlots' => $eligibleCount,
                'slots' => $includeSlots ? $slots : [],
            ];
        }

        foreach ($days as &$day) {
            foreach ($day['slots'] as &$slot) {
                if ($slot['reason'] !== 'occupied') continue;
                $nextAvailable = null;
                foreach ($day['slots'] as $candidate) {
                    if ($candidate['status'] === 'available' && $candidate['startTime'] > $slot['startTime']) {
                        $nextAvailable = $candidate['startTime'];
                        break;
                    }
                }
                $slot['nextAvailableStartTime'] = $nextAvailable;
            }
            unset($slot);
        }
        unset($day);

        return ['timeZone' => $timeZone, 'slotStepMinutes' => $slotStepMinutes, 'days' => $days];
    }

    /** @return array<string, mixed> */
    private function scheduleFor(CompanyActor $actor): array
    {
        $storedSchedule = $this->scope->schedules(AppointmentSchedule::query(), $actor)
            ->where('company_id', $actor->companyId)->first()?->data['schedule']
            ?? $this->scope->schedules(AppointmentSchedule::query(), $actor)->whereNull('company_id')->first()?->data['schedule']
            ?? null;

        return $storedSchedule ?? [
            'workDays' => [1, 2, 3, 4, 5], 'openingTime' => '08:00', 'closingTime' => '18:00',
            'defaultDurationMinutes' => 60, 'intervalMinutes' => 0, 'breakStart' => '', 'breakEnd' => '',
            'preventOverlaps' => true, 'bookingLeadMinutes' => 0,
        ];
    }

    /** @param iterable<Appointment> $appointments */
    private function overlapsAppointment(iterable $appointments, int $startMinute, int $endMinute, int $gapMinutes): bool
    {
        foreach ($appointments as $appointment) {
            $appointmentStart = $this->minutes($appointment->start_time);
            $appointmentEnd = $this->minutes($appointment->end_time);
            if ($appointmentStart < $endMinute + $gapMinutes && $startMinute < $appointmentEnd + $gapMinutes) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $schedule */
    private function unavailableReason(CarbonImmutable $date, string $dateKey, int $startMinute, int $endMinute, array $schedule, bool $isWorkDay, bool $isPastDate, CarbonImmutable $now, string $timeZone, int $durationMinutes): ?string
    {
        if ($isPastDate) return 'past';
        if (! $isWorkDay || $durationMinutes < 1 || $endMinute > 1440) return 'closed';
        if ($startMinute < $this->minutes($schedule['openingTime']) || $endMinute > $this->minutes($schedule['closingTime'])) return 'closed';
        if (! empty($schedule['breakStart']) && $startMinute < $this->minutes($schedule['breakEnd']) && $endMinute > $this->minutes($schedule['breakStart'])) return 'break';
        $start = CarbonImmutable::createFromFormat('!Y-m-d H:i', $dateKey.' '.$this->formatTime($startMinute), $timeZone);
        if ($start === false || $start->format('Y-m-d H:i') !== $dateKey.' '.$this->formatTime($startMinute)) return 'timeInvalid';
        $end = CarbonImmutable::createFromFormat('!Y-m-d H:i', $dateKey.' '.$this->formatTime($endMinute), $timeZone);
        if ($end === false || $end->format('Y-m-d H:i') !== $dateKey.' '.$this->formatTime($endMinute)) return 'timeInvalid';
        $leadTime = max(0, (int) ($schedule['bookingLeadMinutes'] ?? 0));
        if ($start->lt($now->addMinutes($leadTime))) return 'lead';

        return null;
    }

    private function minutes(string $time): int
    {
        return ((int) substr($time, 0, 2)) * 60 + (int) substr($time, 3, 2);
    }

    private function formatTime(int $minute): string
    {
        return sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
    }
}
