<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ManusEditLog extends Model
{
    protected $table = 'manus_edit_logs';

    protected $fillable = [
        'datetime',
        'action',
        'file',
        'what_changed',
        'purpose',
    ];

    protected $casts = [
        'datetime' => 'datetime',
    ];
}
