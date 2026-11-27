<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppointmentSchedule extends Model
{
    protected $fillable = ['data'];

    protected function casts(): array
    {
        return ['user_id' => 'string', 'data' => 'array'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(OwnerSubject::class, 'user_id', 'subject_id');
    }
}
