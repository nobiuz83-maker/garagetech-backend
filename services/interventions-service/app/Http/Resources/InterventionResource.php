<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class InterventionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'clientId' => $this->client_id,
            'vehicleId' => $this->vehicle_id,
            'appointmentId' => $this->appointment_id,
            'address' => $this->address ?? '',
            'addressDetails' => $this->address_details,
            'startDate' => $this->start_date?->format('Y-m-d'),
            'endDate' => $this->end_date?->format('Y-m-d'),
            'status' => $this->status,
            'description' => $this->description,
            'diagnosis' => $this->diagnosis,
            'notes' => $this->notes,
            'laborRateCents' => $this->labor_rate_cents,
            'laborHours' => $this->labor_hours,
            'version' => $this->version,
            'data' => $this->data,
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}
