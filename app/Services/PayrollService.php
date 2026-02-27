<?php

namespace App\Services;

use App\Models\AttendanceDay;
use App\Models\BenefitType;
use App\Models\Employee;
use App\Models\EmployeeBenefit;
use App\Models\EmployeeRate;
use App\Models\Holiday;
use App\Models\PayRate;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;

class PayrollService
{
    /**
     * Standard working hours per day (used for minute-based deductions).
     */
    protected int $standardHoursPerDay = 8;

    /**
     * Standard working minutes per day.
     */
    protected int $standardMinutesPerDay = 480;

    /**
     * OT pay multiplier (1.25 = 125% of hourly rate). Prepared but not used by default.
     */
    protected float $otMultiplier = 1.25;

    /**
     * Whether OT computation is enabled. Default: false.
     */
    protected bool $otEnabled = false;

    /**
     * Holiday pay rates (DOLE standard).
     */
    protected float $regularHolidayWorkedRate = 2.00;    // 200% if worked
    protected float $regularHolidayNotWorkedRate = 1.00; // 100% if not worked (paid)
    protected float $specialHolidayWorkedRate = 1.30;    // 130% if worked

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
     *
     * LOGIC:
     * Basic Pay = daily_rate × required_mandays - absence_deduction - late_deduction - undertime_deduction
     * Earnings = Holiday Pay + Rice Allowance (per day worked incl. holiday days), etc.
     * Deductions = SSS, PhilHealth, Pag-ibig (fixed per cutoff, if eligible)
     * Final Pay = Basic Pay + Earnings - Deductions + Adjustments
     *
     * Holiday Rules (for eligible employees):
     * - Regular Holiday + NOT worked = 100% daily rate (Holiday Pay in Earnings)
     * - Regular Holiday + WORKED = 200% daily rate (in Earnings)
     * - Special Non-Working + NOT worked = no pay, no deduction
     * - Special Non-Working + WORKED = 130% daily rate (in Earnings)
     *
     * For non-eligible employees: holidays are treated as regular working days.
     */
    protected function computeForEmployee(PayrollRun $run, Employee $employee, $start, $end): void
    {
        $startStr = $start instanceof Carbon ? $start->format('Y-m-d') : (string) $start;
        $endStr = $end instanceof Carbon ? $end->format('Y-m-d') : (string) $end;

        // 1. Get daily rate for this employee
        $dailyRate = $this->getDailyRate($employee, $startStr, $endStr);
        if ($dailyRate <= 0) {
            return; // No rate set, skip
        }

        // 2. Compute required mandays
        $mandaysData = $employee->computeRequiredMandays($startStr, $endStr);
        $requiredMandays = $mandaysData['required_mandays'];
        $isHolidayEligible = $mandaysData['holiday_eligible'];

        // 3. Get attendance days (days actually worked)
        $attendanceDays = AttendanceDay::where('employee_id', $employee->id)
            ->whereBetween('work_date', [$startStr, $endStr])
            ->get();

        // Build a set of dates that have attendance
        $attendanceDateSet = [];
        foreach ($attendanceDays as $day) {
            $dateStr = $day->work_date instanceof Carbon
                ? $day->work_date->format('Y-m-d')
                : (string) $day->work_date;
            $attendanceDateSet[$dateStr] = true;
        }

        // 4. Count days worked on REQUIRED days + track holiday work
        $daysWorked = 0;              // Regular working days with attendance
        $holidayDaysWorked = 0;       // Holiday days with attendance (for eligible)
        $totalWorkMinutes = 0;
        $totalLateMinutes = 0;
        $totalEarlyMinutes = 0;
        $totalOvertimeMinutes = 0;

        // Track holiday work details for earnings
        $regularHolidaysWorked = 0;
        $regularHolidaysNotWorked = 0;
        $specialHolidaysWorked = 0;
        $specialHolidaysNotWorked = 0;

        foreach ($attendanceDays as $day) {
            $dateStr = $day->work_date instanceof Carbon
                ? $day->work_date->format('Y-m-d')
                : (string) $day->work_date;

            $isRestDay = $employee->isDayOff($dateStr);
            $holiday = Holiday::getHolidayForDate($dateStr);

            if ($isRestDay) {
                // Rest day — don't count as worked day for basic pay
            } elseif ($holiday && $isHolidayEligible) {
                // Holiday-eligible employee working on a holiday
                // Don't count in regular daysWorked (basic pay), but track for holiday premium
                $holidayDaysWorked++;
            } else {
                // Regular working day (or non-eligible employee on a holiday)
                $daysWorked++;
            }

            // Accumulate minutes for tracking regardless
            $totalWorkMinutes += $day->payable_work_minutes;
            $totalLateMinutes += $day->computed_late_minutes;
            $totalEarlyMinutes += $day->computed_early_minutes;
            $totalOvertimeMinutes += $day->computed_overtime_minutes;
        }

        // 5. For eligible employees, check each holiday date to determine worked vs not worked
        if ($isHolidayEligible) {
            // Check Regular Holidays
            $regularHolidayDates = $mandaysData['regular_holiday_dates'] ?? [];
            foreach ($regularHolidayDates as $hDate) {
                if (isset($attendanceDateSet[$hDate])) {
                    $regularHolidaysWorked++;
                } else {
                    $regularHolidaysNotWorked++;
                }
            }

            // Check Special Non-Working Holidays
            $specialHolidayDates = $mandaysData['special_holiday_dates'] ?? [];
            foreach ($specialHolidayDates as $hDate) {
                if (isset($attendanceDateSet[$hDate])) {
                    $specialHolidaysWorked++;
                } else {
                    $specialHolidaysNotWorked++;
                    // Special Non-Working + not worked = no pay, no deduction (nothing to do)
                }
            }
        }

        // 6. Compute absent days (only on required mandays)
        $absentDays = max(0, $requiredMandays - $daysWorked);

        // 7. Total days actually worked (regular + holiday) — for Rice Allowance etc.
        $totalDaysActuallyWorked = $daysWorked + $holidayDaysWorked;

        // 8. Compute total days decimal (for backward compatibility)
        $shift = $employee->getShiftForDate($startStr);
        $requiredMinutes = $shift?->required_work_minutes ?? $this->standardMinutesPerDay;
        $totalDaysDecimal = $requiredMinutes > 0 ? round($totalWorkMinutes / $requiredMinutes, 4) : 0;

        // 9. Compute Basic Pay
        // Gross Basic = daily_rate × required_mandays
        $grossBasic = round($dailyRate * $requiredMandays, 2);

        // Absence deduction = daily_rate × absent_days
        $absenceDeduction = round($dailyRate * $absentDays, 2);

        // Late deduction = (late_minutes / 60 / 8) × daily_rate
        $lateDeduction = $this->computeMinuteBasedAmount($totalLateMinutes, $dailyRate);

        // Early/Undertime deduction = (early_minutes / 60 / 8) × daily_rate
        $earlyDeduction = $this->computeMinuteBasedAmount($totalEarlyMinutes, $dailyRate);

        // Basic Pay = Gross Basic - Absences - Late - Undertime
        $basePay = round($grossBasic - $absenceDeduction - $lateDeduction - $earlyDeduction, 2);
        $basePay = max(0, $basePay);

        // 10. OT Pay (prepared but disabled by default)
        $otPay = 0;
        if ($this->otEnabled) {
            $otPay = $this->computeOtPay($totalOvertimeMinutes, $dailyRate);
        }

        // 11. Compute Earnings (holiday premiums + benefits with category = 'earning')
        $earningsBreakdown = [];
        $totalEarnings = 0;
        $activeBenefits = $employee->getActiveBenefitsForDate($endStr);

        // --- Holiday Pay Earnings (for eligible employees) ---

        // Regular Holiday + WORKED = 200% of daily rate
        if ($regularHolidaysWorked > 0 && $dailyRate > 0) {
            $amount = round($dailyRate * $this->regularHolidayWorkedRate * $regularHolidaysWorked, 2);
            $earningsBreakdown[] = [
                'name' => 'Regular Holiday Worked',
                'type' => 'holiday',
                'rate' => round($dailyRate * $this->regularHolidayWorkedRate, 2),
                'days' => $regularHolidaysWorked,
                'amount' => $amount,
            ];
            $totalEarnings += $amount;
        }

        // Regular Holiday + NOT WORKED = 100% of daily rate (Holiday Pay)
        if ($regularHolidaysNotWorked > 0 && $dailyRate > 0) {
            $amount = round($dailyRate * $this->regularHolidayNotWorkedRate * $regularHolidaysNotWorked, 2);
            $earningsBreakdown[] = [
                'name' => 'Holiday Pay (Regular)',
                'type' => 'holiday',
                'rate' => round($dailyRate * $this->regularHolidayNotWorkedRate, 2),
                'days' => $regularHolidaysNotWorked,
                'amount' => $amount,
            ];
            $totalEarnings += $amount;
        }

        // Special Non-Working + WORKED = 130% of daily rate
        if ($specialHolidaysWorked > 0 && $dailyRate > 0) {
            $amount = round($dailyRate * $this->specialHolidayWorkedRate * $specialHolidaysWorked, 2);
            $earningsBreakdown[] = [
                'name' => 'Special Holiday Worked',
                'type' => 'holiday',
                'rate' => round($dailyRate * $this->specialHolidayWorkedRate, 2),
                'days' => $specialHolidaysWorked,
                'amount' => $amount,
            ];
            $totalEarnings += $amount;
        }

        // Special Non-Working + NOT WORKED = no pay (nothing added)

        // --- Benefit-based Earnings ---
        foreach ($activeBenefits as $benefit) {
            if ($benefit->benefitType->category !== 'earning') continue;

            $amount = 0;
            if ($benefit->benefitType->unit === 'per_day') {
                // Per day worked INCLUDING holiday days worked (e.g., Rice Allowance)
                $amount = round($benefit->amount * $totalDaysActuallyWorked, 2);
            } elseif ($benefit->benefitType->unit === 'fixed' || $benefit->benefitType->unit === 'per_cutoff') {
                // Fixed per cutoff
                $amount = round($benefit->amount, 2);
            }

            if ($amount > 0) {
                $earningsBreakdown[] = [
                    'name' => $benefit->benefitType->name,
                    'type' => $benefit->benefitType->unit,
                    'rate' => (float) $benefit->amount,
                    'days' => $benefit->benefitType->unit === 'per_day' ? $totalDaysActuallyWorked : null,
                    'amount' => $amount,
                ];
                $totalEarnings += $amount;
            }
        }

        // 12. Compute Deductions (benefits with category = 'deduction')
        $deductionsBreakdown = [];
        $totalDeductions = 0;

        foreach ($activeBenefits as $benefit) {
            if ($benefit->benefitType->category !== 'deduction') continue;

            $amount = round($benefit->amount, 2);

            if ($amount > 0) {
                $deductionsBreakdown[] = [
                    'name' => $benefit->benefitType->name,
                    'amount' => $amount,
                ];
                $totalDeductions += $amount;
            }
        }

        // 13. Compute Gross Pay and Final Pay
        // Gross Pay = Basic Pay + OT Pay
        $grossPay = round($basePay + $otPay, 2);

        // Final Pay = Gross Pay + Earnings - Deductions + Adjustments
        $finalPay = round($grossPay + $totalEarnings - $totalDeductions, 2);
        $finalPay = max(0, $finalPay);

        // 14. Create payroll item
        PayrollItem::create([
            'payroll_run_id'         => $run->id,
            'employee_id'            => $employee->id,
            'total_work_minutes'     => $totalWorkMinutes,
            'total_days_decimal'     => $totalDaysDecimal,
            'required_mandays'       => $requiredMandays,
            'days_worked'            => $totalDaysActuallyWorked, // includes holiday days worked
            'absent_days'            => $absentDays,
            'daily_rate'             => $dailyRate,
            'total_late_minutes'     => $totalLateMinutes,
            'total_early_minutes'    => $totalEarlyMinutes,
            'total_overtime_minutes' => $totalOvertimeMinutes,
            'base_pay'               => $basePay,
            'late_deduction'         => $lateDeduction,
            'early_deduction'        => $earlyDeduction,
            'absence_deduction'      => $absenceDeduction,
            'ot_pay'                 => $otPay,
            'earnings_breakdown'     => $earningsBreakdown,
            'deductions_breakdown'   => $deductionsBreakdown,
            'total_earnings'         => $totalEarnings,
            'total_deductions'       => $totalDeductions,
            'gross_pay'              => $grossPay,
            'adjustments'            => 0,
            'final_pay'              => $finalPay,
        ]);
    }

