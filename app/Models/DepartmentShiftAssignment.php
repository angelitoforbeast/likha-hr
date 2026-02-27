<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DepartmentShiftAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'department_id',
        'shift_id',
        'effective_date',
        'remarks',
    ];

    protected $casts = [
        'effective_date' => 'date',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /**
     * Get the active shift for a department on a given date.
     * Returns the assignment with the latest effective_date <= $date.
     */
    public static function getActiveShift(int $departmentId, string $date): ?Shift
    {
        $assignment = static::where('department_id', $departmentId)
            ->where('effective_date', '<=', $date)
            ->orderByDesc('effective_date')
            ->first();

        return $assignment?->shift;
    }

    /**
     * Get the current (latest) shift assignment for a department.
     */
    public static function getCurrentShift(int $departmentId): ?self
    {
        return static::where('department_id', $departmentId)
            ->where('effective_date', '<=', now()->toDateString())
            ->orderByDesc('effective_date')
            ->first();
    }
}
