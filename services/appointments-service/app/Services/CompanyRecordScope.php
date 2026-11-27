<?php

namespace App\Services;

use App\Auth\CompanyActor;
use App\Models\Appointment;
use App\Models\AppointmentSchedule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class CompanyRecordScope
{
    /** @param Builder<Appointment> $query */
    public function appointments(Builder $query, CompanyActor $actor): Builder
    {
        return $query->where('user_id', $actor->subject)->where(function (Builder $companyQuery) use ($actor): void {
            $companyQuery->where('company_id', $actor->companyId)
                ->orWhere(function (Builder $legacyQuery) use ($actor): void {
                    $legacyQuery->whereNull('company_id')->whereIn('id', DB::table('legacy_appointment_company_assignments')
                        ->select('appointment_id')->where('company_id', $actor->companyId));
                });
        });
    }

    /** @param Builder<AppointmentSchedule> $query */
    public function schedules(Builder $query, CompanyActor $actor): Builder
    {
        return $query->where('user_id', $actor->subject)->where(function (Builder $companyQuery) use ($actor): void {
            $companyQuery->where('company_id', $actor->companyId)
                ->orWhere(function (Builder $legacyQuery) use ($actor): void {
                    $legacyQuery->whereNull('company_id')->whereIn('id', DB::table('legacy_schedule_company_assignments')
                        ->select('schedule_id')->where('company_id', $actor->companyId));
                });
        });
    }

    public function canAccessAppointment(CompanyActor $actor, Appointment $appointment): bool
    {
        if ((string) $appointment->user_id !== $actor->subject) {
            return false;
        }
        if ($appointment->company_id === $actor->companyId) {
            return true;
        }

        return $appointment->company_id === null && DB::table('legacy_appointment_company_assignments')
            ->where('appointment_id', $appointment->id)->where('company_id', $actor->companyId)->exists();
    }
}
