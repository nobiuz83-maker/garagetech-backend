<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class InventoryProduct extends Model
{
    use HasUuids;

    protected $fillable = ['id', 'company_id', 'sku', 'name_fr', 'name_en', 'purchase_price_cents', 'sale_price_cents', 'available_units', 'version'];

    protected function casts(): array
    {
        return ['purchase_price_cents' => 'integer', 'sale_price_cents' => 'integer', 'available_units' => 'integer', 'version' => 'integer'];
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class, 'product_id');
    }
}
