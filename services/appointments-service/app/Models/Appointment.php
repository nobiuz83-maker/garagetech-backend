<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Appointment extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    public const STATUSES = ['scheduled', 'confirmed', 'inProgress', 'completed', 'cancelled'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id', 'user_id'];

    protected function casts(): array
    {
        return ['user_id' => 'string', 'data' => 'array', 'version' => 'integer'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(OwnerSubject::class, 'user_id', 'subject_id');
    }
}
