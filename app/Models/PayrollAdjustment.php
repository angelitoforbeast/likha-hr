<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Master record for a payroll adjustment.
 *
 * Keyed by (employee_id, cutoff_start, cutoff_end). Persists across
 * payroll runs so that deleting + recreating a run for the same cutoff
 * still carries the adjustment over.
 *
 * The value is copied as a snapshot into payroll_items.adjustments at
 * compute / recompute / save-adjustment time. Snapshots do not update
 * silently when the master changes; a Recompute is required.
 */
class PayrollAdjustment extends Model
{
    use Auditable;

    protected $fillable = [
        'employee_id',
        'cutoff_start',
        'cutoff_end',
        'amount',
        'notes',
    ];

    protected $casts = [
        'cutoff_start' => 'date',
        'cutoff_end'   => 'date',
        'amount'       => 'decimal:2',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Look up the current master adjustment for an (employee, cutoff) pair.
     * Returns null when no adjustment has been set.
     */
    public static function findFor(int $employeeId, string $cutoffStart, string $cutoffEnd): ?self
    {
        return static::where('employee_id', $employeeId)
            ->whereDate('cutoff_start', $cutoffStart)
            ->whereDate('cutoff_end', $cutoffEnd)
            ->first();
    }
}