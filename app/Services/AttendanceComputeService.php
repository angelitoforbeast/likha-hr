<?php

namespace App\Services;

use App\Models\AttendanceDay;
use App\Models\AttendanceLog;
use App\Models\AttendanceOverride;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class AttendanceComputeService
{
    /**
     * Compute attendance days for a date range.
     *
     * @param bool $force If true, ignore overrides and recompute from raw logs.
     */
    public function computeForDateRange(
        string $startDate,
        string $endDate,
        ?int $sourceRunId = null,
        bool $force = false
    ): array {
        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->endOfDay();

        $stats = ['processed' => 0, 'skipped' => 0, 'errors' => 0];

        $employees = Employee::where('status', 'active')->get();

        foreach ($employees as $employee) {
            $this->computeForEmployee($employee, $start, $end, $sourceRunId, $stats, $force);
        }

        return $stats;
    }

    /**
     * Compute attendance for a single employee over a date range.
     */
    public function computeForEmployee(
        Employee $employee,
        Carbon $start,
        Carbon $end,
        ?int $sourceRunId,
        array &$stats,
        bool $force = false
    ): void {
        $punches = AttendanceLog::where('employee_id', $employee->id)
            ->whereBetween('punched_at', [$start, $end])
            ->orderBy('punched_at')
            ->get();

        $punchesByDate = $punches->groupBy(function ($log) {
            return Carbon::parse($log->punched_at)->format('Y-m-d');
        });

        $current = $start->copy();
        while ($current->lte($end)) {
            $dateStr = $current->format('Y-m-d');
            $dayPunches = $punchesByDate->get($dateStr, collect());

            if ($dayPunches->isNotEmpty()) {
                try {
                    $shift = $employee->getShiftForDate($dateStr);

                    if (!$force) {
                        // Check if this day has any overrides
                        $existingDay = AttendanceDay::where('employee_id', $employee->id)
                            ->where('work_date', $dateStr)
                            ->first();

                        if ($existingDay) {
                            $overriddenFields = AttendanceOverride::where('attendance_day_id', $existingDay->id)
                                ->pluck('field')
                                ->unique()
                                ->toArray();

                            if (!empty($overriddenFields)) {
                                // Has overrides — do override-aware compute
                                $this->computeDayWithOverrides(
                                    $employee, $dateStr, $dayPunches, $shift,
                                    $sourceRunId, $existingDay, $overriddenFields
                                );
                                $stats['processed']++;
                                $current->addDay();
                                continue;
                            }
                        }
                    }

                    // Normal compute (no overrides, or force mode)
                    $this->computeDay($employee, $dateStr, $dayPunches, $shift, $sourceRunId);
                    $stats['processed']++;
                } catch (\Throwable $e) {
                    Log::error("Error computing day for employee #{$employee->id} on {$dateStr}: " . $e->getMessage());
                    $stats['errors']++;
                }
            }

            $current->addDay();
        }
    }

    /**
     * Round a Carbon time UP to the next whole minute (ceil).
     */
    protected function ceilToMinute(Carbon $time): Carbon
    {
        $rounded = $time->copy()->second(0);
        if ($time->second > 0) {
            $rounded->addMinute();
        }
        return $rounded;
    }

    /**
     * Round a Carbon time DOWN to the current whole minute (floor).
     */
    protected function floorToMinute(Carbon $time): Carbon
    {
        return $time->copy()->second(0);
    }

    /**
     * Assign timestamps using "closest to shift event" rules.
     *
     * Rules:
     * - Time In: punch closest to shift start
     * - Lunch Out: punch closest to lunch start (from remaining punches)
     * - Lunch In: punch closest to lunch end (from remaining punches)
     * - Time Out: punch closest to shift end (from remaining punches)
     *
     * Each punch can only be assigned to one event (no duplicates).
     * Deduplication: if two punches have the exact same time, only one is used.
     */
    protected function assignTimestamps(Collection $punches, ?\App\Models\Shift $shift, string $workDate): array
    {
        $result = [
            'time_in'   => null,
            'lunch_out' => null,
            'lunch_in'  => null,
            'time_out'  => null,
            'notes'     => [],
            'needs_review' => false,
        ];

        $sorted = $punches->sortBy('punched_at')->values();

        if ($sorted->isEmpty()) {
            return $result;
        }

        // Deduplicate: remove punches with exact same timestamp
        $uniquePunches = collect();
        $lastTime = null;
        foreach ($sorted as $punch) {
            $punchTime = Carbon::parse($punch->punched_at);
            if ($lastTime === null || !$punchTime->eq($lastTime)) {
                $uniquePunches->push($punch);
                $lastTime = $punchTime;
            }
        }

        if (!$shift) {
            // No shift — just use first as time in, last as time out
            $result['time_in'] = Carbon::parse($uniquePunches->first()->punched_at);
            if ($uniquePunches->count() > 1) {
                $result['time_out'] = Carbon::parse($uniquePunches->last()->punched_at);
            }
            $result['needs_review'] = true;
            $result['notes'][] = 'No shift assigned to employee.';
            return $result;
        }

        $shiftStart = Carbon::parse($workDate . ' ' . $shift->start_time);
        $shiftEnd = Carbon::parse($workDate . ' ' . $shift->end_time);
        $lunchStart = $shift->lunch_start ? Carbon::parse($workDate . ' ' . $shift->lunch_start) : null;
        $lunchEnd = $shift->lunch_end ? Carbon::parse($workDate . ' ' . $shift->lunch_end) : null;

        // Build array of punch times with indices for tracking
        $available = [];
        foreach ($uniquePunches as $idx => $punch) {
            $available[$idx] = Carbon::parse($punch->punched_at);
        }

        // Step 1: Time In — closest to shift start
        $timeInIdx = $this->findClosest($available, $shiftStart);
        if ($timeInIdx !== null) {
            $result['time_in'] = $available[$timeInIdx];
            unset($available[$timeInIdx]);
        }

        // Step 2: Lunch Out — closest to lunch start (if lunch exists and punches remain)
        if ($lunchStart && !empty($available)) {
            $lunchOutIdx = $this->findClosest($available, $lunchStart);
            if ($lunchOutIdx !== null) {
                $result['lunch_out'] = $available[$lunchOutIdx];
                unset($available[$lunchOutIdx]);
            }
        }

        // Step 3: Lunch In — closest to lunch end (if lunch exists and punches remain)
        if ($lunchEnd && !empty($available)) {
            $lunchInIdx = $this->findClosest($available, $lunchEnd);
            if ($lunchInIdx !== null) {
                $result['lunch_in'] = $available[$lunchInIdx];
                unset($available[$lunchInIdx]);
            }
        }

        // Step 4: Time Out — closest to shift end (from remaining punches)
        if (!empty($available)) {
            $timeOutIdx = $this->findClosest($available, $shiftEnd);
            if ($timeOutIdx !== null) {
                $result['time_out'] = $available[$timeOutIdx];
                unset($available[$timeOutIdx]);
            }
        }

        // Validation: ensure logical order
        // Time In should be before Lunch Out
        if ($result['time_in'] && $result['lunch_out'] && $result['time_in']->gt($result['lunch_out'])) {
            $result['needs_review'] = true;
            $result['notes'][] = 'Time In is after Lunch Out (order issue).';
        }

        // Lunch Out should be before Lunch In
        if ($result['lunch_out'] && $result['lunch_in'] && $result['lunch_out']->gt($result['lunch_in'])) {
            $result['needs_review'] = true;
            $result['notes'][] = 'Lunch Out is after Lunch In (order issue).';
        }

        // Lunch In should be before Time Out
        if ($result['lunch_in'] && $result['time_out'] && $result['lunch_in']->gt($result['time_out'])) {
            $result['needs_review'] = true;
            $result['notes'][] = 'Lunch In is after Time Out (order issue).';
        }

        // If only 1 punch, no time out
        if ($uniquePunches->count() === 1) {
            $result['needs_review'] = true;
            $result['notes'][] = 'Only 1 punch recorded.';
        }

        // If lunch punches missing
        if ($lunchStart && (!$result['lunch_out'] || !$result['lunch_in'])) {
            $result['needs_review'] = true;
            $result['notes'][] = 'Lunch punches could not be inferred.';
        }

        // If no time out assigned
        if (!$result['time_out']) {
            $result['needs_review'] = true;
            $result['notes'][] = 'No Time Out recorded (missed timeout).';
        }

        return $result;
    }

    /**
     * Find the index of the punch closest to a target time.
     */
    protected function findClosest(array $available, Carbon $target): ?int
    {
        $bestIdx = null;
        $bestDiff = PHP_INT_MAX;

        foreach ($available as $idx => $punchTime) {
            $diff = abs($punchTime->diffInSeconds($target));
            if ($diff < $bestDiff) {
                $bestDiff = $diff;
                $bestIdx = $idx;
            }
        }

        return $bestIdx;
    }

    /**
     * Compute a single attendance day from raw logs (no override protection).
     */
    public function computeDay(
        Employee $employee,
        string $workDate,
        Collection $punches,
        ?\App\Models\Shift $shift,
        ?int $sourceRunId = null
    ): AttendanceDay {
        // Use new timestamp assignment rules
        $assigned = $this->assignTimestamps($punches, $shift, $workDate);

        $rawTimeIn = $assigned['time_in'];
        $rawTimeOut = $assigned['time_out'];
        $lunchOut = $assigned['lunch_out'];
        $lunchIn = $assigned['lunch_in'];
        $needsReview = $assigned['needs_review'];
        $notes = $assigned['notes'];

        $computeTimeIn = $rawTimeIn ? $this->ceilToMinute($rawTimeIn) : null;
        $computeTimeOut = $rawTimeOut ? $this->floorToMinute($rawTimeOut) : null;

        $computed = $this->computeMetrics($computeTimeIn, $computeTimeOut, $lunchOut, $lunchIn, $shift, $workDate);

        $day = AttendanceDay::updateOrCreate(
            [
                'employee_id' => $employee->id,
                'work_date'   => $workDate,
            ],
            [
                'shift_id'                  => $shift?->id,
                'time_in'                   => $rawTimeIn,
                'lunch_out'                 => $lunchOut,
                'lunch_in'                  => $lunchIn,
                'time_out'                  => $rawTimeOut,
                'computed_work_minutes'     => $computed['work_minutes'],
                'computed_late_minutes'     => $computed['late_minutes'],
                'computed_early_minutes'    => $computed['early_minutes'],
                'computed_overtime_minutes' => $computed['overtime_minutes'],
                'payable_work_minutes'      => $computed['work_minutes'],
                'needs_review'              => $needsReview,
                'notes'                     => !empty($notes) ? implode(' ', $notes) : null,
                'source_run_id'             => $sourceRunId,
            ]
        );

        return $day;
    }

    /**
     * Compute a day while preserving overridden fields.
     * Overridden fields keep their current (edited) values.
     * Non-overridden fields get fresh values from raw logs.
     * Metrics (work/late/early/OT) are always recomputed based on the final values.
     */
    protected function computeDayWithOverrides(
        Employee $employee,
        string $workDate,
        Collection $punches,
        ?\App\Models\Shift $shift,
        ?int $sourceRunId,
        AttendanceDay $existingDay,
        array $overriddenFields
    ): AttendanceDay {
        $needsReview = false;
        $notes = [];

        // Use new timestamp assignment rules for fresh values
        $assigned = $this->assignTimestamps($punches, $shift, $workDate);

        $freshTimeIn = $assigned['time_in'];
        $freshTimeOut = $assigned['time_out'];
        $freshLunchOut = $assigned['lunch_out'];
        $freshLunchIn = $assigned['lunch_in'];

        if (!$shift) {
            $needsReview = true;
            $notes[] = 'No shift assigned to employee.';
        }

        // Determine final values: use existing (edited) value if field was overridden, otherwise use fresh
        $finalTimeIn = in_array('time_in', $overriddenFields)
            ? ($existingDay->time_in ? Carbon::parse($existingDay->time_in) : null)
            : $freshTimeIn;

        $finalTimeOut = in_array('time_out', $overriddenFields)
            ? ($existingDay->time_out ? Carbon::parse($existingDay->time_out) : null)
            : $freshTimeOut;

        $finalLunchOut = in_array('lunch_out', $overriddenFields)
            ? ($existingDay->lunch_out ? Carbon::parse($existingDay->lunch_out) : null)
            : $freshLunchOut;

        $finalLunchIn = in_array('lunch_in', $overriddenFields)
            ? ($existingDay->lunch_in ? Carbon::parse($existingDay->lunch_in) : null)
            : $freshLunchIn;

        $finalShiftId = in_array('shift_id', $overriddenFields)
            ? $existingDay->shift_id
            : ($shift?->id);

        // If shift was overridden, load that shift for metrics computation
        $computeShift = in_array('shift_id', $overriddenFields)
            ? \App\Models\Shift::find($existingDay->shift_id)
            : $shift;

        if ($computeShift && (!$finalLunchOut || !$finalLunchIn)) {
            $needsReview = true;
            $notes[] = 'Lunch punches could not be inferred.';
        }

        if (!$finalTimeOut) {
            $needsReview = true;
            $notes[] = 'No Time Out recorded (missed timeout).';
        }

        // Compute metrics using the final values (mix of edited + fresh)
        $computeTimeIn = $finalTimeIn ? $this->ceilToMinute($finalTimeIn) : null;
        $computeTimeOut = $finalTimeOut ? $this->floorToMinute($finalTimeOut) : null;

        $computed = $this->computeMetrics(
            $computeTimeIn, $computeTimeOut,
            $finalLunchOut, $finalLunchIn,
            $computeShift, $workDate
        );

        // Track which fields were preserved
        $preservedFields = [];
        if (in_array('time_in', $overriddenFields)) $preservedFields[] = 'time_in';
        if (in_array('time_out', $overriddenFields)) $preservedFields[] = 'time_out';
        if (in_array('lunch_out', $overriddenFields)) $preservedFields[] = 'lunch_out';
        if (in_array('lunch_in', $overriddenFields)) $preservedFields[] = 'lunch_in';
        if (in_array('shift_id', $overriddenFields)) $preservedFields[] = 'shift';

        if (!empty($preservedFields)) {
            $notes[] = 'Preserved overrides: ' . implode(', ', $preservedFields) . '.';
        }

        $existingDay->update([
            'shift_id'                  => $finalShiftId,
            'time_in'                   => $finalTimeIn,
            'lunch_out'                 => $finalLunchOut,
            'lunch_in'                  => $finalLunchIn,
            'time_out'                  => $finalTimeOut,
            'computed_work_minutes'     => $computed['work_minutes'],
            'computed_late_minutes'     => $computed['late_minutes'],
            'computed_early_minutes'    => $computed['early_minutes'],
            'computed_overtime_minutes' => $computed['overtime_minutes'],
            'payable_work_minutes'      => $computed['work_minutes'],
            'needs_review'              => $needsReview,
            'notes'                     => !empty($notes) ? implode(' ', $notes) : null,
            'source_run_id'             => $sourceRunId,
        ]);

        return $existingDay->fresh();
    }

    /**
     * Recompute a single attendance day (used after overrides).
     */
    public function recomputeDay(AttendanceDay $day): AttendanceDay
    {
        $shift = $day->shift;

        $computeTimeIn = $day->time_in ? $this->ceilToMinute(Carbon::parse($day->time_in)) : null;
        $computeTimeOut = $day->time_out ? $this->floorToMinute(Carbon::parse($day->time_out)) : null;

        $computed = $this->computeMetrics(
            $computeTimeIn,
            $computeTimeOut,
            $day->lunch_out ? Carbon::parse($day->lunch_out) : null,
            $day->lunch_in ? Carbon::parse($day->lunch_in) : null,
            $shift,
            $day->work_date->format('Y-m-d')
        );

        $day->update([
            'computed_work_minutes'     => $computed['work_minutes'],
            'computed_late_minutes'     => $computed['late_minutes'],
            'computed_early_minutes'    => $computed['early_minutes'],
            'computed_overtime_minutes' => $computed['overtime_minutes'],
            'payable_work_minutes'      => $computed['work_minutes'],
        ]);

        return $day->fresh();
    }

    /**
     * Force recompute: delete all overrides for the date range and recompute from raw logs.
     * Returns count of overrides deleted.
     */
    public function forceRecomputeForDateRange(
        string $startDate,
        string $endDate,
        ?int $sourceRunId = null
    ): array {
        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->endOfDay();

        // Count overrides that will be deleted
        $dayIds = AttendanceDay::whereBetween('work_date', [$startDate, $endDate])
            ->pluck('id')
            ->toArray();

        $overridesDeleted = AttendanceOverride::whereIn('attendance_day_id', $dayIds)->count();

        // Delete all overrides for these days
        AttendanceOverride::whereIn('attendance_day_id', $dayIds)->delete();

        // Delete all attendance days so they get freshly computed
        AttendanceDay::whereBetween('work_date', [$startDate, $endDate])->delete();

        // Recompute from scratch
        $stats = $this->computeForDateRange($startDate, $endDate, $sourceRunId, true);
        $stats['overrides_deleted'] = $overridesDeleted;

        return $stats;
    }

    /**
     * Count overrides in a date range (for warning before force recompute).
     */
    public function countOverridesInRange(string $startDate, string $endDate): int
    {
        $dayIds = AttendanceDay::whereBetween('work_date', [$startDate, $endDate])
            ->pluck('id')
            ->toArray();

        return AttendanceOverride::whereIn('attendance_day_id', $dayIds)->count();
    }

    /**
     * Compute work minutes, late, early, overtime.
     *
     * Work minutes: actual time worked within shift periods (morning + afternoon), excluding lunch.
     * Late: minutes arrived after shift start (only counted if after grace period).
     * Early: remaining WORK minutes not worked because of early departure (lunch excluded).
     * OT: minutes worked after shift end.
     */
    protected function computeMetrics(
        ?Carbon $timeIn,
        ?Carbon $timeOut,
        ?Carbon $lunchOut,
        ?Carbon $lunchIn,
        ?\App\Models\Shift $shift,
        string $workDate
    ): array {
        $result = [
            'work_minutes'     => 0,
            'late_minutes'     => 0,
            'early_minutes'    => 0,
            'overtime_minutes' => 0,
        ];

        if (!$shift || !$timeIn) {
            return $result;
        }

        $shiftStart = Carbon::parse($workDate . ' ' . $shift->start_time);
        $shiftEnd = Carbon::parse($workDate . ' ' . $shift->end_time);
        $shiftLunchStart = $shift->lunch_start ? Carbon::parse($workDate . ' ' . $shift->lunch_start) : null;
        $shiftLunchEnd = $shift->lunch_end ? Carbon::parse($workDate . ' ' . $shift->lunch_end) : null;

        $hasLunch = $shiftLunchStart && $shiftLunchEnd;

        // === WORK MINUTES ===
        // Calculate actual work time within shift periods, excluding lunch
        if ($hasLunch) {
            // Morning period: shift start to lunch start
            $morningActualStart = $timeIn;
            $morningActualEnd = $lunchOut ?? ($timeOut ?? $shiftLunchStart);
            // Cap morning end to lunch start
            if ($morningActualEnd->gt($shiftLunchStart)) {
                $morningActualEnd = $shiftLunchStart;
            }
            $morningMinutes = $this->overlapMinutes($morningActualStart, $morningActualEnd, $shiftStart, $shiftLunchStart);

            // Afternoon period: lunch end to shift end
            $afternoonActualStart = $lunchIn ?? $shiftLunchEnd;
            $afternoonActualEnd = $timeOut ?? $shiftEnd;
            // If no time out, we don't assume they stayed till shift end — mark 0 afternoon
            if (!$timeOut) {
                // Check if they at least came back from lunch
                if ($lunchIn && $lunchIn->gte($shiftLunchEnd)) {
                    // They came back but no time out — we can't compute afternoon
                    $afternoonMinutes = 0;
                } else {
                    $afternoonMinutes = 0;
                }
            } else {
                $afternoonMinutes = $this->overlapMinutes($afternoonActualStart, $afternoonActualEnd, $shiftLunchEnd, $shiftEnd);
            }
        } else {
            // No lunch break — single work period
            $morningMinutes = $this->overlapMinutes($timeIn, $timeOut ?? $shiftEnd, $shiftStart, $shiftEnd);
            $afternoonMinutes = 0;
            if (!$timeOut) {
                $morningMinutes = 0;
            }
        }

        $result['work_minutes'] = max(0, $morningMinutes + $afternoonMinutes);

        // === LATE MINUTES ===
        // Late = minutes arrived after shift start (with grace period)
        $graceDeadline = $shiftStart->copy()->addMinutes($shift->grace_in_minutes);
        if ($timeIn->gt($graceDeadline)) {
            // Late minutes = only the minutes within the morning work period that were missed
            // i.e., from shift start to time in, but only within shift start to lunch start
            if ($hasLunch) {
                $lateEnd = $timeIn->min($shiftLunchStart); // cap at lunch start
                $result['late_minutes'] = max(0, (int) $shiftStart->diffInMinutes($lateEnd));
            } else {
                $lateEnd = $timeIn->min($shiftEnd);
                $result['late_minutes'] = max(0, (int) $shiftStart->diffInMinutes($lateEnd));
            }
        }

        // === EARLY MINUTES ===
        // Early = remaining WORK minutes not worked because of early departure
        // Lunch break is excluded (invisible)
        if ($timeOut) {
            $earlyDeadline = $shiftEnd->copy()->subMinutes($shift->grace_out_minutes);
            if ($timeOut->lt($earlyDeadline)) {
                if ($hasLunch) {
                    // Calculate remaining work minutes from time out to shift end, excluding lunch
                    $earlyMinutes = 0;

                    if ($timeOut->lt($shiftLunchStart)) {
                        // Left before lunch — missed: (lunch start - time out) in morning + entire afternoon
                        $morningMissed = (int) $timeOut->diffInMinutes($shiftLunchStart);
                        $afternoonMissed = (int) $shiftLunchEnd->diffInMinutes($shiftEnd);
                        $earlyMinutes = $morningMissed + $afternoonMissed;
                    } elseif ($timeOut->lte($shiftLunchEnd)) {
                        // Left during lunch — missed entire afternoon
                        $afternoonMissed = (int) $shiftLunchEnd->diffInMinutes($shiftEnd);
                        $earlyMinutes = $afternoonMissed;
                    } else {
                        // Left after lunch — missed: (shift end - time out) in afternoon
                        $earlyMinutes = (int) $timeOut->diffInMinutes($shiftEnd);
                    }

                    $result['early_minutes'] = max(0, $earlyMinutes);
                } else {
                    // No lunch — simple subtraction
                    $result['early_minutes'] = max(0, (int) $timeOut->diffInMinutes($shiftEnd));
                }
            }
        }

        // === OVERTIME MINUTES ===
        if ($timeOut && $timeOut->gt($shiftEnd)) {
            $result['overtime_minutes'] = (int) $shiftEnd->diffInMinutes($timeOut);
        }

        return $result;
    }

    /**
     * Calculate overlap in minutes between two time ranges.
     */
    protected function overlapMinutes(Carbon $start1, Carbon $end1, Carbon $start2, Carbon $end2): int
    {
        $overlapStart = $start1->max($start2);
        $overlapEnd = $end1->min($end2);

        if ($overlapStart->gte($overlapEnd)) {
            return 0;
        }

        return (int) $overlapStart->diffInMinutes($overlapEnd);
    }
}
