<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class Intervention extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'company_id', 'client_id', 'vehicle_id', 'appointment_id',
        'start_date', 'end_date', 'status', 'description', 'diagnosis', 'notes', 'address', 'address_details',
        'labor_rate_cents', 'labor_hours', 'version', 'data',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'labor_rate_cents' => 'integer',
            'labor_hours' => 'decimal:4',
            'version' => 'integer',
            'data' => 'array',
            'address_details' => 'array',
        ];
    }
}
