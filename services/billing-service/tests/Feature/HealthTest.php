<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_the_service_exposes_its_own_health_endpoint(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertExactJson(['service' => 'billing-service', 'status' => 'ok']);
    }
}
