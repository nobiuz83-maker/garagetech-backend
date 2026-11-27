<?php

namespace App\Policies;

use App\Auth\CompanyActor;
use App\Models\Appointment;
use App\Services\CompanyRecordScope;

class AppointmentPolicy
{
    public function view(CompanyActor $actor, Appointment $appointment): bool
    {
        return app(CompanyRecordScope::class)->canAccessAppointment($actor, $appointment);
    }

    public function update(CompanyActor $actor, Appointment $appointment): bool
    {
        return $this->view($actor, $appointment);
    }

    public function delete(CompanyActor $actor, Appointment $appointment): bool
    {
        return $this->view($actor, $appointment);
    }
}
