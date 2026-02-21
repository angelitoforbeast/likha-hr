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
     * Compute a single attendance day from raw logs (no override protection).
     */
    public function computeDay(
        Employee $employee,
        string $workDate,
        Collection $punches,
        ?\App\Models\Shift $shift,
        ?int $sourceRunId = null
    ): AttendanceDay {
        $needsReview = false;
        $notes = [];

        $sorted = $punches->sortBy('punched_at')->values();

        $rawTimeIn = Carbon::parse($sorted->first()->punched_at);
        $rawTimeOut = $sorted->count() > 1 ? Carbon::parse($sorted->last()->punched_at) : null;

        $computeTimeIn = $this->ceilToMinute($rawTimeIn);
        $computeTimeOut = $rawTimeOut ? $this->floorToMinute($rawTimeOut) : null;

        $lunchOut = null;
        $lunchIn = null;

        if (!$shift) {
            $needsReview = true;
            $notes[] = 'No shift assigned to employee.';
        }

        if ($shift && $sorted->count() >= 3) {
            $lunchResult = $this->inferLunchPunches($sorted, $shift, $workDate);
            $lunchOut = $lunchResult['lunch_out'];
            $lunchIn = $lunchResult['lunch_in'];
        }

        if ($shift && (!$lunchOut || !$lunchIn)) {
            $needsReview = true;
            $notes[] = 'Lunch punches could not be inferred.';
        }

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

        $sorted = $punches->sortBy('punched_at')->values();

        // Fresh values from raw logs
        $freshTimeIn = Carbon::parse($sorted->first()->punched_at);
        $freshTimeOut = $sorted->count() > 1 ? Carbon::parse($sorted->last()->punched_at) : null;

        $freshLunchOut = null;
        $freshLunchIn = null;

        if (!$shift) {
            $needsReview = true;
            $notes[] = 'No shift assigned to employee.';
        }

        if ($shift && $sorted->count() >= 3) {
            $lunchResult = $this->inferLunchPunches($sorted, $shift, $workDate);
            $freshLunchOut = $lunchResult['lunch_out'];
            $freshLunchIn = $lunchResult['lunch_in'];
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
     * Infer lunch out/in from punches within the lunch window.
     */
    protected function inferLunchPunches(Collection $punches, \App\Models\Shift $shift, string $workDate): array
    {
        $lunchStart = Carbon::parse($workDate . ' ' . $shift->lunch_start);
        $lunchEnd = Carbon::parse($workDate . ' ' . $shift->lunch_end);

        $windowStart = $lunchStart->copy()->subMinutes($shift->lunch_inference_window_before_minutes);
        $windowEnd = $lunchEnd->copy()->addMinutes($shift->lunch_inference_window_after_minutes);

        $lunchOut = null;
        $lunchIn = null;

        $middlePunches = $punches->slice(1, -1)->values();

        foreach ($middlePunches as $punch) {
            $punchTime = Carbon::parse($punch->punched_at);

            if ($punchTime->between($windowStart, $windowEnd)) {
                if (!$lunchOut) {
                    $lunchOut = $punchTime;
                } elseif (!$lunchIn) {
                    $lunchIn = $punchTime;
                    break;
                }
            }
        }

        return [
            'lunch_out' => $lunchOut,
            'lunch_in'  => $lunchIn,
        ];
    }

    /**
     * Compute work minutes, late, early, overtime using overlap method.
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
        $shiftLunchStart = Carbon::parse($workDate . ' ' . $shift->lunch_start);
        $shiftLunchEnd = Carbon::parse($workDate . ' ' . $shift->lunch_end);

        $morningActualStart = $timeIn;
        $morningActualEnd = $lunchOut ?? $shiftLunchStart;
        $morningMinutes = $this->overlapMinutes($morningActualStart, $morningActualEnd, $shiftStart, $shiftLunchStart);

        $afternoonActualStart = $lunchIn ?? $shiftLunchEnd;
        $afternoonActualEnd = $timeOut ?? $shiftEnd;
        $afternoonMinutes = $this->overlapMinutes($afternoonActualStart, $afternoonActualEnd, $shiftLunchEnd, $shiftEnd);

        $result['work_minutes'] = max(0, $morningMinutes + $afternoonMinutes);

        $graceDeadline = $shiftStart->copy()->addMinutes($shift->grace_in_minutes);
        if ($timeIn->gt($graceDeadline)) {
            $result['late_minutes'] = (int) $shiftStart->diffInMinutes($timeIn);
        }

        if ($timeOut) {
            $earlyDeadline = $shiftEnd->copy()->subMinutes($shift->grace_out_minutes);
            if ($timeOut->lt($earlyDeadline)) {
                $result['early_minutes'] = (int) $timeOut->diffInMinutes($shiftEnd);
            }
        }

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
