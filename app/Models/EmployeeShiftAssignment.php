<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeShiftAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'shift_id',
        'effective_date',
        'effective_until',
        'remarks',
    ];

    protected $casts = [
        'effective_date'  => 'date',
        'effective_until' => 'date',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /**
     * Get the active shift for an employee on a given date.
     * Returns the assignment whose date range contains $date, preferring the
     * most-recently-started one (so single-day overrides win over open-ended
     * ongoing shifts on the day that overrides them).
     */
    public static function getActiveShift(int $employeeId, string $date): ?Shift
    {
        $assignment = static::where('employee_id', $employeeId)
            ->where('effective_date', '<=', $date)
            ->where(function ($q) use ($date) {
                $q->whereNull('effective_until')
                  ->orWhere('effective_until', '>=', $date);
            })
            ->orderByDesc('effective_date')
            ->first();

        return $assignment?->shift;
    }
}
