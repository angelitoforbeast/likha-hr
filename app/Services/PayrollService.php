<?php

namespace App\Services;

use App\Models\AttendanceDay;
use App\Models\Employee;
use App\Models\PayRate;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PayrollService
{
    /**
     * Default standard working days per month for monthly proration.
     */
    protected int $standardDays = 26;

    /**
     * Compute payroll items for a payroll run.
     */
    public function computePayroll(PayrollRun $run): void
    {
        $start = $run->cutoff_start;
        $end = $run->cutoff_end;

        // Get all active employees
        $employees = Employee::where('status', 'active')->with('defaultShift')->get();

        DB::transaction(function () use ($run, $employees, $start, $end) {
            // Remove existing items for this run (recompute)
            PayrollItem::where('payroll_run_id', $run->id)->delete();

            foreach ($employees as $employee) {
                $this->computeForEmployee($run, $employee, $start, $end);
            }
        });
    }

    /**
     * Compute payroll for a single employee.
     */
    protected function computeForEmployee(PayrollRun $run, Employee $employee, $start, $end): void
    {
        // Get attendance days for this employee in the cutoff period
        $days = AttendanceDay::where('employee_id', $employee->id)
            ->whereBetween('work_date', [$start, $end])
            ->get();

        if ($days->isEmpty()) {
            return;
        }

        $totalWorkMinutes = $days->sum('payable_work_minutes');
        $totalLateMinutes = $days->sum('computed_late_minutes');
        $totalOvertimeMinutes = $days->sum('computed_overtime_minutes');

        // Determine required work minutes per day from shift
        $requiredMinutes = $employee->defaultShift?->required_work_minutes ?? 480;
        $totalDaysDecimal = $requiredMinutes > 0 ? round($totalWorkMinutes / $requiredMinutes, 4) : 0;

        // Get pay rate
        $payRate = $this->getPayRate($employee, $start, $end);
        $basePay = $this->calculateBasePay($payRate, $totalWorkMinutes, $totalDaysDecimal);

        PayrollItem::create([
            'payroll_run_id'       => $run->id,
            'employee_id'          => $employee->id,
            'total_work_minutes'   => $totalWorkMinutes,
            'total_days_decimal'   => $totalDaysDecimal,
            'total_late_minutes'   => $totalLateMinutes,
            'total_overtime_minutes' => $totalOvertimeMinutes,
            'base_pay'             => $basePay,
            'adjustments'          => 0,
            'final_pay'            => $basePay,
        ]);
    }

    /**
     * Get the applicable pay rate for an employee.
     * Falls back to company default (employee_id = null).
     */
    protected function getPayRate(Employee $employee, $start, $end): ?PayRate
    {
        // Try employee-specific rate
        $rate = PayRate::where('employee_id', $employee->id)
            ->where('effective_from', '<=', $end)
            ->where(function ($q) use ($start) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', $start);
            })
            ->orderByDesc('effective_from')
            ->first();

        if ($rate) {
            return $rate;
        }

        // Fall back to company default
        return PayRate::whereNull('employee_id')
            ->where('effective_from', '<=', $end)
            ->where(function ($q) use ($start) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', $start);
            })
            ->orderByDesc('effective_from')
            ->first();
    }

    /**
     * Calculate base pay based on rate type.
     */
    protected function calculateBasePay(?PayRate $rate, int $totalWorkMinutes, float $totalDaysDecimal): float
    {
        if (!$rate) {
            return 0;
        }

        $amount = (float) $rate->amount;

        return match ($rate->rate_type) {
            'daily'   => round($amount * $totalDaysDecimal, 2),
            'hourly'  => round($amount * ($totalWorkMinutes / 60), 2),
            'monthly' => round($amount * ($totalDaysDecimal / $this->standardDays), 2),
            default   => 0,
        };
    }
}
