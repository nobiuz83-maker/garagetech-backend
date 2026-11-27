<?php

namespace App\Policies;

use App\Auth\CompanyActor;
use App\Models\Client;
use App\Services\CompanyRecordScope;

class ClientPolicy
{
    public function view(CompanyActor $actor, Client $client): bool
    {
        return app(CompanyRecordScope::class)->canAccessClient($actor, $client);
    }

    public function update(CompanyActor $actor, Client $client): bool
    {
        return $this->view($actor, $client);
    }

    public function delete(CompanyActor $actor, Client $client): bool
    {
        return $this->view($actor, $client);
    }

    public function restore(CompanyActor $actor, Client $client): bool
    {
        return $this->view($actor, $client);
    }
}
