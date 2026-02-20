<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceDay extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'work_date',
        'shift_id',
        'time_in',
        'lunch_out',
        'lunch_in',
        'time_out',
        'computed_work_minutes',
        'computed_late_minutes',
        'computed_early_minutes',
        'computed_overtime_minutes',
        'payable_work_minutes',
        'needs_review',
        'notes',
        'source_run_id',
    ];

    protected $casts = [
        'work_date' => 'date',
        'time_in' => 'datetime',
        'lunch_out' => 'datetime',
        'lunch_in' => 'datetime',
        'time_out' => 'datetime',
        'computed_work_minutes' => 'integer',
        'computed_late_minutes' => 'integer',
        'computed_early_minutes' => 'integer',
        'computed_overtime_minutes' => 'integer',
        'payable_work_minutes' => 'integer',
        'needs_review' => 'boolean',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function importRun(): BelongsTo
    {
        return $this->belongsTo(AttendanceImportRun::class, 'source_run_id');
    }
}
