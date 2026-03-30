<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    use HasFactory;

    protected $fillable = [
        'zkteco_id',
        'full_name',
        'actual_name',
        'status',
        'default_shift_id',
        'department_id',
        'schedule_mode',
        'night_differential_eligible',
    ];

    protected $casts = [
        'status' => 'string',
        'schedule_mode' => 'string',
        'night_differential_eligible' => 'boolean',
    ];

    /* ── Schedule Mode Constants ── */
    const MODE_DEPARTMENT = 'department';
    const MODE_MANUAL     = 'manual';

    /**
     * Get the display name: actual_name if set, otherwise full_name (ZKTeco name).
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->actual_name ?: $this->full_name;
    }

    public function isDepartmentMode(): bool
    {
        return $this->schedule_mode === self::MODE_DEPARTMENT;
    }

    public function isManualMode(): bool
    {
        return $this->schedule_mode === self::MODE_MANUAL;
    }

    /* ── Existing Relationships ── */

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function defaultShift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'default_shift_id');
    }

    public function shiftAssignments(): HasMany
    {
        return $this->hasMany(EmployeeShiftAssignment::class)->orderByDesc('effective_date');
    }

    public function employeeRates(): HasMany
    {
        return $this->hasMany(EmployeeRate::class)->orderByDesc('effective_date');
    }

    public function attendanceLogs(): HasMany
    {
        return $this->hasMany(AttendanceLog::class);
    }

    public function attendanceDays(): HasMany
    {
        return $this->hasMany(AttendanceDay::class);
    }

    public function overrides(): HasMany
    {
        return $this->hasMany(AttendanceOverride::class);
    }

    public function payRates(): HasMany
    {
        return $this->hasMany(PayRate::class);
    }

    public function payrollItems(): HasMany
    {
        return $this->hasMany(PayrollItem::class);
    }

    /* ── New Relationships ── */

    public function statusHistory(): HasMany
    {
        return $this->hasMany(EmployeeStatusHistory::class)->orderByDesc('effective_from');
    }

    public function benefits(): HasMany
    {
        return $this->hasMany(EmployeeBenefit::class)->orderByDesc('effective_from');
    }

    public function restDayPatterns(): HasMany
    {
        return $this->hasMany(RestDayPattern::class)->orderByDesc('effective_from');
    }

    public function dayOffs(): HasMany
    {
        return $this->hasMany(DayOff::class);
    }

    public function cashAdvances(): HasMany
    {
        return $this->hasMany(CashAdvance::class)->orderByDesc('date_granted');
    }

    /* ── Shift Helpers ── */

    public function getShiftForDate(string $date): ?Shift
    {
        $shift = EmployeeShiftAssignment::getActiveShift($this->id, $date);
        if ($shift) return $shift;
        return $this->defaultShift;
    }

    public function getRateForDate(string $date): ?float
    {
        return EmployeeRate::getActiveRate($this->id, $date);
    }

    public function getCurrentShift(): ?Shift
    {
        return $this->getShiftForDate(now()->toDateString());
    }

    public function getCurrentRate(): ?float
    {
        return $this->getRateForDate(now()->toDateString());
    }

    /* ── Employment Status Helpers ── */

    /**
     * Get the active employment status on a given date.
     */
    public function getStatusForDate(string $date): ?EmploymentStatus
    {
        $history = $this->statusHistory()
            ->where('effective_from', '<=', $date)
            ->where(function ($q) use ($date) {
                $q->whereNull('effective_until')
                  ->orWhere('effective_until', '>=', $date);
            })
            ->orderByDesc('effective_from')
            ->first();

        return $history ? $history->employmentStatus : null;
    }

    public function getCurrentStatus(): ?EmploymentStatus
    {
        return $this->getStatusForDate(now()->toDateString());
    }

    /* ── Rest Day Helpers ── */

    /**
     * Get active rest day patterns for a given date.
     */
    public function getRestDayPatternsForDate(string $date): array
    {
        return $this->restDayPatterns()
            ->where('effective_from', '<=', $date)
            ->where(function ($q) use ($date) {
                $q->whereNull('effective_until')
                  ->orWhere('effective_until', '>=', $date);
            })
            ->pluck('day_of_week')
            ->toArray();
    }

    /**
     * Check if a given date is a day off for this employee.
     * Logic: check pattern first, then check overrides.
     */
    public function isDayOff(string $date): bool
    {
        $carbonDate = Carbon::parse($date);
        $dayOfWeek = $carbonDate->dayOfWeek; // 0=Sunday, 6=Saturday

        // Check if there's an explicit override for this date
        $override = $this->dayOffs()->where('off_date', $date)->first();

        if ($override) {
            // Explicit day_off override = definitely off
            if ($override->type === DayOff::TYPE_DAY_OFF) return true;
            // Explicit cancel_day_off = definitely NOT off (even if pattern says so)
            if ($override->type === DayOff::TYPE_CANCEL_DAY_OFF) return false;
        }

        // Check rest day patterns
        $activePatterns = $this->getRestDayPatternsForDate($date);
        return in_array($dayOfWeek, $activePatterns);
    }

    /* ── Benefits Helpers ── */

    /**
     * Get active benefit for a specific type on a given date.
     */
    public function getBenefitForDate(int $benefitTypeId, string $date): ?EmployeeBenefit
    {
        return $this->benefits()
            ->where('benefit_type_id', $benefitTypeId)
            ->where('effective_from', '<=', $date)
            ->where(function ($q) use ($date) {
                $q->whereNull('effective_until')
                  ->orWhere('effective_until', '>=', $date);
            })
            ->where('is_eligible', true)
            ->orderByDesc('effective_from')
            ->first();
    }

    /**
     * Get all active benefits on a given date.
     */
    public function getActiveBenefitsForDate(string $date): \Illuminate\Support\Collection
    {
        return $this->benefits()
            ->with('benefitType')
            ->where('effective_from', '<=', $date)
            ->where(function ($q) use ($date) {
                $q->whereNull('effective_until')
                  ->orWhere('effective_until', '>=', $date);
            })
            ->where('is_eligible', true)
            ->get();
    }

    /* ── Required Mandays Helpers ── */

    /**
     * Compute required mandays for a date range.
     * Required Mandays = calendar days - rest days - holidays
     * Returns an array with breakdown.
     */
    public function computeRequiredMandays(string $startDate, string $endDate): array
    {
        $period = \Carbon\CarbonPeriod::create($startDate, $endDate);
        $calendarDays = 0;
        $restDays = 0;
        $holidays = 0;
        $regularHolidays = 0;
        $specialHolidays = 0;
        $requiredDays = 0;
        $requiredDates = [];
        $holidayDates = [];
        $regularHolidayDates = [];
        $specialHolidayDates = [];
        $restDayDates = [];

        // Check if employee's current status is holiday-eligible
        $status = $this->getStatusForDate($endDate);
        $isHolidayEligible = $status ? $status->holiday_eligible : true; // default eligible if no status

        foreach ($period as $day) {
            $dateStr = $day->format('Y-m-d');
            $calendarDays++;

            $isRestDay = $this->isDayOff($dateStr);
            $holiday = Holiday::getHolidayForDate($dateStr);

            if ($isRestDay) {
                $restDays++;
                $restDayDates[] = $dateStr;
            } elseif ($holiday && $isHolidayEligible) {
                // Only count as holiday if employee is eligible
                $holidays++;
                $holidayDates[] = $dateStr;
                if ($holiday->type === Holiday::TYPE_REGULAR) {
                    $regularHolidays++;
                    $regularHolidayDates[] = $dateStr;
                } else {
                    $specialHolidays++;
                    $specialHolidayDates[] = $dateStr;
                }
            } else {
                // Not eligible for holiday = treated as regular working day
                $requiredDays++;
                $requiredDates[] = $dateStr;
            }
        }

        return [
            'calendar_days' => $calendarDays,
            'rest_days' => $restDays,
            'holidays' => $holidays,
            'regular_holidays' => $regularHolidays,
            'special_holidays' => $specialHolidays,
            'required_mandays' => $requiredDays,
            'required_dates' => $requiredDates,
            'holiday_dates' => $holidayDates,
            'regular_holiday_dates' => $regularHolidayDates,
            'special_holiday_dates' => $specialHolidayDates,
            'rest_day_dates' => $restDayDates,
            'holiday_eligible' => $isHolidayEligible,
        ];
    }

    /* ── Cash Advance Helpers ── */

    /**
     * Get active cash advances.
     */
    public function getActiveCashAdvances(): \Illuminate\Support\Collection
    {
        return $this->cashAdvances()->active()->get();
    }
}
