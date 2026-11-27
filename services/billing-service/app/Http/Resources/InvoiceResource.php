<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'interventionId' => $this->intervention_id, 'number' => (int) $this->number,
            'subtotalCents' => (int) $this->subtotal_cents, 'gstCents' => (int) $this->gst_cents,
            'qstCents' => (int) $this->qst_cents, 'totalCents' => (int) $this->total_cents,
            'issuedAt' => $this->issued_at, 'snapshot' => json_decode($this->snapshot, true, 512, JSON_THROW_ON_ERROR),
        ];
    }
}
