<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeActiveStatus extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'status',
        'effective_from',
        'effective_until',
        'remarks',
    ];

    protected $casts = [
        'effective_from'  => 'date',
        'effective_until' => 'date',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Get the current active status for an employee on a given date.
     */
    public static function getStatusForDate(int $employeeId, string $date): ?self
    {
        return static::where('employee_id', $employeeId)
            ->where('effective_from', '<=', $date)
            ->where(function ($q) use ($date) {
                $q->whereNull('effective_until')
                  ->orWhere('effective_until', '>=', $date);
            })
            ->orderByDesc('effective_from')
            ->first();
    }

    /**
     * Sync the cached status column on the employees table.
     */
    public static function syncEmployeeStatus(int $employeeId): void
    {
        $today = now()->toDateString();
        $current = static::getStatusForDate($employeeId, $today);
        $status = $current ? $current->status : 'active'; // default active if no history

        Employee::where('id', $employeeId)->update(['status' => $status]);
    }
}
