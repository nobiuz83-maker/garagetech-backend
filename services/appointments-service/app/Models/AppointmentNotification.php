<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AppointmentNotification extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'scheduled_at' => 'immutable_datetime', 'sent_at' => 'immutable_datetime'];
    }
}
