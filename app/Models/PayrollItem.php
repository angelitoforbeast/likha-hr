<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'payroll_run_id',
        'employee_id',
        'total_work_minutes',
        'total_days_decimal',
        'required_mandays',
        'days_worked',
        'absent_days',
        'daily_rate',
        'total_late_minutes',
        'total_early_minutes',
        'total_overtime_minutes',
        'base_pay',
        'late_deduction',
        'early_deduction',
        'absence_deduction',
        'ot_pay',
        'earnings_breakdown',
        'deductions_breakdown',
        'total_earnings',
        'total_deductions',
        'gross_pay',
        'adjustments',
        'final_pay',
        'notes',
        'daily_breakdown',
        'holiday_earnings_detail',
    ];

    protected $casts = [
        'total_work_minutes' => 'integer',
        'total_days_decimal' => 'decimal:4',
        'required_mandays' => 'integer',
        'days_worked' => 'integer',
        'absent_days' => 'integer',
        'daily_rate' => 'decimal:2',
        'total_late_minutes' => 'integer',
        'total_early_minutes' => 'integer',
        'total_overtime_minutes' => 'integer',
        'base_pay' => 'decimal:2',
        'late_deduction' => 'decimal:2',
        'early_deduction' => 'decimal:2',
        'absence_deduction' => 'decimal:2',
        'ot_pay' => 'decimal:2',
        'earnings_breakdown' => 'array',
        'deductions_breakdown' => 'array',
        'total_earnings' => 'decimal:2',
        'total_deductions' => 'decimal:2',
        'gross_pay' => 'decimal:2',
        'adjustments' => 'decimal:2',
        'final_pay' => 'decimal:2',
        'daily_breakdown' => 'array',
        'holiday_earnings_detail' => 'array',
    ];

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Get the computed basic pay breakdown.
     */
    public function getBasicPayBreakdownAttribute(): array
    {
        return [
            'gross_basic' => round($this->daily_rate * $this->required_mandays, 2),
            'absence_deduction' => (float) $this->absence_deduction,
            'late_deduction' => (float) $this->late_deduction,
            'early_deduction' => (float) $this->early_deduction,
            'net_basic' => (float) $this->base_pay,
        ];
    }
}