    /**
     * Get the daily rate for an employee.
     * Uses employee_rates table first, falls back to legacy pay_rates.
     */
    protected function getDailyRate(Employee $employee, string $startDate, string $endDate): float
    {
        // Try employee_rates table (use the rate effective at the start of the cutoff)
        $rate = EmployeeRate::getActiveRate($employee->id, $startDate);
        if ($rate !== null && $rate > 0) {
            return $rate;
        }

        // Try midpoint of cutoff
        $midDate = Carbon::parse($startDate)->addDays(
            (int) (Carbon::parse($startDate)->diffInDays(Carbon::parse($endDate)) / 2)
        )->format('Y-m-d');
        $rate = EmployeeRate::getActiveRate($employee->id, $midDate);
        if ($rate !== null && $rate > 0) {
            return $rate;
        }

        // Fall back to legacy pay_rates table
        $payRate = PayRate::where('employee_id', $employee->id)
            ->where('effective_from', '<=', $endDate)
            ->where(function ($q) use ($startDate) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', $startDate);
            })
            ->orderByDesc('effective_from')
            ->first();

        if ($payRate && $payRate->rate_type === 'daily') {
            return (float) $payRate->amount;
        }

        return 0;
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
     * Compute OT pay. Prepared but disabled by default.
     * Formula: (ot_minutes / 60 / 8) × daily_rate × ot_multiplier
     */
    protected function computeOtPay(int $otMinutes, float $dailyRate): float
    {
        if ($otMinutes <= 0 || $dailyRate <= 0) {
            return 0;
        }

        return round(($otMinutes / 60 / $this->standardHoursPerDay) * $dailyRate * $this->otMultiplier, 2);
    }
}
