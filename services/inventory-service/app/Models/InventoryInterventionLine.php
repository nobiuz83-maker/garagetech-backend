<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class InventoryInterventionLine extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['id', 'company_id', 'intervention_id', 'product_id', 'sku', 'name_fr', 'name_en', 'quantity_units', 'unit_price_cents', 'total_cents', 'consumed_at', 'reversed_at'];

    protected function casts(): array
    {
        return ['quantity_units' => 'integer', 'unit_price_cents' => 'integer', 'total_cents' => 'integer', 'consumed_at' => 'immutable_datetime', 'reversed_at' => 'immutable_datetime'];
    }
}
