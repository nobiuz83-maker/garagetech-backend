<?php

namespace App\Services;

use App\Auth\CompanyActor;
use App\Auth\SecurityFailure;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AdjustInventoryStock
{
    public function adjust(CompanyActor $actor, string $productId, string $movementId, int $quantityDeltaUnits, string $reason): InventoryMovement
    {
        return DB::transaction(function () use ($actor, $productId, $movementId, $quantityDeltaUnits, $reason): InventoryMovement {
            $product = InventoryProduct::where('company_id', $actor->companyId)->whereKey($productId)->lockForUpdate()->first();
            if ($product === null) {
                throw new SecurityFailure('RESOURCE_NOT_FOUND', 404);
            }
            $nextQuantity = $product->available_units + $quantityDeltaUnits;
            if ($nextQuantity < 0 || $nextQuantity > 9007199254740991) {
                throw ValidationException::withMessages(['quantityDeltaUnits' => 'INVENTORY_QUANTITY_INVALID']);
            }
            $product->available_units = $nextQuantity;
            $product->version++;
            $product->save();

            return InventoryMovement::create([
                'id' => $movementId, 'company_id' => $actor->companyId, 'product_id' => $productId,
                'type' => 'adjustment', 'quantity_delta_units' => $quantityDeltaUnits,
                'reason' => $reason, 'actor_subject' => $actor->subject, 'occurred_at' => now(),
            ]);
        });
    }
}
