<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppointmentResource extends JsonResource
{
    public static bool $forceWrapping = true;

    public function toArray(Request $request): array
    {
        $document = $this->data ?? ['schemaVersion' => 1];

        return [
            'id' => $this->id, 'clientId' => $this->client_id, 'vehicleId' => $this->vehicle_id,
            'date' => $this->date, 'startTime' => substr($this->start_time, 0, 5), 'endTime' => substr($this->end_time, 0, 5),
            'status' => $this->status, 'serviceType' => $this->service_type,
            'description' => $this->description, 'notes' => $this->notes,
            'address' => $document['address'] ?? '',
            ...isset($document['addressDetails']) ? ['addressDetails' => $document['addressDetails']] : [],
            ...isset($document['serviceId']) ? ['serviceId' => $document['serviceId']] : [],
            'data' => ['schemaVersion' => 1, ...isset($document['legacyProperties']) ? ['legacyProperties' => $document['legacyProperties']] : []],
            'version' => $this->version,
            'createdAt' => $this->created_at->toISOString(), 'updatedAt' => $this->updated_at->toISOString(),
        ];
    }
}
