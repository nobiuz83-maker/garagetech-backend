<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['data', 'first_name', 'last_name', 'phone', 'email', 'address'];

    protected function casts(): array
    {
        return ['user_id' => 'string', 'address' => 'array', 'data' => 'array'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(OwnerSubject::class, 'user_id', 'subject_id');
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }
}
