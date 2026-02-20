<?php

namespace App\Services;

use App\Models\AttendanceDay;
use App\Models\Employee;
use App\Models\EmployeeRate;
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
     * Standard working hours per day (used for deduction/OT computation).
     */
    protected int $standardHoursPerDay = 8;

    /**
     * OT pay multiplier (1.25 = 125% of hourly rate).
     */
    protected float $otMultiplier = 1.25;

    /**
     * Compute payroll items for a payroll run.
     */
    public function computePayroll(PayrollRun $run): void
    {
        $start = $run->cutoff_start;
        $end = $run->cutoff_end;

        // Get all active employees
        $employees = Employee::where('status', 'active')->get();

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
        $totalEarlyMinutes = $days->sum('computed_early_minutes');
        $totalOvertimeMinutes = $days->sum('computed_overtime_minutes');

        // Determine required work minutes per day from the shift active at the start of cutoff
        $shift = $employee->getShiftForDate(
            $start instanceof Carbon ? $start->format('Y-m-d') : (string) $start
        );
        $requiredMinutes = $shift?->required_work_minutes ?? 480;
        $totalDaysDecimal = $requiredMinutes > 0 ? round($totalWorkMinutes / $requiredMinutes, 4) : 0;

        // Calculate base pay using daily rate from employee_rates table
        $basePay = $this->calculateBasePayFromRates($employee, $days, $start, $end);
        $avgDailyRate = $this->getAverageDailyRate($employee, $days);

        // If no employee_rates found, fall back to legacy pay_rates table
        if ($basePay === null) {
            $payRate = $this->getLegacyPayRate($employee, $start, $end);
            $basePay = $this->calculateLegacyBasePay($payRate, $totalWorkMinutes, $totalDaysDecimal);
            $avgDailyRate = $payRate ? (float) $payRate->amount : 0;
        }

        // Compute deductions and OT pay using formula: (minutes / 60 / 8) × daily_rate
        $lateDeduction = $this->computeMinuteBasedAmount($totalLateMinutes, $avgDailyRate);
        $earlyDeduction = $this->computeMinuteBasedAmount($totalEarlyMinutes, $avgDailyRate);
        $otPay = $this->computeOtPay($totalOvertimeMinutes, $avgDailyRate);

        // Final Pay = Base Pay - Late Deduction - Early Deduction + OT Pay + Adjustments
        $finalPay = round($basePay - $lateDeduction - $earlyDeduction + $otPay, 2);

        PayrollItem::create([
            'payroll_run_id'         => $run->id,
            'employee_id'            => $employee->id,
            'total_work_minutes'     => $totalWorkMinutes,
            'total_days_decimal'     => $totalDaysDecimal,
            'total_late_minutes'     => $totalLateMinutes,
            'total_early_minutes'    => $totalEarlyMinutes,
            'total_overtime_minutes' => $totalOvertimeMinutes,
            'base_pay'               => $basePay,
            'late_deduction'         => $lateDeduction,
            'early_deduction'        => $earlyDeduction,
            'ot_pay'                 => $otPay,
            'adjustments'            => 0,
            'final_pay'              => max(0, $finalPay),
        ]);
    }

    /**
     * Compute a minute-based deduction/amount.
     * Formula: (minutes / 60 / 8) × daily_rate
     */
    protected function computeMinuteBasedAmount(int $minutes, float $dailyRate): float
    {
        if ($minutes <= 0 || $dailyRate <= 0) {
            return 0;
        }

        return round(($minutes / 60 / $this->standardHoursPerDay) * $dailyRate, 2);
    }

    /**
     * Compute OT pay.
     * Formula: (ot_minutes / 60 / 8) × daily_rate × ot_multiplier
     */
    protected function computeOtPay(int $otMinutes, float $dailyRate): float
    {
        if ($otMinutes <= 0 || $dailyRate <= 0) {
            return 0;
        }

        return round(($otMinutes / 60 / $this->standardHoursPerDay) * $dailyRate * $this->otMultiplier, 2);
    }

    /**
     * Get the average daily rate for an employee across the attendance days.
     * Used for deduction/OT computation.
     */
    protected function getAverageDailyRate(Employee $employee, $days): float
    {
        $totalRate = 0;
        $count = 0;

        foreach ($days as $day) {
            $dateStr = $day->work_date instanceof Carbon
                ? $day->work_date->format('Y-m-d')
                : (string) $day->work_date;

            $rate = $employee->getRateForDate($dateStr);
            if ($rate !== null && $rate > 0) {
                $totalRate += $rate;
                $count++;
            }
        }

        return $count > 0 ? round($totalRate / $count, 2) : 0;
    }

    /**
     * Calculate base pay using the new employee_rates table.
     * Uses daily rate effective on each work date for accurate computation.
     * Returns null if no rates are set.
     */
    protected function calculateBasePayFromRates(Employee $employee, $days, $start, $end): ?float
    {
        // Check if employee has any rates at all
        $hasRates = EmployeeRate::where('employee_id', $employee->id)->exists();
        if (!$hasRates) {
            return null;
        }

        $totalPay = 0;

        foreach ($days as $day) {
            $dateStr = $day->work_date instanceof Carbon
                ? $day->work_date->format('Y-m-d')
                : (string) $day->work_date;

            $dailyRate = $employee->getRateForDate($dateStr);

            if ($dailyRate === null || $dailyRate <= 0) {
                continue;
            }

            // Get shift for this date to determine required minutes
            $shift = $employee->getShiftForDate($dateStr);
            $requiredMinutes = $shift?->required_work_minutes ?? 480;

            // Prorate: (payable_work_minutes / required_work_minutes) * daily_rate
            $dayFraction = $requiredMinutes > 0
                ? $day->payable_work_minutes / $requiredMinutes
                : 0;

            $totalPay += round($dailyRate * $dayFraction, 2);
        }

        return round($totalPay, 2);
    }

    /**
     * Get the applicable legacy pay rate for an employee (from pay_rates table).
     * Falls back to company default (employee_id = null).
     */
    protected function getLegacyPayRate(Employee $employee, $start, $end): ?PayRate
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
     * Calculate base pay based on legacy rate type.
     */
    protected function calculateLegacyBasePay(?PayRate $rate, int $totalWorkMinutes, float $totalDaysDecimal): float
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
