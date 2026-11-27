<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class InventoryProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'sku' => $this->sku, 'nameFr' => $this->name_fr, 'nameEn' => $this->name_en,
            'purchasePriceCents' => $this->purchase_price_cents, 'salePriceCents' => $this->sale_price_cents,
            'availableUnits' => $this->available_units, 'version' => $this->version,
            'createdAt' => $this->created_at?->toISOString(), 'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}
