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

        // 1. Get daily rate for this employee (primary rate for display; per-day rates used in computation)
        $dailyRate = $this->getDailyRate($employee, $startStr, $endStr);
        if ($dailyRate <= 0) {
            return; // No rate set, skip
        }

        // Check if employee has multiple rates during this cutoff
        $allRates = EmployeeRate::where('employee_id', $employee->id)
            ->where('effective_date', '<=', $endStr)
            ->where(function ($q) use ($startStr) {
                $q->whereNull('effective_until')
                  ->orWhere('effective_until', '>=', $startStr);
            })
            ->orderBy('effective_date')
            ->get();
        $hasMultipleRates = $allRates->count() > 1;

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
        $totalUndertimeMinutes = 0; // Actual undertime = required - payable per day
        $perDayGrossBasic = 0;        // Sum of per-day rate for each worked day
        $perDayLateDeduction = 0;     // Sum of per-day late deductions
        $perDayEarlyDeduction = 0;    // Sum of per-day early deductions
        $perDayUndertimeDeduction = 0; // Sum of per-day undertime deductions
        $perDayAbsenceDeduction = 0;  // Sum of per-day absence deductions (for required days not worked)

        // Daily breakdown for payslip
        $dailyBreakdown = [];

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

            if ($isRestDay && (int) $day->payable_work_minutes > 0) {
                // Rest day but employee worked — count as regular day worked (Option C)
                $daysWorked++;
            } elseif ($isRestDay) {
                // Rest day — no attendance, don't count
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

            // Compute actual undertime per day: difference between required and payable
            // This catches halfdays (no Time Out) where computed_early_minutes may be 0
            $dayShift = $employee->getShiftForDate($dateStr);
            $dayRequired = $dayShift?->required_work_minutes ?? $this->standardMinutesPerDay;
            $dayPayable = (int) $day->payable_work_minutes;
            $dayLate = (int) $day->computed_late_minutes;
            $dayEarly = (int) $day->computed_early_minutes;
            $dayUndertime = 0;

            if (!$isRestDay || $dayPayable > 0) {
                $dayMissing = max(0, $dayRequired - $dayPayable);
                $dayAlreadyDeducted = $dayLate + $dayEarly;
                $dayUndertime = max(0, $dayMissing - $dayAlreadyDeducted);
                $totalUndertimeMinutes += $dayUndertime;
            }

            // Get per-day rate
            $dayRate = EmployeeRate::getActiveRate($employee->id, $dateStr) ?? $dailyRate;

            // Accumulate per-day deductions
            $perDayLateDeduction += $this->computeMinuteBasedAmount($dayLate, $dayRate);
            $perDayEarlyDeduction += $this->computeMinuteBasedAmount($dayEarly, $dayRate);
            $perDayUndertimeDeduction += $this->computeMinuteBasedAmount($dayUndertime, $dayRate);

            // Accumulate per-day gross basic (rate for each worked day)
            if (!$isRestDay || $dayPayable > 0) {
                $perDayGrossBasic += $dayRate;
            }

            // Build daily breakdown entry
            $dayHours = round($dayPayable / 60, 2);
            $dayDailyAmount = $dayRequired > 0 ? round($dayRate * ($dayPayable / $dayRequired), 2) : 0;
            $dayTotalDeduct = $dayLate + $dayEarly + $dayUndertime;

            $dayType = 'regular';
            if ($isRestDay && $dayPayable > 0) $dayType = 'rest_day_worked';
            elseif ($isRestDay) $dayType = 'rest_day';
            elseif ($holiday) $dayType = 'holiday';
            elseif ($day->status === 'Absent' || $dayPayable <= 0) $dayType = 'absent';

            $dailyBreakdown[] = [
                'date'       => $dateStr,
                'type'       => $dayType,
                'status'     => $day->status ?? 'Present',
                'time_in'    => $day->time_in ? $day->time_in->format('H:i') : null,
                'lunch_out'  => $day->lunch_out ? $day->lunch_out->format('H:i') : null,
                'lunch_in'   => $day->lunch_in ? $day->lunch_in->format('H:i') : null,
                'time_out'   => $day->time_out ? $day->time_out->format('H:i') : null,
                'work_mins'  => $dayPayable,
                'hours'      => $dayHours,
                'rate'       => $dayRate,
                'late'       => $dayLate,
                'early'      => $dayEarly,
                'undertime'  => $dayUndertime,
                'ot'         => (int) $day->computed_overtime_minutes,
                'amount'     => $dayDailyAmount,
            ];
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
        // For employees with multiple rates during the cutoff, use per-day accumulated values.
        // For single-rate employees, use the traditional formula.
        if ($hasMultipleRates) {
            // Per-day rate computation: gross basic = sum of day rates for required days
            // We need to also account for absent days using their specific day rate
            // perDayGrossBasic already has the sum of rates for days actually worked
            // For required mandays, we need to add rates for absent days too
            $grossBasic = round($perDayGrossBasic, 2);

            // Absent days: compute using the rate for each absent required day
            // Since we don't track which specific dates are absent, use average rate
            // Actually, grossBasic from per-day = sum of rates for worked days only
            // We want: grossBasic = sum of rates for ALL required days (worked + absent)
            // So we compute absence deduction separately and add absent day rates to gross
            $absenceDeduction = 0;
            if ($absentDays > 0) {
                // Get the required dates that were NOT worked
                $requiredDates = $mandaysData['required_dates'] ?? [];
                foreach ($requiredDates as $reqDate) {
                    if (!isset($attendanceDateSet[$reqDate])) {
                        $absentDayRate = EmployeeRate::getActiveRate($employee->id, $reqDate) ?? $dailyRate;
                        $grossBasic += $absentDayRate; // Add to gross so we can deduct it
                        $absenceDeduction += $absentDayRate;
                    }
                }
                $absenceDeduction = round($absenceDeduction, 2);
                $grossBasic = round($grossBasic, 2);
            }

            $lateDeduction = round($perDayLateDeduction, 2);
            $earlyDeduction = round($perDayEarlyDeduction, 2);
            $undertimeDeduction = round($perDayUndertimeDeduction, 2);
        } else {
            // Single rate: traditional formula
            $grossBasic = round($dailyRate * $requiredMandays, 2);
            $absenceDeduction = round($dailyRate * $absentDays, 2);
            $lateDeduction = $this->computeMinuteBasedAmount($totalLateMinutes, $dailyRate);
            $earlyDeduction = $this->computeMinuteBasedAmount($totalEarlyMinutes, $dailyRate);
            $undertimeDeduction = $this->computeMinuteBasedAmount($totalUndertimeMinutes, $dailyRate);
        }

        // Basic Pay = Gross Basic - Absences - Late - Early - Undertime
        $basePay = round($grossBasic - $absenceDeduction - $lateDeduction - $earlyDeduction - $undertimeDeduction, 2);
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

        // Regular Holiday + WORKED = 200% of daily rate (use per-holiday-date rate)
        if ($regularHolidaysWorked > 0 && $dailyRate > 0) {
            $amount = 0;
            $regularHolidayDatesWorked = $mandaysData['regular_holiday_dates'] ?? [];
            foreach ($regularHolidayDatesWorked as $hDate) {
                if (isset($attendanceDateSet[$hDate])) {
                    $hRate = EmployeeRate::getActiveRate($employee->id, $hDate) ?? $dailyRate;
                    $amount += round($hRate * $this->regularHolidayWorkedRate, 2);
                }
            }
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
            $amount = 0;
            $regularHolidayDatesAll = $mandaysData['regular_holiday_dates'] ?? [];
            foreach ($regularHolidayDatesAll as $hDate) {
                if (!isset($attendanceDateSet[$hDate])) {
                    $hRate = EmployeeRate::getActiveRate($employee->id, $hDate) ?? $dailyRate;
                    $amount += round($hRate * $this->regularHolidayNotWorkedRate, 2);
                }
            }
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
            $amount = 0;
            $specialHolidayDatesAll = $mandaysData['special_holiday_dates'] ?? [];
            foreach ($specialHolidayDatesAll as $hDate) {
                if (isset($attendanceDateSet[$hDate])) {
                    $hRate = EmployeeRate::getActiveRate($employee->id, $hDate) ?? $dailyRate;
                    $amount += round($hRate * $this->specialHolidayWorkedRate, 2);
                }
            }
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
                // Per day worked — use threshold-based logic for Rice Allowance etc.
                // For each attendance day:
                //   work_minutes > 360 → 1 full day
                //   work_minutes > 120 → 0.5 day
                //   work_minutes ≤ 120 → 0 days
                $thresholdDays = 0;
                foreach ($attendanceDays as $day) {
                    $dayDateStr = $day->work_date instanceof Carbon
                        ? $day->work_date->format('Y-m-d')
                        : (string) $day->work_date;

                    // Skip rest days for rice allowance
                    $isRestDay = $employee->isDayOff($dayDateStr);
                    if ($isRestDay) continue;

                    $dayMinutes = (int) $day->payable_work_minutes;
                    if ($dayMinutes > 360) {
                        $thresholdDays += 1.0;
                    } elseif ($dayMinutes > 120) {
                        $thresholdDays += 0.5;
                    }
                    // ≤ 120 minutes = 0 (no rice allowance for that day)
                }
                $amount = round($benefit->amount * $thresholdDays, 2);
            } elseif ($benefit->benefitType->unit === 'fixed' || $benefit->benefitType->unit === 'per_cutoff') {
                // Fixed per cutoff
                $amount = round($benefit->amount, 2);
            }

            if ($amount > 0) {
                $earningsBreakdown[] = [
                    'name' => $benefit->benefitType->name,
                    'type' => $benefit->benefitType->unit,
                    'rate' => (float) $benefit->amount,
                    'days' => $benefit->benefitType->unit === 'per_day' ? $thresholdDays : null,
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
            'total_late_minutes'     => $totalLateMinutes + $totalUndertimeMinutes,
            'total_early_minutes'    => $totalEarlyMinutes,
            'total_overtime_minutes' => $totalOvertimeMinutes,
            'base_pay'               => $basePay,
            'late_deduction'         => round($lateDeduction + $undertimeDeduction, 2),
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
            'daily_breakdown'        => $dailyBreakdown,
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
