<?php

namespace App\Http\Resources;

use App\Support\HybridDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClientResource extends JsonResource
{
    /** Keep the response envelope distinct from the entity's JSON document. */
    public static bool $forceWrapping = true;

    public function toArray(Request $request): array
    {
        $document = $this->data ?? ['schemaVersion' => 1];
        $address = $document['address'] ?? null;

        return [
            'id' => $this->id,
            'firstName' => $this->first_name,
            'lastName' => $this->last_name,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->when($address !== null, $address),
            'data' => HybridDocument::forApi(array_diff_key($document, ['address' => true])),
            'createdAt' => $this->created_at->toISOString(),
            'vehicles' => VehicleResource::collection($this->whenLoaded('vehicles')),
        ];
    }
}
