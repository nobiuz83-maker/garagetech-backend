<?php

namespace App\Policies;

use App\Auth\CompanyActor;
use App\Models\Intervention;

final class InterventionPolicy
{
    public function view(CompanyActor $actor, Intervention $intervention): bool
    {
        return $this->allows($actor, $intervention, 'interventions.read');
    }

    public function update(CompanyActor $actor, Intervention $intervention): bool
    {
        return $this->allows($actor, $intervention, 'interventions.write');
    }

    public function complete(CompanyActor $actor, Intervention $intervention): bool
    {
        return $this->allows($actor, $intervention, 'interventions.complete');
    }

    private function allows(CompanyActor $actor, Intervention $intervention, string $permission): bool
    {
        return $actor->companyId === $intervention->company_id && $actor->allows($permission);
    }
}
