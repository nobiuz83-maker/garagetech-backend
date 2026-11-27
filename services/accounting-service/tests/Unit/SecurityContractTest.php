<?php

namespace Tests\Unit;

use Tests\TestCase;

class SecurityContractTest extends TestCase
{
    public function test_role_templates_keep_financial_and_governance_boundaries_explicit(): void
    {
        $permissions = config('company_permissions.permissions');
        $roles = config('company_permissions.roles');
        $this->assertCount(65, $permissions);
        $this->assertSame(['owner', 'administrator', 'service_advisor', 'technician', 'inventory_manager', 'accountant', 'auditor'], array_keys($roles));
        $this->assertEqualsCanonicalizing(array_keys($permissions), $roles['owner']);
        foreach (['billing.credit_notes.issue', 'billing.refunds.record', 'identity.owners.manage'] as $permission) {
            $this->assertNotContains($permission, $roles['administrator']);
            $this->assertNotContains($permission, $roles['service_advisor']);
        }
        foreach ($roles['administrator'] as $permission) {
            $this->assertFalse(str_starts_with($permission, 'accounting.'));
        }
        $this->assertNotContains('inventory.valuation.read', $roles['technician']);
        $this->assertNotContains('identity.roles.assign', $roles['accountant']);
        foreach ($roles['auditor'] as $permission) {
            $this->assertFalse($permissions[$permission]['mutating']);
        }
        foreach ($roles as $grants) {
            foreach ($grants as $permission) {
                $this->assertArrayHasKey($permission, $permissions);
                $this->assertEmpty(array_diff($permissions[$permission]['requires'], $grants));
            }
        }
    }
}
