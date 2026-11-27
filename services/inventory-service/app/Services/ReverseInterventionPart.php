<?php

namespace App\Services;

use App\Auth\CompanyActor;
use App\Auth\SecurityFailure;
use App\Models\InventoryInterventionLine;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use Illuminate\Support\Facades\DB;

final class ReverseInterventionPart
{
    public function reverse(CompanyActor $actor, string $interventionId, string $lineId, string $movementId, string $reason): InventoryInterventionLine
    {
        return DB::transaction(function () use ($actor, $interventionId, $lineId, $movementId, $reason): InventoryInterventionLine {
            DB::table('company_subjects')->where('company_id', $actor->companyId)->lockForUpdate()->first();
            if (DB::table('inventory_intervention_invoice_locks')->where('company_id', $actor->companyId)->where('intervention_id', $interventionId)->exists()) {
                throw new SecurityFailure('INTERVENTION_INVOICED', 409);
            }
            $line = InventoryInterventionLine::where('company_id', $actor->companyId)->where('intervention_id', $interventionId)->whereKey($lineId)->lockForUpdate()->first();
            if ($line === null) {
                throw new SecurityFailure('RESOURCE_NOT_FOUND', 404);
            }
            if ($line->reversed_at !== null) {
                throw new SecurityFailure('INVENTORY_LINE_ALREADY_REVERSED', 409);
            }
            $product = InventoryProduct::where('company_id', $actor->companyId)->whereKey($line->product_id)->lockForUpdate()->firstOrFail();
            if ($product->available_units > 9007199254740991 - $line->quantity_units) {
                throw new SecurityFailure('INVENTORY_QUANTITY_INVALID', 422);
            }
            $product->available_units += $line->quantity_units;
            $product->version++;
            $product->save();
            $line->reversed_at = now();
            $line->save();
            InventoryMovement::create([
                'id' => $movementId, 'company_id' => $actor->companyId, 'product_id' => $product->id,
                'line_id' => $line->id, 'type' => 'reversal', 'quantity_delta_units' => $line->quantity_units,
                'reason' => $reason, 'actor_subject' => $actor->subject, 'occurred_at' => now(),
            ]);

            return $line;
        });
    }
}
