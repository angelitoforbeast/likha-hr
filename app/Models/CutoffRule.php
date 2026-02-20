<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CutoffRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'rule_json',
    ];

    protected $casts = [
        'rule_json' => 'array',
    ];
}
