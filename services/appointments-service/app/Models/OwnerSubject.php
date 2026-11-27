<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class OwnerSubject extends Model
{
    protected $primaryKey = 'subject_id';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    public static function ensureExists(string $subject): void
    {
        DB::table('owner_subjects')->upsert([['subject_id' => $subject]], ['subject_id'], ['subject_id']);
    }
}
