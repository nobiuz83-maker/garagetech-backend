<?php

namespace App\Services;

use App\Auth\CompanyActor;
use App\Auth\SecurityFailure;
use App\Models\InventoryInterventionLine;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ConsumeInterventionPart
{
    /** @param array<string, mixed> $intervention
     * @param  array{id: string, movementId: string, productId: string, quantityUnits: int}  $input
     */
    public function consume(CompanyActor $actor, array $intervention, array $input): InventoryInterventionLine
    {
        return DB::transaction(function () use ($actor, $intervention, $input): InventoryInterventionLine {
            DB::table('company_subjects')->where('company_id', $actor->companyId)->lockForUpdate()->first();
            if (DB::table('inventory_intervention_invoice_locks')->where('company_id', $actor->companyId)->where('intervention_id', $intervention['id'])->exists()) {
                throw new SecurityFailure('INTERVENTION_INVOICED', 409);
            }
            if (InventoryInterventionLine::whereKey($input['id'])->exists()) {
                throw new SecurityFailure('INVENTORY_LINE_EXISTS', 409);
            }
            if (($intervention['status'] ?? null) !== 'planned') {
                throw new SecurityFailure('INTERVENTION_NOT_EDITABLE', 409);
            }
            $product = InventoryProduct::where('company_id', $actor->companyId)->whereKey($input['productId'])->lockForUpdate()->first();
            if ($product === null) {
                throw new SecurityFailure('RESOURCE_NOT_FOUND', 404);
            }
            if ($input['quantityUnits'] > $product->available_units) {
                throw ValidationException::withMessages(['quantityUnits' => 'INSUFFICIENT_STOCK']);
            }
            if ($product->sale_price_cents !== 0 && $input['quantityUnits'] > intdiv(PHP_INT_MAX - 500, $product->sale_price_cents)) {
                throw ValidationException::withMessages(['quantityUnits' => 'INVENTORY_AMOUNT_INVALID']);
            }
            $totalCents = intdiv($input['quantityUnits'] * $product->sale_price_cents + 500, 1000);
            if ($totalCents > 9007199254740991) {
                throw ValidationException::withMessages(['quantityUnits' => 'INVENTORY_AMOUNT_INVALID']);
            }
            DB::table('inventory_intervention_references')->insertOrIgnore([
                'company_id' => $actor->companyId, 'intervention_id' => $intervention['id'],
                'client_id' => $intervention['clientId'], 'vehicle_id' => $intervention['vehicleId'],
            ]);
            $reference = DB::table('inventory_intervention_references')->where('company_id', $actor->companyId)->where('intervention_id', $intervention['id'])->lockForUpdate()->first();
            if ($reference->client_id !== $intervention['clientId'] || $reference->vehicle_id !== $intervention['vehicleId']) {
                throw new SecurityFailure('INTERVENTION_REFERENCE_CONFLICT', 409);
            }
            $line = InventoryInterventionLine::create([
                'id' => $input['id'], 'company_id' => $actor->companyId, 'intervention_id' => $intervention['id'],
                'product_id' => $product->id, 'sku' => $product->sku, 'name_fr' => $product->name_fr, 'name_en' => $product->name_en,
                'quantity_units' => $input['quantityUnits'], 'unit_price_cents' => $product->sale_price_cents,
                'total_cents' => $totalCents, 'consumed_at' => now(),
            ]);
            $product->available_units -= $input['quantityUnits'];
            $product->version++;
            $product->save();
            InventoryMovement::create([
                'id' => $input['movementId'], 'company_id' => $actor->companyId, 'product_id' => $product->id,
                'line_id' => $line->id, 'type' => 'consumption', 'quantity_delta_units' => -$input['quantityUnits'],
                'reason' => 'INTERVENTION_CONSUMPTION', 'actor_subject' => $actor->subject, 'occurred_at' => now(),
            ]);

            return $line;
        });
    }
}
