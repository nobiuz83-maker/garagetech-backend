<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class InventoryMovement extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['id', 'company_id', 'product_id', 'line_id', 'type', 'quantity_delta_units', 'reason', 'actor_subject', 'occurred_at'];

    protected function casts(): array
    {
        return ['quantity_delta_units' => 'integer', 'occurred_at' => 'immutable_datetime'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(InventoryProduct::class, 'product_id');
    }
}
