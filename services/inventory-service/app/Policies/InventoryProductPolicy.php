<?php

namespace App\Policies;

use App\Auth\CompanyActor;
use App\Models\InventoryProduct;

final class InventoryProductPolicy
{
    public function view(CompanyActor $actor, InventoryProduct $product): bool
    {
        return $actor->companyId === $product->company_id && $actor->allows('inventory.read');
    }

    public function update(CompanyActor $actor, InventoryProduct $product): bool
    {
        return $actor->companyId === $product->company_id && $actor->allows('inventory.catalog.manage');
    }
}
