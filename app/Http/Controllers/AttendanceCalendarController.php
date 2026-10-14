<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AnswersAttendanceChanges;
use App\Models\AttendanceChangeRequest;
use App\Models\AttendanceDateLock;
use App\Models\AttendanceDay;
use App\Models\AttendanceOverride;
use App\Models\DayOffLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\SilApplication;
use App\Services\AttendanceChangeRefused;
use App\Services\AttendanceChangeRequestService;
use App\Services\AttendanceChangeService;
use App\Services\AttendanceComputeService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AttendanceCalendarController extends Controller
{
    use AnswersAttendanceChanges;

    protected const REST_DAY_MESSAGES = [
        'remove_override' => 'Override removed.',
        'add_day_off'     => 'Rest day added.',
        'cancel_day_off'  => 'Rest day cancelled (must work).',
    ];

    public function __construct(
        protected AttendanceChangeService $changes,
        protected AttendanceChangeRequestService $requests
    ) {
    }

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

        // Employees with a request in this range that waits for the CEO, or that was rejected and
        // not asked again, are listed too: a day that is only requested must be visible.
        $openRequestScope = fn ($q) => $q->where('status', AttendanceChangeRequest::STATUS_PENDING)
            ->orWhere(fn ($r) => $r->where('status', AttendanceChangeRequest::STATUS_REJECTED)->whereNull('superseded_at'));
        $employeeIdsWithLogs = array_values(array_unique(array_merge(
            $employeeIdsWithLogs,
            AttendanceChangeRequest::whereBetween('work_date', [$dateFrom, $dateTo])
                ->where($openRequestScope)
                ->distinct()
                ->pluck('employee_id')
                ->toArray()
        )));

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

        // Preload SIL applications so cells can be tagged as SIL and the modal can show existing SIL.
        $silApplications = SilApplication::whereIn('employee_id', $filteredEmployees->pluck('id'))
            ->whereBetween('sil_date', [$startDate, $endDate])
            ->get()
            ->keyBy(fn ($s) => $s->employee_id . '_' . $s->sil_date->format('Y-m-d'));

        // Preload date locks for the visible range so the calendar can mark locked cells + block edits.
        $dateLocks = AttendanceDateLock::with('locker')
            ->whereBetween('lock_date', [$startDate, $endDate])
            ->get()
            ->keyBy(fn ($l) => $l->lock_date->format('Y-m-d'));

        // Requests of the visible range in one query: pending ones and rejected ones not yet
        // replaced. They are shown beside the official day and never change its status or totals.
        $shiftNames = Shift::pluck('name', 'id')->all();
        $openRequests = AttendanceChangeRequest::with(['requester', 'decider'])
            ->whereIn('employee_id', $filteredEmployees->pluck('id'))
            ->whereBetween('work_date', [$dateFrom, $dateTo])
            ->where($openRequestScope)
            ->orderBy('id')
            ->get()
            ->groupBy(fn ($r) => $r->employee_id . '_' . $r->dateString());

        // Approved requests behind the override and day-off log rows loaded above, so the edit
        // history can say who asked and who approved.
        $requestByOfficialRow = AttendanceChangeRequest::with(['requester', 'decider'])
            ->where('status', AttendanceChangeRequest::STATUS_APPROVED)
            ->where(function ($q) use ($overrides, $dayOffLogs) {
                $q->where(fn ($o) => $o->where('applied_type', 'attendance_override')
                        ->whereIn('applied_id', $overrides->flatten()->pluck('id')))
                    ->orWhere(fn ($o) => $o->where('applied_type', 'day_off_log')
                        ->whereIn('applied_id', $dayOffLogs->flatten()->pluck('id')));
            })
            ->get()
            ->keyBy(fn ($r) => $r->applied_type . ':' . $r->applied_id);
        $askedAndApproved = function (string $type, int $id) use ($requestByOfficialRow) {
            $r = $requestByOfficialRow->get($type . ':' . $id);

            return [
                'requested_by' => $r ? $r->requesterName() : null,
                'approved_by'  => $r ? ($r->decider->name ?? 'Unknown') : null,
            ];
        };
        $viewerId = Auth::id();

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

                // Check if this date is an SIL day for this employee — takes precedence over other statuses.
                $silRecord = $silApplications[$emp->id . '_' . $dateStr] ?? null;

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
                    ] + $askedAndApproved('day_off_log', $log->id);
                }

                if ($silRecord) {
                    // SIL day takes precedence — regardless of attendance / rest-day status.
                    $empData['days'][$idx] = [
                        'date' => $dateStr,
                        'status' => 'sil',
                        'attendance' => $attDay,
                        'has_overrides' => !empty($dayOffLogEntries),
                        'override_details' => $dayOffLogEntries,
                        'shift' => $shiftInfo,
                        'shift_id' => $shiftIdForDay,
                        'sil' => [
                            'reason' => $silRecord->reason,
                            'applied_by' => $silRecord->applier?->name ?? 'Unknown',
                            'applied_at' => $silRecord->created_at->format('M d, Y g:i A'),
                        ],
                    ];
                } elseif ($attDay) {
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
                        ] + $askedAndApproved('attendance_override', $ov->id);
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
                        'sil' => $silRecord ? [
                            'reason' => $silRecord->reason,
                            'applied_by' => $silRecord->applier?->name ?? 'Unknown',
                            'applied_at' => $silRecord->created_at->format('M d, Y g:i A'),
                        ] : null,
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
                        'sil' => $silRecord ? [
                            'reason' => $silRecord->reason,
                            'applied_by' => $silRecord->applier?->name ?? 'Unknown',
                            'applied_at' => $silRecord->created_at->format('M d, Y g:i A'),
                        ] : null,
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
                        'sil' => $silRecord ? [
                            'reason' => $silRecord->reason,
                            'applied_by' => $silRecord->applier?->name ?? 'Unknown',
                            'applied_at' => $silRecord->created_at->format('M d, Y g:i A'),
                        ] : null,
                    ];
                }

                // The requests of this day, beside the official data built above.
                $pendingList = [];
                $noticeList  = [];
                foreach ($openRequests->get($key, []) as $req) {
                    if ($req->isPending()) {
                        // The official value from what this page has already loaded.
                        $official = match (true) {
                            $req->isTimeField() => $attDay && $attDay->{$req->field}
                                ? Carbon::parse($attDay->{$req->field})->format('H:i:s') : null,
                            $req->field === AttendanceChangeRequest::FIELD_OVERTIME => $attDay?->approved_overtime_minutes === null
                                ? null : (string) $attDay->approved_overtime_minutes,
                            $req->field === AttendanceChangeRequest::FIELD_SHIFT => $shiftIdForDay ? (string) $shiftIdForDay : null,
                            $req->field === AttendanceChangeRequest::FIELD_SIL => $silRecord ? 'applied' : null,
                            default => $this->requests->officialValue($emp, $dateStr, $req->field),
                        };
                        $pendingList[] = $req->toDayItem($official, $shiftNames, $viewerId);
                    } else {
                        $noticeList[] = $req->toDayItem(null, $shiftNames, $viewerId);
                    }
                }
                $empData['days'][$idx]['pending'] = $pendingList;
                $empData['days'][$idx]['notices'] = $noticeList;
            }

            $calendarData[] = $empData;
        }

        // What the signed-in user asked for and the CEO rejected, until asked again: shown
        // above the calendar whatever range is open, so a rejection is not missed.
        $myRejected = AttendanceChangeRequest::with('employee')
            ->where('requested_by', $viewerId)
            ->where('status', AttendanceChangeRequest::STATUS_REJECTED)
            ->whereNull('superseded_at')
            ->orderByDesc('decided_at')
            ->limit(60)
            ->get()
            ->groupBy(fn ($r) => $r->employee_id . '_' . $r->dateString())
            ->map(fn ($rows) => [
                'employee' => $rows->first()->employee?->display_name ?? 'Unknown',
                'date'     => Carbon::parse($rows->first()->dateString())->format('M d, Y'),
                'fields'   => $rows->map->fieldLabel()->implode(', '),
                'reason'   => $rows->first()->decision_reason,
            ])
            ->values()
            ->take(20);

        // Shifts list for the per-cell shift-edit dropdown in the modal.
        $shifts = Shift::orderBy('name')->get();

        return view('attendance-calendar.index', compact(
            'departments', 'employees', 'calendarData',
            'dates', 'totalDays', 'dateFrom', 'dateTo',
            'filterType', 'departmentId', 'employeeId', 'showShift', 'shifts', 'dateLocks', 'myRejected'
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
            'reason'      => 'nullable|string|min:3|max:500',
        ]);
        $date = Carbon::parse($validated['date'])->toDateString();
        $this->assertDateNotLocked($date);

        $employee = Employee::findOrFail($validated['employee_id']);
        $user     = Auth::user();

        if (AttendanceChangeRequestService::mustRequest($user)) {
            // The CEO reads the reason before approving, so a request never goes without one.
            if (empty($validated['reason'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'A reason is required. Type it in the Reason box, then save the shift again.',
                    'needs_reason' => true,
                ], 422);
            }
            if ($this->requests->officialValue($employee, $date, AttendanceChangeRequest::FIELD_SHIFT) === (string) (int) $validated['shift_id']) {
                return response()->json(['success' => true, 'message' => 'No changes to save.']);
            }
            try {
                return $this->pendingResponse($this->requests->submit(
                    $employee, $date,
                    [AttendanceChangeRequest::FIELD_SHIFT => (string) (int) $validated['shift_id']],
                    $validated['reason'], $user, 'assign_shift'
                ));
            } catch (AttendanceChangeRefused $e) {
                return $this->refusedResponse($e);
            }
        }

        $this->changes->assignShiftForDate(
            $employee,
            $date,
            (int) $validated['shift_id'],
            'Set via attendance calendar',
            AttendanceChangeService::RECOMPUTE_PUNCHES
        );

        $shift = Shift::find($validated['shift_id']);

        return response()->json([
            'success'    => true,
            'message'    => 'Shift updated and attendance recomputed for this date.',
            'shift_name' => $shift?->name,
        ]);
    }

    /**
     * Create a blank AttendanceDay for an employee/date so the CEO can manually enter time-in/out
     * for absent or day-off cells that have no attendance record yet.
     * Returns the attendance_day_id (existing or newly created).
     *
     * For HR staff and admins nothing is created: a blank row would already count as a worked
     * day in payroll. They get no id back, and the page then sends the time with the employee
     * and the date, which becomes a request.
     */
    public function createDay(Request $request)
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'work_date'   => 'required|date',
        ]);
        $date = Carbon::parse($validated['work_date'])->toDateString();
        $this->assertDateNotLocked($date);

        $employee = Employee::findOrFail($validated['employee_id']);
        $existing = $this->changes->findDay($employee->id, $date);

        if (AttendanceChangeRequestService::mustRequest(Auth::user())) {
            return response()->json([
                'success'           => true,
                'pending'           => true,
                'attendance_day_id' => $existing?->id,
                'message'           => 'Enter the times and save. They will be sent for approval.',
                'was_created'       => false,
            ]);
        }

        if ($existing) {
            return response()->json([
                'success'           => true,
                'attendance_day_id' => $existing->id,
                'message'           => 'Attendance record already exists.',
                'was_created'       => false,
            ]);
        }

        $day = $this->changes->createBlankDay($employee, $date, 'Manually created via attendance calendar');

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
     * Save all pending time / shift edits for one employee/date in a single request.
     * The client sends every field's intended final value; the server diffs against the current row
     * and keeps only the fields that really change. The CEO's save is written at once (one
     * AttendanceOverride per changed time field, one EmployeeShiftAssignment when the shift changed,
     * one recompute). The same save by HR staff or an admin is stored as requests and writes
     * nothing official.
     */
    public function saveAll(Request $request)
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'date'        => 'required|date',
            'reason'      => 'required|string|min:3|max:500',
            // Each field: null = "not changed", value = "set to this"
            'time_in'     => ['nullable', 'string', $this->timeRule()],
            'lunch_out'   => ['nullable', 'string', $this->timeRule()],
            'lunch_in'    => ['nullable', 'string', $this->timeRule()],
            'time_out'    => ['nullable', 'string', $this->timeRule()],
            'shift_id'    => 'nullable|integer|exists:shifts,id',
            // Approved OT in hours. Empty string / null = keep current, "0" = explicit zero
            // (approves NO overtime), non-empty numeric = set to that many hours.
            'approved_ot_hours' => 'nullable|numeric|min:0|max:24',
            'clear_approved_ot' => 'nullable|boolean', // when true, reverts to auto-computed OT
            // Explicit list of fields the client wants to clear (empty payload alone is ambiguous)
            'clear_fields'=> 'nullable|array',
            'clear_fields.*' => 'in:time_in,lunch_out,lunch_in,time_out',
        ]);
        $dateStr = Carbon::parse($validated['date'])->toDateString();
        $this->assertDateNotLocked($dateStr);

        $employee = Employee::findOrFail($validated['employee_id']);
        $reason   = $validated['reason'];
        $clearSet = array_flip($validated['clear_fields'] ?? []);
        $user     = Auth::user();

        $day = $this->changes->findDay($employee->id, $dateStr);

        // What actually changes. Nothing is written while this is worked out.
        $newShiftId = null;
        if (($validated['shift_id'] ?? null) !== null) {
            $currentShift = $employee->getShiftForDate($dateStr);
            if (!$currentShift || $currentShift->id !== (int) $validated['shift_id']) {
                $newShiftId = (int) $validated['shift_id'];
            }
        }

        $timeChanges = []; // field => 'H:i:s', or null to clear
        foreach (AttendanceChangeService::TIME_FIELDS as $field) {
            $currentRaw = $day && $day->{$field} ? Carbon::parse($day->{$field})->format('H:i:s') : '';
            $incoming   = $validated[$field] ?? null;

            if (isset($clearSet[$field])) {
                if ($currentRaw === '') continue; // already empty
                $timeChanges[$field] = null;
            } elseif ($incoming !== null) {
                $normalized = AttendanceChangeService::normalizeTime($incoming);
                if ($normalized === $currentRaw) continue; // no change
                $timeChanges[$field] = $normalized;
            }
        }

        // Approved OT needs a day row to sit on: one that exists, or one these times will create.
        $otChange = null; // ['minutes' => ?int] when it changes
        if ($day || !empty($timeChanges)) {
            $clearOt = (bool) ($validated['clear_approved_ot'] ?? false);
            $incomingOtHours = $validated['approved_ot_hours'] ?? null;
            if ($clearOt || ($incomingOtHours !== null && $incomingOtHours !== '')) {
                $newApprovedMinutes = $clearOt ? null : AttendanceChangeService::overtimeMinutes($incomingOtHours);
                if ($newApprovedMinutes !== $day?->approved_overtime_minutes) {
                    $otChange = ['minutes' => $newApprovedMinutes];
                }
            }
        }

        $changedFields  = array_keys($timeChanges);
        $shiftIdChanged = $newShiftId !== null;
        $otChanged      = $otChange !== null;

        if (!empty($changedFields) || $shiftIdChanged || $otChanged) {
            if (AttendanceChangeRequestService::mustRequest($user)) {
                $fields = $timeChanges;
                if ($shiftIdChanged) $fields[AttendanceChangeRequest::FIELD_SHIFT] = (string) $newShiftId;
                if ($otChanged) {
                    $fields[AttendanceChangeRequest::FIELD_OVERTIME] = $otChange['minutes'] === null ? null : (string) $otChange['minutes'];
                }
                try {
                    return $this->pendingResponse($this->requests->submit($employee, $dateStr, $fields, $reason, $user, 'save_all'));
                } catch (AttendanceChangeRefused $e) {
                    return $this->refusedResponse($e);
                }
            }

            DB::transaction(function () use ($employee, $dateStr, $newShiftId, $timeChanges, $otChange, $reason, $user) {
                if ($newShiftId !== null) {
                    $this->changes->assignShiftForDate(
                        $employee, $dateStr, $newShiftId,
                        'Set via attendance calendar (Save All)',
                        AttendanceChangeService::RECOMPUTE_DAY
                    );
                }
                if (!empty($timeChanges)) {
                    $this->changes->applyTimes(
                        $employee, $dateStr,
                        array_map(fn ($value) => ['value' => $value, 'reason' => $reason], $timeChanges),
                        $user,
                        'Auto-created via Save All'
                    );
                }
                if ($otChange !== null) {
                    $this->changes->setApprovedOvertime($employee, $dateStr, $otChange['minutes'], $reason, $user);
                }
            });
            $day = $this->changes->findDay($employee->id, $dateStr);
        }

        $effectiveOtMinutes = $day
            ? (int) ($day->approved_overtime_minutes ?? $day->computed_overtime_minutes)
            : 0;

        return response()->json([
            'success' => true,
            'message' => (empty($changedFields) && !$shiftIdChanged && !$otChanged)
                ? 'No changes to save.'
                : (count($changedFields) . ' field(s) saved'
                    . ($shiftIdChanged ? ' + shift updated' : '')
                    . ($otChanged ? ' + OT updated' : '') . '.'),
            'changed_fields' => $changedFields,
            'shift_changed'  => $shiftIdChanged,
            'ot_changed'     => $otChanged,
            'values' => $day ? [
                'time_in'   => ['display' => $day->time_in   ? Carbon::parse($day->time_in)->format('g:i A')   : '-', 'raw' => $day->time_in   ? Carbon::parse($day->time_in)->format('H:i:s')   : ''],
                'lunch_out' => ['display' => $day->lunch_out ? Carbon::parse($day->lunch_out)->format('g:i A') : '-', 'raw' => $day->lunch_out ? Carbon::parse($day->lunch_out)->format('H:i:s') : ''],
                'lunch_in'  => ['display' => $day->lunch_in  ? Carbon::parse($day->lunch_in)->format('g:i A')  : '-', 'raw' => $day->lunch_in  ? Carbon::parse($day->lunch_in)->format('H:i:s')  : ''],
                'time_out'  => ['display' => $day->time_out  ? Carbon::parse($day->time_out)->format('g:i A')  : '-', 'raw' => $day->time_out  ? Carbon::parse($day->time_out)->format('H:i:s')  : ''],
            ] : null,
            'metrics' => $day ? [
                'work_minutes'     => (int) $day->computed_work_minutes,
                'late_minutes'     => (int) $day->computed_late_minutes,
                'early_minutes'    => (int) $day->computed_early_minutes,
                'overtime_minutes' => $effectiveOtMinutes,
                'approved_ot_hours'=> $day->approved_overtime_minutes !== null
                    ? round($day->approved_overtime_minutes / 60, 2)
                    : null,
            ] : null,
        ]);
    }

    /**
     * Override a time field (Time In / Lunch Out / Lunch In / Time Out) for a single day
     * from the inline editor in the calendar's detail modal. The day is named by its
     * attendance_day_id or, when it has no row yet, by employee and date, so that asking for a
     * time never needs a blank row to exist first.
     */
    public function overrideTime(Request $request)
    {
        $validated = $request->validate([
            'attendance_day_id' => 'nullable|exists:attendance_days,id',
            'employee_id'       => 'required_without:attendance_day_id|nullable|exists:employees,id',
            'date'              => 'required_without:attendance_day_id|nullable|date',
            'field'             => 'required|in:time_in,lunch_out,lunch_in,time_out',
            'new_value'         => ['nullable', 'string', $this->timeRule()], // H:i or H:i:s, empty to clear
            'reason'            => 'required|string|min:3|max:500',
        ]);

        if (!empty($validated['attendance_day_id'])) {
            $day      = AttendanceDay::findOrFail($validated['attendance_day_id']);
            $employee = Employee::findOrFail($day->employee_id);
            $dateStr  = $day->work_date->format('Y-m-d');
        } else {
            $employee = Employee::findOrFail($validated['employee_id']);
            $dateStr  = Carbon::parse($validated['date'])->toDateString();
            $day      = $this->changes->findDay($employee->id, $dateStr);
        }
        $this->assertDateNotLocked($dateStr);

        $field    = $validated['field'];
        $newValue = !empty($validated['new_value']) ? AttendanceChangeService::normalizeTime($validated['new_value']) : null;
        $reason   = $validated['reason'];
        $user     = Auth::user();

        if (AttendanceChangeRequestService::mustRequest($user)) {
            if ($this->requests->officialValue($employee, $dateStr, $field) === $newValue) {
                return response()->json(['success' => true, 'message' => 'No changes to save.']);
            }
            try {
                return $this->pendingResponse(
                    $this->requests->submit($employee, $dateStr, [$field => $newValue], $reason, $user, 'override_time'),
                    ['field' => $field]
                );
            } catch (AttendanceChangeRefused $e) {
                return $this->refusedResponse($e);
            }
        }

        if (!$day && $newValue === null) {
            // Clearing a time on a day that has no record would only leave a blank row behind.
            return response()->json(['success' => true, 'message' => 'No changes to save.']);
        }

        $day = $this->changes->applyTimes(
            $employee, $dateStr,
            [$field => ['value' => $newValue, 'reason' => $reason]],
            $user,
            'Manually created via attendance calendar'
        )['day'];

        return response()->json([
            'success' => true,
            'message' => 'Saved.',
            'field'   => $field,
            'attendance_day_id' => $day->id,
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
     * a normal working day when the ZKTeco didn't capture punches. For the CEO it writes the
     * four times at once; for HR staff and admins the four times of the shift as it is now
     * are stored as requests.
     */
    public function fillFromShift(Request $request)
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'date'        => 'required|date',
            'reason'      => 'required|string|min:3|max:500',
        ]);
        $dateStr = Carbon::parse($validated['date'])->toDateString();
        $this->assertDateNotLocked($dateStr);

        $employee = Employee::findOrFail($validated['employee_id']);
        $reason   = $validated['reason'];
        $user     = Auth::user();

        $shift = $employee->getShiftForDate($dateStr);
        if (!$shift) {
            return response()->json([
                'success' => false,
                'message' => 'No shift assigned for this employee on this date. Assign a shift first.',
            ], 422);
        }

        $times = $this->shiftTimes($shift);

        if (AttendanceChangeRequestService::mustRequest($user)) {
            try {
                return $this->pendingResponse($this->requests->submit($employee, $dateStr, $times, $reason, $user, 'fill_from_shift'));
            } catch (AttendanceChangeRefused $e) {
                return $this->refusedResponse($e);
            }
        }

        $result = $this->changes->applyTimes(
            $employee, $dateStr,
            array_map(fn ($value) => ['value' => $value, 'reason' => $reason], $times),
            $user,
            'Auto-created via Fill from Shift Schedule'
        );
        $day = $result['day'];

        return response()->json([
            'success'      => true,
            'message'      => 'Filled from shift schedule.',
            'created_day'  => $result['created'],
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

    /** The four times of a shift, field => 'H:i:s'. */
    protected function shiftTimes(Shift $shift): array
    {
        return [
            'time_in'   => Carbon::parse($shift->start_time)->format('H:i:s'),
            'lunch_out' => Carbon::parse($shift->lunch_start)->format('H:i:s'),
            'lunch_in'  => Carbon::parse($shift->lunch_end)->format('H:i:s'),
            'time_out'  => Carbon::parse($shift->end_time)->format('H:i:s'),
        ];
    }

    /**
     * Bulk apply an action to many (employee, date) pairs at once — typically fired when the user
     * clicks a date column header in the calendar. Returns a per-employee success/failure summary.
     *
     * For the CEO each employee is written at once. For HR staff and admins each employee becomes
     * requests, all of one action sharing a batch id so the CEO can decide them together; the one
     * exception is a rest-day change for an employee without a day row, which stays direct.
     * One employee that fails never stops the others.
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
            'time_in'       => ['nullable', 'string', $this->timeRule()],
            'lunch_out'     => ['nullable', 'string', $this->timeRule()],
            'lunch_in'      => ['nullable', 'string', $this->timeRule()],
            'time_out'      => ['nullable', 'string', $this->timeRule()],
        ]);
        $date = Carbon::parse($validated['date'])->toDateString();
        $this->assertDateNotLocked($date);

        $action = $validated['action'];
        $reason = $validated['reason'];
        $user   = Auth::user();
        $asks   = AttendanceChangeRequestService::mustRequest($user);

        $times = [];
        if ($action === 'set_times') {
            foreach (AttendanceChangeService::TIME_FIELDS as $field) {
                if (!empty($validated[$field])) {
                    $times[$field] = AttendanceChangeService::normalizeTime($validated[$field]);
                }
            }
            if (empty($times)) {
                return response()->json(['success' => false, 'message' => 'Enter at least one time to set.'], 422);
            }
        }

        $batchId = $asks ? (string) Str::uuid() : null;

        $successes = []; // written at once
        $requested = []; // sent for approval
        $failures  = [];

        foreach ($validated['employee_ids'] as $empId) {
            $employee = Employee::find($empId);
            if (!$employee) {
                $failures[] = ['id' => $empId, 'name' => 'Unknown', 'error' => 'Employee not found'];
                continue;
            }
            try {
                $wasRequested = $this->bulkApplyOne($employee, $date, $action, $reason, $times, $user, $batchId);
                $entry = ['id' => $employee->id, 'name' => $employee->display_name];
                if ($wasRequested) {
                    $requested[] = $entry;
                } else {
                    $successes[] = $entry;
                }
            } catch (\Throwable $e) {
                $failures[] = [
                    'id'    => $employee->id,
                    'name'  => $employee->display_name,
                    'error' => $e->getMessage(),
                ];
            }
        }

        if ($asks) {
            $sent = count($requested);
            $message = count($successes) > 0
                ? count($successes) . ' done, ' . $sent . ' sent for approval'
                : $sent . ' ' . ($sent === 1 ? 'request' : 'requests') . ' sent for approval';
            $message .= (count($failures) > 0 ? ', ' . count($failures) . ' failed' : '') . '.';
        } else {
            $message = count($successes) . ' succeeded, ' . count($failures) . ' failed.';
        }

        return response()->json([
            'success'   => true,
            'message'   => $message,
            'successes' => $successes,
            'requested' => $requested,
            'failures'  => $failures,
        ]);
    }

    /**
     * One employee of a bulk action. Returns true when the change was stored as a request,
     * false when it was written at once. $batchId is null for the CEO, whose changes are direct.
     */
    protected function bulkApplyOne(Employee $employee, string $date, string $action, string $reason, array $times, $user, ?string $batchId): bool
    {
        $asks = $batchId !== null;

        if (in_array($action, ['add_day_off', 'cancel_day_off', 'remove_override'], true)) {
            if (!$asks) {
                $this->changes->changeRestDay($employee, $date, $action, $reason, $user, true);

                return false;
            }
            // The rest-day rule, per employee: direct only while there is no day row.
            if (!$this->changes->findDay($employee->id, $date)) {
                try {
                    $this->changes->changeRestDay($employee, $date, $action, $reason, $user, true, true);

                    return false;
                } catch (AttendanceChangeRefused $e) {
                    // A day row appeared in the meantime: the change becomes a request.
                }
            }
            $this->requests->submit(
                $employee, $date, [AttendanceChangeRequest::FIELD_REST_DAY => $action], $reason, $user, 'bulk_' . $action, $batchId
            );

            return true;
        }

        if ($action === 'fill_from_shift') {
            $shift = $employee->getShiftForDate($date);
            if (!$shift) throw new \RuntimeException('No shift assigned for this date.');
            $times = $this->shiftTimes($shift);
        }

        if ($asks) {
            $this->requests->submit($employee, $date, $times, $reason, $user, 'bulk_' . $action, $batchId);

            return true;
        }

        $this->changes->applyTimes(
            $employee, $date,
            array_map(fn ($value) => ['value' => $value, 'reason' => $reason], $times),
            $user,
            'Auto-created via bulk action'
        );

        return false;
    }

    /**
     * Apply SIL (Service Incentive Leave) for one employee/date. Requires the employee to be
     * marked SIL-eligible and to have remaining SIL days for that year. Returns the fresh
     * remaining count so the modal can update the balance shown to the user immediately.
     */
    public function applySil(Request $request)
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'date'        => 'required|date',
            'reason'      => 'required|string|min:3|max:500',
        ]);
        $date = Carbon::parse($validated['date'])->toDateString();
        $this->assertDateNotLocked($date);

        $employee = Employee::findOrFail($validated['employee_id']);
        $year     = Carbon::parse($date)->year;
        $user     = Auth::user();

        try {
            if (AttendanceChangeRequestService::mustRequest($user)) {
                // The same three checks as the direct path, so that what cannot be applied is not asked for.
                $this->changes->assertSilCanBeApplied($employee, $date);

                return $this->pendingResponse($this->requests->submit(
                    $employee, $date, [AttendanceChangeRequest::FIELD_SIL => 'apply'], $validated['reason'], $user, 'apply_sil'
                ));
            }
            $this->changes->applySil($employee, $date, $validated['reason'], $user);
        } catch (AttendanceChangeRefused $e) {
            return $this->refusedResponse($e);
        }

        return response()->json([
            'success'   => true,
            'message'   => 'SIL applied.',
            'remaining' => $employee->getSilRemainingDays($year),
            'total'     => (float) $employee->getSilBalance($year)->total_days,
        ]);
    }

    /**
     * Remove an SIL application for one employee/date. Returns updated remaining count.
     */
    public function removeSil(Request $request)
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'date'        => 'required|date',
            'reason'      => 'nullable|string|min:3|max:500',
        ]);
        $date = Carbon::parse($validated['date'])->toDateString();
        $this->assertDateNotLocked($date);

        $employee = Employee::findOrFail($validated['employee_id']);
        $year     = Carbon::parse($date)->year;
        $user     = Auth::user();

        // HR staff and admins never reach the direct removal below. Whether SIL is applied
        // is read under the employee lock, so SIL applied at the same moment is seen.
        if (AttendanceChangeRequestService::mustRequest($user)) {
            try {
                return $this->changes->withLockRetry(function () use ($employee, $date, $year, $validated, $user) {
                    $this->changes->lockEmployee($employee);

                    $balance = fn () => [
                        'remaining' => $employee->getSilRemainingDays($year),
                        'total'     => (float) $employee->getSilBalance($year)->total_days,
                    ];
                    if (!$employee->isDateSil($date)) {
                        return response()->json(['success' => true, 'message' => 'No SIL was applied on this date.'] + $balance());
                    }
                    // The CEO reads the reason before approving, so a request never goes without one.
                    if (empty($validated['reason'])) {
                        return response()->json([
                            'success' => false,
                            'message' => 'A reason is required to ask for removing SIL.',
                            'needs_reason' => true,
                        ], 422);
                    }

                    return $this->pendingResponse($this->requests->submit(
                        $employee, $date, [AttendanceChangeRequest::FIELD_SIL => 'remove'], $validated['reason'], $user, 'remove_sil'
                    ), $balance());
                });
            } catch (AttendanceChangeRefused $e) {
                return $this->refusedResponse($e);
            }
        }

        $deleted = $this->changes->removeSil($employee, $date);

        return response()->json([
            'success'   => true,
            'message'   => $deleted ? 'SIL removed.' : 'No SIL was applied on this date.',
            'remaining' => $employee->getSilRemainingDays($year),
            'total'     => (float) $employee->getSilBalance($year)->total_days,
        ]);
    }

    /**
     * Adjust the SIL total_days for an employee's year balance. CEO only.
     * Used when starting mid-year with days already consumed elsewhere, or year-end conversions.
     */
    public function adjustSilBalance(Request $request)
    {
        if (Auth::user()?->role !== 'ceo') {
            return response()->json(['success' => false, 'message' => 'Only CEO can adjust SIL balances.'], 403);
        }
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'year'        => 'required|integer|min:2000|max:2100',
            'total_days'  => 'required|numeric|min:0|max:365',
            'notes'       => 'nullable|string|max:500',
        ]);
        $employee = Employee::findOrFail($validated['employee_id']);
        $balance = $employee->getSilBalance($validated['year']);
        $balance->update([
            'total_days' => $validated['total_days'],
            'notes'      => $validated['notes'] ?? $balance->notes,
        ]);
        return response()->json([
            'success'   => true,
            'message'   => 'SIL balance updated.',
            'remaining' => $employee->getSilRemainingDays($validated['year']),
            'total'     => (float) $balance->total_days,
        ]);
    }

    /**
     * Toggle SIL eligibility for an employee (CEO only). Convenience for the calendar modal.
     */
    public function toggleSilEligibility(Request $request)
    {
        if (Auth::user()?->role !== 'ceo') {
            return response()->json(['success' => false, 'message' => 'Only CEO can toggle SIL eligibility.'], 403);
        }
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'eligible'    => 'required|boolean',
        ]);
        $employee = Employee::findOrFail($validated['employee_id']);
        $employee->update(['sil_eligible' => $validated['eligible']]);
        return response()->json([
            'success' => true,
            'message' => $validated['eligible'] ? 'SIL eligibility enabled.' : 'SIL eligibility disabled.',
            'eligible' => (bool) $employee->sil_eligible,
        ]);
    }

    /**
     * Lock a single date so no attendance edits (times, shift, day-off, SIL) can be made.
     * CEO only. Requires a reason for audit.
     */
    public function lockDate(Request $request)
    {
        if (Auth::user()?->role !== 'ceo') {
            return response()->json(['success' => false, 'message' => 'Only CEO can lock dates.'], 403);
        }
        $validated = $request->validate([
            'date'   => 'required|date',
            'reason' => 'required|string|min:3|max:500',
        ]);
        AttendanceDateLock::updateOrCreate(
            ['lock_date' => $validated['date']],
            [
                'locked_by' => Auth::id(),
                'reason'    => $validated['reason'],
                'source'    => 'manual',
                'locked_at' => now(),
            ]
        );
        return response()->json(['success' => true, 'message' => 'Date locked.']);
    }

    /**
     * Lock a range of dates in bulk (e.g., a whole cutoff period). CEO only.
     */
    public function lockRange(Request $request)
    {
        if (Auth::user()?->role !== 'ceo') {
            return response()->json(['success' => false, 'message' => 'Only CEO can lock dates.'], 403);
        }
        $validated = $request->validate([
            'start_date' => 'required|date',
            'end_date'   => 'required|date|after_or_equal:start_date',
            'reason'     => 'required|string|min:3|max:500',
        ]);
        $count = 0;
        $period = \Carbon\CarbonPeriod::create($validated['start_date'], $validated['end_date']);
        foreach ($period as $day) {
            AttendanceDateLock::updateOrCreate(
                ['lock_date' => $day->format('Y-m-d')],
                [
                    'locked_by' => Auth::id(),
                    'reason'    => $validated['reason'],
                    'source'    => 'range',
                    'locked_at' => now(),
                ]
            );
            $count++;
        }
        return response()->json(['success' => true, 'message' => "Locked {$count} date(s)."]);
    }

    /**
     * Unlock a single date. CEO only.
     */
    public function unlockDate(Request $request)
    {
        if (Auth::user()?->role !== 'ceo') {
            return response()->json(['success' => false, 'message' => 'Only CEO can unlock dates.'], 403);
        }
        $validated = $request->validate([
            'date' => 'required|date',
        ]);
        $deleted = AttendanceDateLock::whereDate('lock_date', $validated['date'])->delete();
        return response()->json([
            'success' => true,
            'message' => $deleted ? 'Date unlocked.' : 'Date was not locked.',
        ]);
    }

    /**
     * Assert the given date is NOT locked; abort 423 (Locked) if it is.
     * Called at the top of every edit endpoint (times, shift, day-off, SIL, bulk actions).
     */
    protected function assertDateNotLocked(string $date): void
    {
        $this->changes->assertDateNotLocked($date);
    }

    /**
     * Toggle a day off via AJAX.
     */
    public function toggleDayOff(Request $request)
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'date'        => 'required|date',
            'action'      => 'required|in:add_day_off,cancel_day_off,remove_override',
            'reason'      => 'required|string|min:3|max:500',
        ]);
        $date = Carbon::parse($validated['date'])->toDateString();
        $this->assertDateNotLocked($date);

        $employee = Employee::findOrFail($validated['employee_id']);
        $user     = Auth::user();

        // The one exception to "HR staff and admins ask": a day without a day row has no
        // attendance to take out of pay, so its rest day is changed at once. With a day row
        // the change waits for the CEO.
        if (AttendanceChangeRequestService::mustRequest($user)) {
            $direct = false;
            if (!$this->changes->findDay($employee->id, $date)) {
                try {
                    $this->changes->changeRestDay($employee, $date, $validated['action'], $validated['reason'], $user, false, true);
                    $direct = true;
                } catch (AttendanceChangeRefused $e) {
                    // A day row appeared in the meantime: the change becomes a request.
                }
            }
            if (!$direct) {
                try {
                    return $this->pendingResponse($this->requests->submit(
                        $employee, $date, [AttendanceChangeRequest::FIELD_REST_DAY => $validated['action']],
                        $validated['reason'], $user, 'toggle_day_off'
                    ), ['is_day_off' => $employee->isDayOff($date)]);
                } catch (AttendanceChangeRefused $e) {
                    return $this->refusedResponse($e);
                }
            }
        } else {
            $this->changes->changeRestDay($employee, $date, $validated['action'], $validated['reason'], $user);
        }

        return response()->json([
            'success' => true,
            'message' => self::REST_DAY_MESSAGES[$validated['action']],
            'is_day_off' => $employee->isDayOff($date),
        ]);
    }
}
