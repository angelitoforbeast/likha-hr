<?php

namespace App\Services;

use App\Models\AttendanceDay;
use App\Models\AttendanceLog;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class AttendanceComputeService
{
    /**
     * Compute attendance days for a date range, optionally for a specific run.
     */
    public function computeForDateRange(
        string $startDate,
        string $endDate,
        ?int $sourceRunId = null
    ): array {
        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->endOfDay();

        $stats = ['processed' => 0, 'errors' => 0];

        // Get all employees with active status
        $employees = Employee::where('status', 'active')->get();

        foreach ($employees as $employee) {
            $this->computeForEmployee($employee, $start, $end, $sourceRunId, $stats);
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
        array &$stats
    ): void {
        // Shift is now resolved per-date using shift assignments

        // Get all punches for this employee in the date range
        $punches = AttendanceLog::where('employee_id', $employee->id)
            ->whereBetween('punched_at', [$start, $end])
            ->orderBy('punched_at')
            ->get();

        // Group punches by date
        $punchesByDate = $punches->groupBy(function ($log) {
            return Carbon::parse($log->punched_at)->format('Y-m-d');
        });

        // Iterate each date in range
        $current = $start->copy();
        while ($current->lte($end)) {
            $dateStr = $current->format('Y-m-d');
            $dayPunches = $punchesByDate->get($dateStr, collect());

            if ($dayPunches->isNotEmpty()) {
                try {
                    // Resolve shift for this specific date using assignment history
                    $shift = $employee->getShiftForDate($dateStr);
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
     * 10:00:00 stays 10:00, 10:00:01 becomes 10:01, 10:00:31 becomes 10:01.
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
     * 18:59:59 becomes 18:59, 19:00:00 stays 19:00.
     */
    protected function floorToMinute(Carbon $time): Carbon
    {
        return $time->copy()->second(0);
    }

    /**
     * Compute a single attendance day for an employee.
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

        // Sort punches by time
        $sorted = $punches->sortBy('punched_at')->values();

        // time_in = first punch (raw), time_out = last punch (raw)
        $rawTimeIn = Carbon::parse($sorted->first()->punched_at);
        $rawTimeOut = $sorted->count() > 1 ? Carbon::parse($sorted->last()->punched_at) : null;

        // For computation: round UP time_in (ceil), round DOWN time_out (floor)
        $computeTimeIn = $this->ceilToMinute($rawTimeIn);
        $computeTimeOut = $rawTimeOut ? $this->floorToMinute($rawTimeOut) : null;

        $lunchOut = null;
        $lunchIn = null;

        if (!$shift) {
            $needsReview = true;
            $notes[] = 'No shift assigned to employee.';
        }

        // Infer lunch punches if shift is available
        if ($shift && $sorted->count() >= 3) {
            $lunchResult = $this->inferLunchPunches($sorted, $shift, $workDate);
            $lunchOut = $lunchResult['lunch_out'];
            $lunchIn = $lunchResult['lunch_in'];
        }

        if ($shift && (!$lunchOut || !$lunchIn)) {
            $needsReview = true;
            $notes[] = 'Lunch punches could not be inferred.';
        }

        // Compute work minutes, late, early, overtime using rounded times
        $computed = $this->computeMetrics($computeTimeIn, $computeTimeOut, $lunchOut, $lunchIn, $shift, $workDate);

        // Upsert attendance_days — store raw times for display, computed values use rounded
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
     * Recompute a single attendance day (used after overrides).
     */
    public function recomputeDay(AttendanceDay $day): AttendanceDay
    {
        $shift = $day->shift;

        // Apply same rounding: ceil for time_in, floor for time_out
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

        // Find punches within the lunch window (excluding first and last overall punches)
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
     * Expects time_in already rounded UP (ceil) and time_out rounded DOWN (floor).
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

        // Morning overlap: [time_in, lunch_out or shift_lunch_start] ∩ [shift_start, shift_lunch_start]
        $morningActualStart = $timeIn;
        $morningActualEnd = $lunchOut ?? $shiftLunchStart;
        $morningMinutes = $this->overlapMinutes($morningActualStart, $morningActualEnd, $shiftStart, $shiftLunchStart);

        // Afternoon overlap: [lunch_in or shift_lunch_end, time_out] ∩ [shift_lunch_end, shift_end]
        $afternoonActualStart = $lunchIn ?? $shiftLunchEnd;
        $afternoonActualEnd = $timeOut ?? $shiftEnd;
        $afternoonMinutes = $this->overlapMinutes($afternoonActualStart, $afternoonActualEnd, $shiftLunchEnd, $shiftEnd);

        $result['work_minutes'] = max(0, $morningMinutes + $afternoonMinutes);

        // Late minutes: time_in is already ceiled, so compare directly to shift start
        // Grace period from shift (0 means no grace — any second late counts)
        $graceDeadline = $shiftStart->copy()->addMinutes($shift->grace_in_minutes);
        if ($timeIn->gt($graceDeadline)) {
            // Late minutes = difference from shift start (not from grace deadline)
            $result['late_minutes'] = (int) $shiftStart->diffInMinutes($timeIn);
        }

        // Early out minutes: time_out is already floored, so compare directly to shift end
        if ($timeOut) {
            $earlyDeadline = $shiftEnd->copy()->subMinutes($shift->grace_out_minutes);
            if ($timeOut->lt($earlyDeadline)) {
                // Early minutes = difference from shift end
                $result['early_minutes'] = (int) $timeOut->diffInMinutes($shiftEnd);
            }
        }

        // Overtime: only if time_out (floored) is after shift end
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
