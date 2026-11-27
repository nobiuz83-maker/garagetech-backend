<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vehicle extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['data', 'make', 'model', 'year', 'vin', 'license_plate'];

    protected function casts(): array
    {
        return ['year' => 'integer', 'data' => 'array'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
