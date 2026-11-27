<?php

namespace App\Http\Resources;

use App\Support\HybridDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VehicleResource extends JsonResource
{
    /** Keep the response envelope distinct from the entity's JSON document. */
    public static bool $forceWrapping = true;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'clientId' => $this->client_id,
            'data' => HybridDocument::forApi($this->data ?? ['schemaVersion' => 1]),
            'make' => $this->make,
            'model' => $this->model,
            'year' => $this->year,
            'vin' => $this->vin,
            'licensePlate' => $this->license_plate,
            'createdAt' => $this->created_at->toISOString(),
        ];
    }
}
