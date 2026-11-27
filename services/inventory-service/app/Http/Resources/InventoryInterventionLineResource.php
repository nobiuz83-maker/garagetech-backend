<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class InventoryInterventionLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'interventionId' => $this->intervention_id, 'productId' => $this->product_id,
            'sku' => $this->sku, 'nameFr' => $this->name_fr, 'nameEn' => $this->name_en,
            'quantityUnits' => $this->quantity_units, 'unitPriceCents' => $this->unit_price_cents,
            'totalCents' => $this->total_cents, 'consumedAt' => $this->consumed_at?->toISOString(),
            'reversedAt' => $this->reversed_at?->toISOString(),
        ];
    }
}
