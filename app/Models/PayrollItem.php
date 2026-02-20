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
        'total_late_minutes',
        'total_overtime_minutes',
        'base_pay',
        'adjustments',
        'final_pay',
        'notes',
    ];

    protected $casts = [
        'total_work_minutes' => 'integer',
        'total_days_decimal' => 'decimal:4',
        'total_late_minutes' => 'integer',
        'total_overtime_minutes' => 'integer',
        'base_pay' => 'decimal:2',
        'adjustments' => 'decimal:2',
        'final_pay' => 'decimal:2',
    ];

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
