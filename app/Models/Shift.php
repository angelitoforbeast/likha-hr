<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shift extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'start_time',
        'end_time',
        'lunch_start',
        'lunch_end',
        'required_work_minutes',
        'grace_in_minutes',
        'grace_out_minutes',
        'lunch_inference_window_before_minutes',
        'lunch_inference_window_after_minutes',
    ];

    protected $casts = [
        'start_time' => 'string',
        'end_time' => 'string',
        'lunch_start' => 'string',
        'lunch_end' => 'string',
        'required_work_minutes' => 'integer',
        'grace_in_minutes' => 'integer',
        'grace_out_minutes' => 'integer',
        'lunch_inference_window_before_minutes' => 'integer',
        'lunch_inference_window_after_minutes' => 'integer',
    ];

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'default_shift_id');
    }

    public function attendanceDays(): HasMany
    {
        return $this->hasMany(AttendanceDay::class);
    }
}
