<?php

namespace App\Http\Controllers;

use App\Models\AttendanceDay;
use App\Models\AttendanceOverride;
use App\Models\DayOff;
use App\Models\DayOffLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use App\Models\Shift;
use App\Services\AttendanceComputeService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AttendanceCalendarController extends Controller
{
    /**
     * Show the Attendance Calendar page.
     * Grid view — rows = employees, columns = days in selected date range.
     * Only shows active employees that have at least one attendance log in the range.
     */
    public function index(Request $request)
    {
        $departments = Department::orderBy('name')->get();

        // Default date range: 1st to last day of current month
        $dateFrom = $request->input('date_from', now()->startOfMonth()->format('Y-m-d'));
        $dateTo   = $request->input('date_to', now()->endOfMonth()->format('Y-m-d'));

        $startDate = Carbon::parse($dateFrom)->startOfDay();
        $endDate   = Carbon::parse($dateTo)->startOfDay();

        // Ensure from <= to
        if ($startDate->gt($endDate)) {
            $tmp = $startDate;
            $startDate = $endDate;
            $endDate = $tmp;
            $dateFrom = $startDate->format('Y-m-d');
            $dateTo = $endDate->format('Y-m-d');
        }

        $filterType = $request->input('filter_type', 'all');
        $departmentId = $request->input('department_id');
        $employeeId = $request->input('employee_id');
        $showShift = $request->boolean('show_shift'); // toggle: display shift name/times in each cell

        // Build the list of dates in range
        $period = CarbonPeriod::create($startDate, $endDate);
        $dates = [];
        foreach ($period as $date) {
            $dates[] = $date->copy();
        }
        $totalDays = count($dates);

        // Get employee IDs that have attendance records in this range
        $employeeIdsWithLogs = AttendanceDay::whereBetween('work_date', [$startDate, $endDate])
            ->distinct()
            ->pluck('employee_id')
            ->toArray();

        // Get filtered active employees WITH attendance logs only
        $query = Employee::where('status', 'active')
            ->whereIn('id', $employeeIdsWithLogs);

        if ($filterType === 'department' && $departmentId) {
            $query->where('department_id', $departmentId);
        } elseif ($filterType === 'employee' && $employeeId) {
            $query->where('id', $employeeId);
        }

        // Default sort: department name first, then employee name.
        // Done in PHP to avoid SQL join ambiguities on columns like `id` / `status`.
        $filteredEmployees = $query
            ->with('department')
            ->orderBy('full_name')
            ->get()
            ->sortBy(function ($emp) {
                return strtolower(($emp->department->name ?? 'zzz') . '|' . $emp->full_name);
            })
            ->values();

        // All active employees for the employee filter dropdown
        $employees = Employee::where('status', 'active')->orderBy('full_name')->get();

        // Preload all attendance days for the range for these employees
        $attendanceDays = AttendanceDay::with('shift')
            ->whereIn('employee_id', $filteredEmployees->pluck('id'))
            ->whereBetween('work_date', [$startDate, $endDate])
            ->get();

        // Index by employee_id + date
        $attendanceIndex = [];
        foreach ($attendanceDays as $day) {
            $key = $day->employee_id . '_' . $day->work_date->format('Y-m-d');
            $attendanceIndex[$key] = $day;
        }

        // Preload overrides for these attendance days (with updater for detail display)
        $attDayIds = $attendanceDays->pluck('id')->toArray();
        $overrides = AttendanceOverride::with('updater')
            ->whereIn('attendance_day_id', $attDayIds)
            ->orderBy('created_at', 'asc')
            ->get()
            ->groupBy('attendance_day_id');

        // Preload day-off audit logs for these employees in the visible range so the modal can
        // surface a combined edit history (time overrides + day-off actions).
        $dayOffLogs = DayOffLog::with('updater')
            ->whereIn('employee_id', $filteredEmployees->pluck('id'))
            ->whereBetween('off_date', [$startDate, $endDate])
            ->orderBy('created_at', 'asc')
            ->get()
            ->groupBy(fn ($l) => $l->employee_id . '_' . $l->off_date->format('Y-m-d'));

        // Build calendar data
        $calendarData = [];

        foreach ($filteredEmployees as $emp) {
            $empData = [
                'employee' => $emp,
                'days' => [],
            ];

            foreach ($dates as $idx => $date) {
                $dateStr = $date->format('Y-m-d');
                $key = $emp->id . '_' . $dateStr;

                $attDay = $attendanceIndex[$key] ?? null;

                // Check if this date is a day off for this employee
                $isDayOff = $emp->isDayOff($dateStr);

                // Resolve shift for this employee on this date (falls back to default_shift).
                // Used both for the modal (schedule/lunch display) and the cell overlay (when show_shift toggle is on).
                $shiftForDay = $emp->getShiftForDate($dateStr);
                $shiftInfo = $shiftForDay ? [
                    'id'          => $shiftForDay->id,
                    'name'        => $shiftForDay->name,
                    'start'       => Carbon::parse($shiftForDay->start_time)->format('g:i A'),
                    'end'         => Carbon::parse($shiftForDay->end_time)->format('g:i A'),
                    'start_short' => Carbon::parse($shiftForDay->start_time)->format('ga'),
                    'end_short'   => Carbon::parse($shiftForDay->end_time)->format('ga'),
                    'lunch_start' => Carbon::parse($shiftForDay->lunch_start)->format('g:i A'),
                    'lunch_end'   => Carbon::parse($shiftForDay->lunch_end)->format('g:i A'),
                ] : null;
                $shiftIdForDay = $shiftForDay?->id;

                // Build the day-off audit entries for this employee/date. Used across all cell types
                // (present/absent/day_off) so the Edit History in the modal is complete regardless of status.
                $dayOffLogList = $dayOffLogs[$emp->id . '_' . $dateStr] ?? collect();
                $dayOffLogEntries = [];
                foreach ($dayOffLogList as $log) {
                    $dayOffLogEntries[] = [
                        'kind'    => 'day_off',
                        'field'   => 'day_off:' . $log->action, // e.g., day_off:add_day_off
                        'old_value' => $log->old_type ?? '(none)',
                        'new_value' => $log->new_type ?? '(none)',
                        'reason'  => $log->reason,
                        'updater' => $log->updater->name ?? 'Unknown',
                        'date'    => $log->created_at->format('M d, Y g:i A'),
                    ];
                }

                if ($attDay) {
                    $status = 'present';
                    $lateMin = $attDay->computed_late_minutes ?? 0;
                    $earlyMin = $attDay->computed_early_minutes ?? 0;

                    // A day is considered "undertime" when:
                    //  - there is late-in or early-out, OR
                    //  - required punches are missing (no time-in / no time-out — can't finish a shift), OR
                    //  - actual payable work is less than what the shift requires.
                    $missingCorePunches = empty($attDay->time_in) || empty($attDay->time_out);
                    $requiredMinutes    = (int) ($shiftForDay->required_work_minutes ?? 0);
                    $payableMinutes     = (int) ($attDay->payable_work_minutes ?? 0);
                    $insufficientWork   = $requiredMinutes > 0 && $payableMinutes < $requiredMinutes;

                    // A day is only "worked on a rest day" (RD-P) if there is actual evidence of work:
                    // at least one punch recorded or non-zero payable minutes. An empty AttendanceDay
                    // that just happens to sit on a rest-day override should stay as plain day_off.
                    $actuallyWorked = !empty($attDay->time_in)
                        || !empty($attDay->time_out)
                        || !empty($attDay->lunch_out)
                        || !empty($attDay->lunch_in)
                        || $payableMinutes > 0;

                    if ($isDayOff) {
                        $status = $actuallyWorked ? 'rd_present' : 'day_off';
                    } elseif ($lateMin > 0 || $earlyMin > 0 || $missingCorePunches || $insufficientWork) {
                        // Merge late/undertime/missing-punches/insufficient-work into a single "undertime" status
                        $status = 'undertime';
                    }

                    // Check if any overrides exist for this day
                    $dayOverrides = $overrides[$attDay->id] ?? collect();

                    // Build per-field override details for modal display
                    $overrideDetails = [];
                    foreach ($dayOverrides as $ov) {
                        $overrideDetails[] = [
                            'kind'  => 'time',
                            'field' => $ov->field,
                            'old_value' => $ov->old_value,
                            'new_value' => $ov->new_value,
                            'reason' => $ov->reason,
                            'updater' => $ov->updater->name ?? 'Unknown',
                            'date' => $ov->created_at->format('M d, Y g:i A'),
                        ];
                    }
                    // Merge time overrides with day-off logs, sorted chronologically by date string.
                    $overrideDetails = array_merge($overrideDetails, $dayOffLogEntries);
                    usort($overrideDetails, fn ($a, $b) => strcmp($a['date'], $b['date']));

                    $hasOverrides = !empty($overrideDetails);

                    $empData['days'][$idx] = [
                        'date' => $dateStr,
                        'status' => $status,
                        'attendance' => $attDay,
                        'has_overrides' => $hasOverrides,
                        'override_details' => $overrideDetails,
                        'shift' => $shiftInfo,
                        'shift_id' => $shiftIdForDay,
                    ];
                } elseif ($isDayOff) {
                    $empData['days'][$idx] = [
                        'date' => $dateStr,
                        'status' => 'day_off',
                        'attendance' => null,
                        'has_overrides' => !empty($dayOffLogEntries),
                        'override_details' => $dayOffLogEntries,
                        'shift' => $shiftInfo,
                        'shift_id' => $shiftIdForDay,
                    ];
                } else {
                    $empData['days'][$idx] = [
                        'date' => $dateStr,
                        'status' => 'absent',
                        'attendance' => null,
                        'has_overrides' => !empty($dayOffLogEntries),
                        'override_details' => $dayOffLogEntries,
                        'shift' => $shiftInfo,
                        'shift_id' => $shiftIdForDay,
                    ];
                }
            }

            $calendarData[] = $empData;
        }

        // Shifts list for the per-cell shift-edit dropdown in the modal.
        $shifts = Shift::orderBy('name')->get();

        return view('attendance-calendar.index', compact(
            'departments', 'employees', 'calendarData',
            'dates', 'totalDays', 'dateFrom', 'dateTo',
            'filterType', 'departmentId', 'employeeId', 'showShift', 'shifts'
        ));
    }

    /**
     * Assign or replace a single-day shift override for one employee/date.
     * Creates an EmployeeShiftAssignment with effective_date == effective_until.
     * Existing longer-range assignments are left intact — the fixed getActiveShift
     * gives precedence to this same-day record for that specific date only.
     */
    public function assignShiftForDate(Request $request)
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'date'        => 'required|date',
            'shift_id'    => 'required|exists:shifts,id',
        ]);

        // Replace any existing single-day override for this employee/date first.
        EmployeeShiftAssignment::where('employee_id', $validated['employee_id'])
            ->where('effective_date', $validated['date'])
            ->where('effective_until', $validated['date'])
            ->delete();

        $assignment = EmployeeShiftAssignment::create([
            'employee_id'     => $validated['employee_id'],
            'shift_id'        => $validated['shift_id'],
            'effective_date'  => $validated['date'],
            'effective_until' => $validated['date'],
            'remarks'         => 'Set via attendance calendar',
        ]);

        $shift = Shift::find($validated['shift_id']);

        // Auto-recompute this single employee/date so the calendar reflects updated status
        // (respects overrides — never force).
        $this->autoComputeSingleDay((int) $validated['employee_id'], $validated['date']);

        return response()->json([
            'success'    => true,
            'message'    => 'Shift updated and attendance recomputed for this date.',
            'shift_name' => $shift?->name,
        ]);
    }

    /**
     * Create a blank AttendanceDay for an employee/date so the CEO can manually enter time-in/out
     * for absent or day-off cells that have no attendance record yet. CEO only.
     * Returns the attendance_day_id (existing or newly created).
     */
    public function createDay(Request $request)
    {
        if (Auth::user()?->role !== 'ceo') {
            return response()->json(['success' => false, 'message' => 'Only CEO can add attendance records manually.'], 403);
        }

        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'work_date'   => 'required|date',
        ]);

        $employee = Employee::findOrFail($validated['employee_id']);
        $workDate = Carbon::parse($validated['work_date']);

        $existing = AttendanceDay::where('employee_id', $employee->id)
            ->whereDate('work_date', $workDate)
            ->first();

        if ($existing) {
            return response()->json([
                'success'           => true,
                'attendance_day_id' => $existing->id,
                'message'           => 'Attendance record already exists.',
                'was_created'       => false,
            ]);
        }

        // Assign the employee's shift for this date so late/OT compute correctly later.
        $shift = $employee->getShiftForDate($workDate->format('Y-m-d'));

        $day = AttendanceDay::create([
            'employee_id'            => $employee->id,
            'work_date'              => $workDate,
            'shift_id'               => $shift?->id,
            'time_in'                => null,
            'lunch_out'              => null,
            'lunch_in'               => null,
            'time_out'               => null,
            'computed_work_minutes'  => 0,
            'computed_late_minutes'  => 0,
            'computed_early_minutes' => 0,
            'computed_overtime_minutes' => 0,
            'payable_work_minutes'   => 0,
            'needs_review'           => true,
            'notes'                  => 'Manually created via attendance calendar',
        ]);

        return response()->json([
            'success'           => true,
            'attendance_day_id' => $day->id,
            'message'           => 'Attendance record created. Now you can enter time-in/out.',
            'was_created'       => true,
        ]);
    }

    /**
     * Bulk compute attendance for the visible date range on the calendar.
     * Respects existing manual overrides (uses computeForDateRange with force=false).
     */
    public function computeRange(Request $request)
    {
        $validated = $request->validate([
            'start_date' => 'required|date',
            'end_date'   => 'required|date|after_or_equal:start_date',
        ]);

        $service = new AttendanceComputeService();
        $stats = $service->computeForDateRange(
            $validated['start_date'],
            $validated['end_date'],
            null,
            false // respect overrides
        );

        return response()->json([
            'success' => true,
            'message' => "Computed attendance: {$stats['processed']} days processed, {$stats['errors']} errors.",
            'stats'   => $stats,
        ]);
    }

    /**
     * Override a time field (Time In / Lunch Out / Lunch In / Time Out) for a single AttendanceDay
     * from the inline editor in the calendar's detail modal. Also records the AttendanceOverride
     * audit row, recomputes the day, and returns fresh values for the UI to reflect immediately.
     */
    public function overrideTime(Request $request)
    {
        $validated = $request->validate([
            'attendance_day_id' => 'required|exists:attendance_days,id',
            'field'             => 'required|in:time_in,lunch_out,lunch_in,time_out',
            'new_value'         => 'nullable|string', // H:i or H:i:s, empty to clear
            'reason'            => 'required|string|min:3|max:500',
        ]);

        $day   = AttendanceDay::findOrFail($validated['attendance_day_id']);
        $field = $validated['field'];
        $newValue = $validated['new_value'] ?? null;
        $reason   = $validated['reason'];

        $oldValue = $day->{$field} ? Carbon::parse($day->{$field})->format('H:i:s') : null;

        if ($newValue) {
            // Accept H:i or H:i:s
            $day->{$field} = Carbon::parse($day->work_date->format('Y-m-d') . ' ' . $newValue);
        } else {
            $day->{$field} = null;
        }
        $day->save();

        AttendanceOverride::create([
            'attendance_day_id' => $day->id,
            'employee_id'       => $day->employee_id,
            'work_date'         => $day->work_date,
            'field'             => $field,
            'old_value'         => $oldValue,
            'new_value'         => $newValue,
            'reason'            => $reason,
            'updated_by'        => Auth::id(),
        ]);

        // Recompute the day so late/early/OT/status reflect the new value.
        $day->load('shift');
        $service = new AttendanceComputeService();
        $service->recomputeDay($day);

        $day->refresh();

        return response()->json([
            'success' => true,
            'message' => 'Saved.',
            'field'   => $field,
            'value_display' => $day->{$field} ? Carbon::parse($day->{$field})->format('g:i A') : '-',
            'value_raw'     => $day->{$field} ? Carbon::parse($day->{$field})->format('H:i:s') : '',
            // Recomputed metrics so the modal can refresh Work/Late/UT/OT live without a page reload
            'metrics' => [
                'work_minutes'     => (int) $day->computed_work_minutes,
                'late_minutes'     => (int) $day->computed_late_minutes,
                'early_minutes'    => (int) $day->computed_early_minutes,
                'overtime_minutes' => (int) $day->computed_overtime_minutes,
            ],
        ]);
    }

    /**
     * "Fill from Shift Schedule" — one-click sets Time In, Lunch Out, Lunch In, Time Out
     * to the values from the employee's shift for that date. Handy for retroactively logging
     * a normal working day when the ZKTeco didn't capture punches. Auto-creates the AttendanceDay,
     * writes an AttendanceOverride audit row per field, recomputes, and returns fresh values.
     */
    public function fillFromShift(Request $request)
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'date'        => 'required|date',
            'reason'      => 'required|string|min:3|max:500',
        ]);

        $employee = Employee::findOrFail($validated['employee_id']);
        $dateStr  = $validated['date'];
        $reason   = $validated['reason'];

        $shift = $employee->getShiftForDate($dateStr);
        if (!$shift) {
            return response()->json([
                'success' => false,
                'message' => 'No shift assigned for this employee on this date. Assign a shift first.',
            ], 422);
        }

        // Ensure an AttendanceDay exists (create blank if needed).
        $day = AttendanceDay::where('employee_id', $employee->id)
            ->whereDate('work_date', $dateStr)
            ->first();

        $createdDay = false;
        if (!$day) {
            if (Auth::user()?->role !== 'ceo') {
                return response()->json([
                    'success' => false,
                    'message' => 'No attendance record for this date. Only CEO can create one.',
                ], 403);
            }
            $day = AttendanceDay::create([
                'employee_id'            => $employee->id,
                'work_date'              => Carbon::parse($dateStr),
                'shift_id'               => $shift->id,
                'computed_work_minutes'  => 0,
                'computed_late_minutes'  => 0,
                'computed_early_minutes' => 0,
                'computed_overtime_minutes' => 0,
                'payable_work_minutes'   => 0,
                'needs_review'           => true,
                'notes'                  => 'Auto-created via Fill from Shift Schedule',
            ]);
            $createdDay = true;
        }

        // Map each attendance field to its corresponding shift time.
        $mapping = [
            'time_in'   => Carbon::parse($shift->start_time)->format('H:i:s'),
            'lunch_out' => Carbon::parse($shift->lunch_start)->format('H:i:s'),
            'lunch_in'  => Carbon::parse($shift->lunch_end)->format('H:i:s'),
            'time_out'  => Carbon::parse($shift->end_time)->format('H:i:s'),
        ];

        foreach ($mapping as $field => $timeStr) {
            $oldValue = $day->{$field} ? Carbon::parse($day->{$field})->format('H:i:s') : null;

            $day->{$field} = Carbon::parse($day->work_date->format('Y-m-d') . ' ' . $timeStr);

            AttendanceOverride::create([
                'attendance_day_id' => $day->id,
                'employee_id'       => $day->employee_id,
                'work_date'         => $day->work_date,
                'field'             => $field,
                'old_value'         => $oldValue,
                'new_value'         => $timeStr,
                'reason'            => $reason,
                'updated_by'        => Auth::id(),
            ]);
        }
        $day->save();

        // Recompute once so metrics reflect all 4 new values in a single pass.
        $day->load('shift');
        $service = new AttendanceComputeService();
        $service->recomputeDay($day);
        $day->refresh();

        return response()->json([
            'success'      => true,
            'message'      => 'Filled from shift schedule.',
            'created_day'  => $createdDay,
            'values' => [
                'time_in'   => ['display' => Carbon::parse($day->time_in)->format('g:i A'),   'raw' => Carbon::parse($day->time_in)->format('H:i:s')],
                'lunch_out' => ['display' => Carbon::parse($day->lunch_out)->format('g:i A'), 'raw' => Carbon::parse($day->lunch_out)->format('H:i:s')],
                'lunch_in'  => ['display' => Carbon::parse($day->lunch_in)->format('g:i A'),  'raw' => Carbon::parse($day->lunch_in)->format('H:i:s')],
                'time_out'  => ['display' => Carbon::parse($day->time_out)->format('g:i A'),  'raw' => Carbon::parse($day->time_out)->format('H:i:s')],
            ],
            'metrics' => [
                'work_minutes'     => (int) $day->computed_work_minutes,
                'late_minutes'     => (int) $day->computed_late_minutes,
                'early_minutes'    => (int) $day->computed_early_minutes,
                'overtime_minutes' => (int) $day->computed_overtime_minutes,
            ],
        ]);
    }

    /**
     * Bulk apply an action to many (employee, date) pairs at once — typically fired when the user
     * clicks a date column header in the calendar. Returns a per-employee success/failure summary.
     */
    public function bulkAction(Request $request)
    {
        $validated = $request->validate([
            'date'          => 'required|date',
            'employee_ids'  => 'required|array|min:1',
            'employee_ids.*'=> 'integer|exists:employees,id',
            'action'        => 'required|in:fill_from_shift,set_times,add_day_off,cancel_day_off,remove_override',
            'reason'        => 'required|string|min:3|max:500',
            // set_times action extras:
            'time_in'       => 'nullable|string',
            'lunch_out'     => 'nullable|string',
            'lunch_in'      => 'nullable|string',
            'time_out'      => 'nullable|string',
        ]);

        $date   = $validated['date'];
        $action = $validated['action'];
        $reason = $validated['reason'];

        $successes = [];
        $failures  = [];

        foreach ($validated['employee_ids'] as $empId) {
            $employee = Employee::find($empId);
            if (!$employee) {
                $failures[] = ['id' => $empId, 'name' => 'Unknown', 'error' => 'Employee not found'];
                continue;
            }
            try {
                if ($action === 'fill_from_shift') {
                    $this->bulkApplyFillFromShift($employee, $date, $reason);
                } elseif ($action === 'set_times') {
                    $this->bulkApplySetTimes($employee, $date, $reason, [
                        'time_in'   => $validated['time_in']   ?? null,
                        'lunch_out' => $validated['lunch_out'] ?? null,
                        'lunch_in'  => $validated['lunch_in']  ?? null,
                        'time_out'  => $validated['time_out']  ?? null,
                    ]);
                } elseif (in_array($action, ['add_day_off', 'cancel_day_off', 'remove_override'], true)) {
                    $this->bulkApplyDayOffAction($employee, $date, $action, $reason);
                }
                $successes[] = ['id' => $employee->id, 'name' => $employee->display_name];
            } catch (\Throwable $e) {
                $failures[] = [
                    'id'    => $employee->id,
                    'name'  => $employee->display_name,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'success'   => true,
            'message'   => count($successes) . ' succeeded, ' . count($failures) . ' failed.',
            'successes' => $successes,
            'failures'  => $failures,
        ]);
    }

    /**
     * Bulk-mode helper: apply Fill from Shift to one employee/date. Mirrors fillFromShift's core
     * logic but skips the JSON response wrapper.
     */
    protected function bulkApplyFillFromShift(Employee $employee, string $date, string $reason): void
    {
        $shift = $employee->getShiftForDate($date);
        if (!$shift) throw new \RuntimeException('No shift assigned for this date.');

        $day = $this->ensureAttendanceDayForBulk($employee, $date, $shift);

        $mapping = [
            'time_in'   => Carbon::parse($shift->start_time)->format('H:i:s'),
            'lunch_out' => Carbon::parse($shift->lunch_start)->format('H:i:s'),
            'lunch_in'  => Carbon::parse($shift->lunch_end)->format('H:i:s'),
            'time_out'  => Carbon::parse($shift->end_time)->format('H:i:s'),
        ];

        foreach ($mapping as $field => $timeStr) {
            $oldValue = $day->{$field} ? Carbon::parse($day->{$field})->format('H:i:s') : null;
            $day->{$field} = Carbon::parse($day->work_date->format('Y-m-d') . ' ' . $timeStr);
            AttendanceOverride::create([
                'attendance_day_id' => $day->id,
                'employee_id'       => $day->employee_id,
                'work_date'         => $day->work_date,
                'field'             => $field,
                'old_value'         => $oldValue,
                'new_value'         => $timeStr,
                'reason'            => $reason,
                'updated_by'        => Auth::id(),
            ]);
        }
        $day->save();
        $day->load('shift');
        (new AttendanceComputeService())->recomputeDay($day);
    }

    /**
     * Bulk-mode helper: set the four time fields to specific values (only the provided ones are written).
     */
    protected function bulkApplySetTimes(Employee $employee, string $date, string $reason, array $times): void
    {
        $shift = $employee->getShiftForDate($date);
        $day = $this->ensureAttendanceDayForBulk($employee, $date, $shift);

        foreach (['time_in', 'lunch_out', 'lunch_in', 'time_out'] as $field) {
            if (!array_key_exists($field, $times) || $times[$field] === null || $times[$field] === '') continue;
            $newVal = $times[$field];
            $oldValue = $day->{$field} ? Carbon::parse($day->{$field})->format('H:i:s') : null;
            $day->{$field} = Carbon::parse($day->work_date->format('Y-m-d') . ' ' . $newVal);
            AttendanceOverride::create([
                'attendance_day_id' => $day->id,
                'employee_id'       => $day->employee_id,
                'work_date'         => $day->work_date,
                'field'             => $field,
                'old_value'         => $oldValue,
                'new_value'         => $newVal,
                'reason'            => $reason,
                'updated_by'        => Auth::id(),
            ]);
        }
        $day->save();
        $day->load('shift');
        (new AttendanceComputeService())->recomputeDay($day);
    }

    /**
     * Bulk-mode helper: apply a day-off action (add/cancel/remove) and write the audit log.
     */
    protected function bulkApplyDayOffAction(Employee $employee, string $date, string $action, string $reason): void
    {
        $existing = DayOff::where('employee_id', $employee->id)
            ->where('off_date', $date)
            ->first();
        $oldType = $existing?->type;
        $newType = null;

        if ($action === 'remove_override') {
            if ($existing) $existing->delete();
        } elseif ($action === 'add_day_off') {
            if ($existing) {
                $existing->update(['type' => DayOff::TYPE_DAY_OFF, 'remarks' => 'Bulk-set via attendance calendar']);
            } else {
                DayOff::create([
                    'employee_id' => $employee->id,
                    'off_date'    => $date,
                    'type'        => DayOff::TYPE_DAY_OFF,
                    'remarks'     => 'Bulk-set via attendance calendar',
                ]);
            }
            $newType = DayOff::TYPE_DAY_OFF;
        } elseif ($action === 'cancel_day_off') {
            if ($existing) {
                $existing->update(['type' => DayOff::TYPE_CANCEL_DAY_OFF, 'remarks' => 'Bulk-cancelled via attendance calendar']);
            } else {
                DayOff::create([
                    'employee_id' => $employee->id,
                    'off_date'    => $date,
                    'type'        => DayOff::TYPE_CANCEL_DAY_OFF,
                    'remarks'     => 'Bulk-cancelled via attendance calendar',
                ]);
            }
            $newType = DayOff::TYPE_CANCEL_DAY_OFF;
        }

        DayOffLog::create([
            'employee_id' => $employee->id,
            'off_date'    => $date,
            'action'      => $action,
            'old_type'    => $oldType,
            'new_type'    => $newType,
            'reason'      => $reason,
            'updated_by'  => Auth::id(),
        ]);

        $this->autoComputeSingleDay($employee->id, $date);
    }

    /**
     * Ensure an AttendanceDay exists for the bulk helpers, creating a blank one if needed.
     * CEO check is enforced by the outer bulkAction validation of allowed actions.
     */
    protected function ensureAttendanceDayForBulk(Employee $employee, string $date, ?Shift $shift = null): AttendanceDay
    {
        $day = AttendanceDay::where('employee_id', $employee->id)
            ->whereDate('work_date', $date)
            ->first();
        if ($day) return $day;

        if (Auth::user()?->role !== 'ceo') {
            throw new \RuntimeException('CEO access required to create attendance records.');
        }
        $shift = $shift ?: $employee->getShiftForDate($date);
        return AttendanceDay::create([
            'employee_id'            => $employee->id,
            'work_date'              => Carbon::parse($date),
            'shift_id'               => $shift?->id,
            'computed_work_minutes'  => 0,
            'computed_late_minutes'  => 0,
            'computed_early_minutes' => 0,
            'computed_overtime_minutes' => 0,
            'payable_work_minutes'   => 0,
            'needs_review'           => true,
            'notes'                  => 'Auto-created via bulk action',
        ]);
    }

    /**
     * Recompute a single employee/date without touching overrides.
     * Silent — used internally after per-cell edits.
     */
    protected function autoComputeSingleDay(int $employeeId, string $date): void
    {
        try {
            $employee = Employee::find($employeeId);
            if (!$employee) return;

            $service = new AttendanceComputeService();
            $stats = ['processed' => 0, 'errors' => 0, 'overrides_deleted' => 0];
            $start = Carbon::parse($date)->startOfDay();
            $end   = Carbon::parse($date)->endOfDay();
            $service->computeForEmployee($employee, $start, $end, null, $stats, false);
        } catch (\Throwable $e) {
            // Silent — the shift change itself already succeeded; log for debugging.
            \Log::warning('Auto-compute after calendar edit failed', [
                'employee_id' => $employeeId,
                'date'        => $date,
                'error'       => $e->getMessage(),
            ]);
        }
    }

    /**
     * Toggle a day off via AJAX (reuses same logic as DayOffCalendarController@toggle).
     */
    public function toggleDayOff(Request $request)
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'date'        => 'required|date',
            'action'      => 'required|in:add_day_off,cancel_day_off,remove_override',
            'reason'      => 'required|string|min:3|max:500',
        ]);

        $employee = Employee::findOrFail($validated['employee_id']);
        $date     = $validated['date'];
        $reason   = $validated['reason'];

        $existing = DayOff::where('employee_id', $employee->id)
            ->where('off_date', $date)
            ->first();

        $oldType = $existing?->type;
        $newType = null;

        if ($validated['action'] === 'remove_override') {
            if ($existing) $existing->delete();
            $message = 'Override removed.';
            $newType = null;
        } elseif ($validated['action'] === 'add_day_off') {
            if ($existing) {
                $existing->update(['type' => DayOff::TYPE_DAY_OFF, 'remarks' => 'Set via attendance calendar']);
            } else {
                DayOff::create([
                    'employee_id' => $employee->id,
                    'off_date' => $date,
                    'type' => DayOff::TYPE_DAY_OFF,
                    'remarks' => 'Set via attendance calendar',
                ]);
            }
            $message = 'Rest day added.';
            $newType = DayOff::TYPE_DAY_OFF;
        } elseif ($validated['action'] === 'cancel_day_off') {
            if ($existing) {
                $existing->update(['type' => DayOff::TYPE_CANCEL_DAY_OFF, 'remarks' => 'Cancelled via attendance calendar']);
            } else {
                DayOff::create([
                    'employee_id' => $employee->id,
                    'off_date' => $date,
                    'type' => DayOff::TYPE_CANCEL_DAY_OFF,
                    'remarks' => 'Cancelled via attendance calendar',
                ]);
            }
            $message = 'Rest day cancelled (must work).';
            $newType = DayOff::TYPE_CANCEL_DAY_OFF;
        }

        // Log the change to day_off_logs for a full audit trail (who / when / why / old→new).
        DayOffLog::create([
            'employee_id' => $employee->id,
            'off_date'    => $date,
            'action'      => $validated['action'],
            'old_type'    => $oldType,
            'new_type'    => $newType,
            'reason'      => $reason,
            'updated_by'  => Auth::id(),
        ]);

        // Auto-recompute this single employee/date so the calendar reflects updated status
        $this->autoComputeSingleDay($employee->id, $date);

        // Return updated status
        $isDayOff = $employee->isDayOff($date);

        return response()->json([
            'success' => true,
            'message' => $message,
            'is_day_off' => $isDayOff,
        ]);
    }
}
