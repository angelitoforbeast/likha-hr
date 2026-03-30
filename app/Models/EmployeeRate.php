<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeRate extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'daily_rate',
        'effective_date',
        'effective_until',
        'remarks',
    ];

    protected $casts = [
        'daily_rate'      => 'decimal:2',
        'effective_date'  => 'date',
        'effective_until' => 'date',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Get the active daily rate for an employee on a given date.
     * Returns the rate with the latest effective_date <= $date.
     */
    public static function getActiveRate(int $employeeId, string $date): ?float
    {
        $rate = static::where('employee_id', $employeeId)
            ->where('effective_date', '<=', $date)
            ->orderByDesc('effective_date')
            ->first();

        return $rate ? (float) $rate->daily_rate : null;
    }
}
