<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class InventoryMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'productId' => $this->product_id, 'type' => $this->type,
            'quantityDeltaUnits' => $this->quantity_delta_units, 'reason' => $this->reason,
            'actorSubject' => $this->actor_subject, 'occurredAt' => $this->occurred_at?->toISOString(),
        ];
    }
}
