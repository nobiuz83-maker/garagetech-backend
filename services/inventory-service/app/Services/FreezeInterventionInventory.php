<?php

namespace App\Services;

use App\Auth\CompanyActor;
use App\Auth\SecurityFailure;
use App\Models\InventoryInterventionLine;
use Illuminate\Support\Facades\DB;

final class FreezeInterventionInventory
{
    /** @param array<string, mixed> $intervention
     * @return list<array<string, mixed>>
     */
    public function preview(CompanyActor $actor, array $intervention): array
    {
        if (DB::table('inventory_intervention_invoice_locks')->where('company_id', $actor->companyId)
            ->where('intervention_id', $intervention['id'])->exists()) {
            throw new SecurityFailure('INTERVENTION_INVOICED', 409);
        }
        $lines = InventoryInterventionLine::where('company_id', $actor->companyId)
            ->where('intervention_id', $intervention['id'])->whereNull('reversed_at')->orderBy('id')->limit(1001)->get();
        if ($lines->count() > 1000) {
            throw new SecurityFailure('TOO_MANY_INVENTORY_LINES', 422);
        }

        return $this->serializeLines($lines);
    }

    /** @param array<string, mixed> $intervention
     * @return list<array<string, mixed>>
     */
    public function freeze(CompanyActor $actor, array $intervention, string $invoiceId, int $laborSubtotalCents, ?string $expectedLinesHash = null): array
    {
        return DB::transaction(function () use ($actor, $intervention, $invoiceId, $laborSubtotalCents, $expectedLinesHash): array {
            DB::table('company_subjects')->where('company_id', $actor->companyId)->lockForUpdate()->first();
            DB::table('inventory_intervention_references')->insertOrIgnore([
                'company_id' => $actor->companyId, 'intervention_id' => $intervention['id'],
                'client_id' => $intervention['clientId'], 'vehicle_id' => $intervention['vehicleId'],
            ]);
            $reference = DB::table('inventory_intervention_references')->where('company_id', $actor->companyId)->where('intervention_id', $intervention['id'])->first();
            if ($reference->client_id !== $intervention['clientId'] || $reference->vehicle_id !== $intervention['vehicleId']) {
                throw new SecurityFailure('INTERVENTION_REFERENCE_CONFLICT', 409);
            }
            $existing = DB::table('inventory_intervention_invoice_locks')->where('company_id', $actor->companyId)->where('intervention_id', $intervention['id'])->first();
            if ($existing !== null && $existing->invoice_id !== $invoiceId) {
                throw new SecurityFailure('INTERVENTION_INVOICED', 409);
            }
            $lines = InventoryInterventionLine::where('company_id', $actor->companyId)->where('intervention_id', $intervention['id'])
                ->whereNull('reversed_at')->orderBy('id')->limit(1001)->lockForUpdate()->get();
            if ($lines->count() > 1000) {
                throw new SecurityFailure('TOO_MANY_INVENTORY_LINES', 422);
            }
            $serializedLines = $this->serializeLines($lines);
            if ($expectedLinesHash !== null && ! hash_equals($expectedLinesHash, hash('sha256', json_encode($serializedLines, JSON_THROW_ON_ERROR)))) {
                throw new SecurityFailure('INVOICE_PREVIEW_CHANGED', 409);
            }
            $subtotalCents = $laborSubtotalCents;
            foreach ($lines as $line) {
                if ($line->total_cents > 10000000000 - $subtotalCents) {
                    throw new SecurityFailure('INVOICE_AMOUNT_INVALID', 422);
                }
                $subtotalCents += $line->total_cents;
            }

            if ($existing === null) {
                DB::table('inventory_intervention_invoice_locks')->insert([
                    'company_id' => $actor->companyId, 'intervention_id' => $intervention['id'], 'invoice_id' => $invoiceId, 'created_at' => now(),
                ]);
            }

            return $serializedLines;
        });
    }

    /** @return list<array<string, mixed>> */
    private function serializeLines(\Illuminate\Support\Collection $lines): array
    {
        return $lines->map(fn (InventoryInterventionLine $line): array => [
                'id' => $line->id, 'productId' => $line->product_id, 'sku' => $line->sku,
                'nameFr' => $line->name_fr, 'nameEn' => $line->name_en,
                'quantityUnits' => $line->quantity_units, 'unitPriceCents' => $line->unit_price_cents, 'totalCents' => $line->total_cents,
            ])->all();
    }
}
