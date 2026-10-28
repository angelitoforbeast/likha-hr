<?php

namespace App\Services;

use App\Models\AttendanceDateLock;
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
        $computeLunchOut = $lunchOut ? $this->floorToMinute($lunchOut) : null;
        $computeLunchIn = $lunchIn ? $this->ceilToMinute($lunchIn) : null;

        $computed = $this->computeMetrics($computeTimeIn, $computeTimeOut, $computeLunchOut, $computeLunchIn, $shift, $workDate);

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
     *
     * @param bool $keepStoredTimes every time field keeps its stored value, overridden or not:
     *                              the recheck after an approval (see recheckDay)
     */
    protected function computeDayWithOverrides(
        Employee $employee,
        string $workDate,
        Collection $punches,
        ?\App\Models\Shift $shift,
        ?int $sourceRunId,
        AttendanceDay $existingDay,
        array $overriddenFields,
        bool $keepStoredTimes = false
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

        $keep = fn (string $field) => $keepStoredTimes || in_array($field, $overriddenFields);

        // Determine final values: use existing (edited) value if field was overridden, otherwise use fresh
        $finalTimeIn = $keep('time_in')
            ? ($existingDay->time_in ? Carbon::parse($existingDay->time_in) : null)
            : $freshTimeIn;

        $finalTimeOut = $keep('time_out')
            ? ($existingDay->time_out ? Carbon::parse($existingDay->time_out) : null)
            : $freshTimeOut;

        $finalLunchOut = $keep('lunch_out')
            ? ($existingDay->lunch_out ? Carbon::parse($existingDay->lunch_out) : null)
            : $freshLunchOut;

        $finalLunchIn = $keep('lunch_in')
            ? ($existingDay->lunch_in ? Carbon::parse($existingDay->lunch_in) : null)
            : $freshLunchIn;

        $finalShiftId = in_array('shift_id', $overriddenFields)
            ? $existingDay->shift_id
            : ($shift?->id);

        // If shift was overridden, load that shift for metrics computation
        $computeShift = in_array('shift_id', $overriddenFields)
            ? \App\Models\Shift::find($existingDay->shift_id)
            : $shift;

        // The checks of the import's own compute, on the final values: a time in that was
        // cleared, or edited times out of order, leave the day as much in need of review
        // as the same problems straight from the punches.
        if (!$finalTimeIn) {
            $needsReview = true;
            $notes[] = 'No Time In recorded.';
        }
        foreach ([
            ['Time In', $finalTimeIn, 'Lunch Out', $finalLunchOut],
            ['Lunch Out', $finalLunchOut, 'Lunch In', $finalLunchIn],
            ['Lunch In', $finalLunchIn, 'Time Out', $finalTimeOut],
        ] as [$firstLabel, $first, $secondLabel, $second]) {
            if ($first && $second && $first->gt($second)) {
                $needsReview = true;
                $notes[] = "{$firstLabel} is after {$secondLabel} (order issue).";
            }
        }

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
        $computeLunchOut = $finalLunchOut ? $this->floorToMinute($finalLunchOut) : null;
        $computeLunchIn = $finalLunchIn ? $this->ceilToMinute($finalLunchIn) : null;
        $computed = $this->computeMetrics(
            $computeTimeIn, $computeTimeOut,
            $computeLunchOut, $computeLunchIn,
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
     * Recheck one existing day row after a change to it was made official: the
     * override-aware compute of "Compute Attendance" (force = false) for that day, with
     * every stored time kept. "Needs review" and the note are decided again from the
     * final times, so a problem an approval fixed is no longer reported, and the minutes
     * are recomputed from them with the shift of the date, as after any edit.
     *
     * Every time is kept, not only the overridden ones: the others were already built
     * from the punches, and building them again could move them to other slots (after a
     * shift change, say) and with them the pay of a day nobody asked to change. Approved
     * overtime, SIL, rest days and the override rows are not touched, and the import run
     * the day came from stays named. Unlike "Compute Attendance", errors are not
     * swallowed: the caller's transaction must fail with them.
     */
    public function recheckDay(AttendanceDay $day): AttendanceDay
    {
        $employee = $day->employee()->firstOrFail();
        $workDate = $day->work_date->format('Y-m-d');

        // The fallback recomputeDay uses, so the minutes an edit just computed stay.
        $shift = $employee->getShiftForDate($workDate) ?: $day->shift;
        $overriddenFields = AttendanceOverride::where('attendance_day_id', $day->id)
            ->pluck('field')
            ->unique()
            ->values()
            ->all();

        return $this->computeDayWithOverrides(
            $employee, $workDate, collect(), $shift, $day->source_run_id, $day, $overriddenFields, true
        );
    }

    /**
     * Recompute a single attendance day (used after overrides).
     */
    public function recomputeDay(AttendanceDay $day): AttendanceDay
    {
        // Always resolve the shift that is EFFECTIVE for this employee on this date, so late/OT
        // calculations use the currently-assigned shift — not a stale shift_id that was captured
        // when the AttendanceDay was first created. If the effective shift differs from what's
        // stored on the AttendanceDay, we also sync it so future queries see the correct shift.
        $workDateStr = $day->work_date->format('Y-m-d');
        $effectiveShift = $day->employee?->getShiftForDate($workDateStr);
        $shift = $effectiveShift ?: $day->shift;

        $computeTimeIn = $day->time_in ? $this->ceilToMinute(Carbon::parse($day->time_in)) : null;
        $computeTimeOut = $day->time_out ? $this->floorToMinute(Carbon::parse($day->time_out)) : null;
        $computeLunchOut = $day->lunch_out ? $this->floorToMinute(Carbon::parse($day->lunch_out)) : null;
        $computeLunchIn = $day->lunch_in ? $this->ceilToMinute(Carbon::parse($day->lunch_in)) : null;
        $computed = $this->computeMetrics(
            $computeTimeIn,
            $computeTimeOut,
            $computeLunchOut,
            $computeLunchIn,
            $shift,
            $workDateStr
        );

        $updates = [
            'computed_work_minutes'     => $computed['work_minutes'],
            'computed_late_minutes'     => $computed['late_minutes'],
            'computed_early_minutes'    => $computed['early_minutes'],
            'computed_overtime_minutes' => $computed['overtime_minutes'],
            'payable_work_minutes'      => $computed['work_minutes'],
        ];
        if ($effectiveShift && $effectiveShift->id !== $day->shift_id) {
            $updates['shift_id'] = $effectiveShift->id;
        }
        $day->update($updates);

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
     * Compute a given list of employee-days and nothing else. Used by the import, which
     * must fill in the days its file names without touching other dates or employees.
     *
     * Each day goes through the same override-aware compute as "Compute Attendance"
     * (force = false). Unlike that button, a locked date is skipped whole here: an import
     * runs unattended, so nobody would notice it changing a date that was closed.
     * Only active employees are computed; the others are counted nowhere.
     *
     * A day is computed only when it received a new punch or has no day row yet. A day
     * that already has a row and got nothing new is left as it is and counted nowhere:
     * recomputing it would change days computed earlier whenever a shift assignment or
     * the calculation changed in between, and nobody asked for that. Refreshing such
     * days stays a deliberate act, the "Compute Attendance" button.
     *
     * @param array<int, array<string, bool>> $employeeDays employee id => ['Y-m-d' => whether
     *                                                      the day received a new punch]
     * @return array{days_computed:int, locked_skipped:int, errors:int}
     */
    public function computeEmployeeDays(array $employeeDays, ?int $sourceRunId = null): array
    {
        $summary = ['days_computed' => 0, 'locked_skipped' => 0, 'errors' => 0];

        $allDates = array_map('strval', array_merge([], ...array_map('array_keys', array_values($employeeDays))));
        if (empty($allDates)) {
            return $summary;
        }

        // whereDate, because the stored value carries a time part on some database drivers.
        $lockedDates = AttendanceDateLock::whereDate('lock_date', '>=', min($allDates))
            ->whereDate('lock_date', '<=', max($allDates))
            ->get()
            ->mapWithKeys(fn ($lock) => [$lock->lock_date->format('Y-m-d') => true])
            ->all();

        $employees = Employee::whereIn('id', array_keys($employeeDays))
            ->where('status', 'active')
            ->get();

        foreach ($employees as $employee) {
            $days = $employeeDays[$employee->id] ?? [];
            ksort($days);

            foreach ($days as $date => $hasNewPunch) {
                $date = (string) $date;

                if (isset($lockedDates[$date])) {
                    $summary['locked_skipped']++;
                    continue;
                }

                if (!$hasNewPunch && $this->dayRowExists($employee->id, $date)) {
                    continue;
                }

                // One call per day: a range would also compute dates that are not in the list.
                $stats = ['processed' => 0, 'skipped' => 0, 'errors' => 0];
                $this->computeForEmployee(
                    $employee,
                    Carbon::parse($date)->startOfDay(),
                    Carbon::parse($date)->endOfDay(),
                    $sourceRunId,
                    $stats,
                    false
                );
                $summary['days_computed'] += $stats['processed'];
                $summary['errors'] += $stats['errors'];
            }
        }

        return $summary;
    }

    /**
     * Whether the employee already has a day row for the date.
     */
    protected function dayRowExists(int $employeeId, string $date): bool
    {
        // whereDate, because the stored value carries a time part on some database drivers.
        return AttendanceDay::where('employee_id', $employeeId)
            ->whereDate('work_date', $date)
            ->exists();
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

        if (!$shift) {
            return $result;
        }

        // === MISSING TIME IN SCENARIO ===
        // No Time In, but has Lunch In + Time Out → compute afternoon portion only
        // The entire morning period is counted as late minutes
        if (!$timeIn && $lunchIn && $timeOut) {
            $shiftStart = Carbon::parse($workDate . ' ' . $shift->start_time);
            $shiftEnd = Carbon::parse($workDate . ' ' . $shift->end_time);
            $shiftLunchStart = $shift->lunch_start ? Carbon::parse($workDate . ' ' . $shift->lunch_start) : null;
            $shiftLunchEnd = $shift->lunch_end ? Carbon::parse($workDate . ' ' . $shift->lunch_end) : null;

            // Afternoon work minutes: from lunchIn to timeOut, within shift afternoon period
            if ($shiftLunchEnd) {
                $afternoonMinutes = $this->overlapMinutes($lunchIn, $timeOut, $shiftLunchEnd, $shiftEnd);
            } else {
                $afternoonMinutes = 0;
            }
            $result['work_minutes'] = max(0, $afternoonMinutes);

            // Late minutes = entire morning period (shift start to lunch start)
            $lateMinutes = 0;
            if ($shiftLunchStart) {
                $lateMinutes += max(0, (int) $shiftStart->diffInMinutes($shiftLunchStart));
            }
            // Also add late lunch return if lunchIn is after scheduled lunch end
            if ($shiftLunchEnd && $lunchIn->gt($shiftLunchEnd)) {
                $lateLunchEnd = $lunchIn->min($shiftEnd);
                $lateMinutes += max(0, (int) $shiftLunchEnd->diffInMinutes($lateLunchEnd));
            }
            $result['late_minutes'] = max(0, $lateMinutes);

            // Early minutes: if timeOut is before shift end
            if ($timeOut->lt($shiftEnd)) {
                $earlyDeadline = $shiftEnd->copy()->subMinutes($shift->grace_out_minutes);
                if ($timeOut->lt($earlyDeadline)) {
                    $result['early_minutes'] = max(0, (int) $timeOut->diffInMinutes($shiftEnd));
                }
            }

            // Overtime: if timeOut is after shift end
            if ($timeOut->gt($shiftEnd)) {
                $result['overtime_minutes'] = (int) $shiftEnd->diffInMinutes($timeOut);
            }

            return $result;
        }

        // Normal scenario: if no timeIn and no lunchIn, nothing to compute
        if (!$timeIn) {
            return $result;
        }

        // 1-punch-only scenario: Time In exists but no Time Out and no Lunch punches
        // This means only 1 punch was recorded — treat as absent (0 work minutes)
        // HR can manually override Time Out if the employee actually worked
        if (!$timeOut && !$lunchOut && !$lunchIn) {
            return $result;
        }

        $shiftStart = Carbon::parse($workDate . ' ' . $shift->start_time);
        $shiftEnd = Carbon::parse($workDate . ' ' . $shift->end_time);
        $shiftLunchStart = $shift->lunch_start ? Carbon::parse($workDate . ' ' . $shift->lunch_start) : null;
        $shiftLunchEnd = $shift->lunch_end ? Carbon::parse($workDate . ' ' . $shift->lunch_end) : null;

        $hasLunch = $shiftLunchStart && $shiftLunchEnd;

        // === WORK MINUTES (STRICT RULE) ===
        // Every punch is required to credit its half of the day. Any missing
        // punch zeroes out the corresponding half — no scheduled fallback,
        // no on-time-return assumption.
        //   - Missing Time In    → morning = 0 (handled earlier for the no-time-in branch)
        //   - Missing Lunch Out  → morning = 0 (change from previous lenient rule)
        //   - Missing Lunch In   → afternoon = 0 (change from previous lenient rule)
        //   - Missing Time Out   → afternoon = 0
        //
        // Result: to earn a full 8h, all 4 punches must be present. HR can
        // still fill in the missing punch manually via the calendar override.
        if ($hasLunch) {
            // Morning period: shift start to lunch start
            if (!$lunchOut) {
                $morningMinutes = 0;
                $result['notes'][] = 'Morning = 0 (missing Lunch Out — strict rule).';
            } else {
                $morningActualStart = $timeIn;
                $morningActualEnd   = $lunchOut;
                // Cap morning end to scheduled lunch start so late lunch-out
                // does not spill into the lunch window as work.
                if ($morningActualEnd->gt($shiftLunchStart)) {
                    $morningActualEnd = $shiftLunchStart;
                }
                $morningMinutes = $this->overlapMinutes($morningActualStart, $morningActualEnd, $shiftStart, $shiftLunchStart);
            }

            // Afternoon period: lunch end to shift end
            if (!$lunchIn) {
                $afternoonMinutes = 0;
                $result['notes'][] = 'Afternoon = 0 (missing Lunch In — strict rule).';
            } elseif (!$timeOut) {
                $afternoonMinutes = 0;
                $result['notes'][] = 'Afternoon = 0 (missing Time Out — strict rule).';
            } else {
                $afternoonActualStart = $lunchIn;
                $afternoonActualEnd   = $timeOut;
                $afternoonMinutes = $this->overlapMinutes($afternoonActualStart, $afternoonActualEnd, $shiftLunchEnd, $shiftEnd);
            }
        } else {
            // No lunch break — single work period. Both Time In and Time Out
            // required for any credit.
            if (!$timeOut) {
                $morningMinutes = 0;
                $result['notes'][] = 'Work = 0 (missing Time Out — strict rule).';
            } else {
                $morningMinutes = $this->overlapMinutes($timeIn, $timeOut, $shiftStart, $shiftEnd);
            }
            $afternoonMinutes = 0;
        }

        $result['work_minutes'] = max(0, $morningMinutes + $afternoonMinutes);

        // === LATE MINUTES ===
        // Late = minutes arrived after shift start (with grace period) + late lunch return
        $lateMinutes = 0;

        // A) Late morning arrival
        $graceDeadline = $shiftStart->copy()->addMinutes($shift->grace_in_minutes);
        if ($timeIn->gt($graceDeadline)) {
            // Late minutes = only the minutes within the morning work period that were missed
            // i.e., from shift start to time in, but only within shift start to lunch start
            if ($hasLunch) {
                $lateEnd = $timeIn->min($shiftLunchStart); // cap at lunch start
                $lateMinutes += max(0, (int) $shiftStart->diffInMinutes($lateEnd));
            } else {
                $lateEnd = $timeIn->min($shiftEnd);
                $lateMinutes += max(0, (int) $shiftStart->diffInMinutes($lateEnd));
            }
        }

        // B) Late lunch return: came back from lunch after scheduled lunch end
        if ($hasLunch && $lunchIn && $lunchIn->gt($shiftLunchEnd)) {
            $lateLunchEnd = $lunchIn->min($shiftEnd); // cap at shift end
            $lateMinutes += max(0, (int) $shiftLunchEnd->diffInMinutes($lateLunchEnd));
        }

        $result['late_minutes'] = max(0, $lateMinutes);

        // === EARLY MINUTES ===
        // Early = remaining WORK minutes not worked because of early departure OR early lunch out
        // Lunch break is excluded (invisible)
        $earlyMinutes = 0;

        // A) Early lunch out: went to lunch before scheduled lunch start
        if ($hasLunch && $lunchOut && $lunchOut->lt($shiftLunchStart)) {
            $earlyMinutes += max(0, (int) $lunchOut->diffInMinutes($shiftLunchStart));
        }

        // B) Early time out: left before shift end
        if ($timeOut) {
            $earlyDeadline = $shiftEnd->copy()->subMinutes($shift->grace_out_minutes);
            if ($timeOut->lt($earlyDeadline)) {
                if ($hasLunch) {
                    if ($timeOut->lt($shiftLunchStart)) {
                        // Left before lunch — missed: (lunch start - time out) in morning + entire afternoon
                        $morningMissed = (int) $timeOut->diffInMinutes($shiftLunchStart);
                        $afternoonMissed = (int) $shiftLunchEnd->diffInMinutes($shiftEnd);
                        $earlyMinutes += $morningMissed + $afternoonMissed;
                    } elseif ($timeOut->lte($shiftLunchEnd)) {
                        // Left during lunch — missed entire afternoon
                        $afternoonMissed = (int) $shiftLunchEnd->diffInMinutes($shiftEnd);
                        $earlyMinutes += $afternoonMissed;
                    } else {
                        // Left after lunch — missed: (shift end - time out) in afternoon
                        $earlyMinutes += (int) $timeOut->diffInMinutes($shiftEnd);
                    }
                } else {
                    // No lunch — simple subtraction
                    $earlyMinutes += max(0, (int) $timeOut->diffInMinutes($shiftEnd));
                }
            }
        }

        $result['early_minutes'] = max(0, $earlyMinutes);

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
