<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AnswersAttendanceChanges;
use App\Models\AttendanceChangeRequest;
use App\Models\AttendanceDay;
use App\Models\AttendanceLog;
use App\Models\AttendanceOverride;
use App\Models\CutoffRule;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Shift;
use App\Services\AttendanceChangeRefused;
use App\Services\AttendanceChangeRequestService;
use App\Services\AttendanceChangeService;
use App\Services\AttendanceComputeService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttendanceController extends Controller
{
    use AnswersAttendanceChanges;

    public function __construct(
        protected AttendanceChangeService $changes,
        protected AttendanceChangeRequestService $requests
    ) {
    }

    /**
     * Attendance viewer with filters.
     */
    public function index(Request $request)
    {
        $employees = Employee::where('status', 'active')->orderBy('full_name')->get();
        $shifts = Shift::all();
        $departments = Department::orderBy('name')->get();

        // Resolve date range — use request dates or default to last completed cutoff
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        if (!$startDate || !$endDate) {
            $defaults = $this->getLastCompletedCutoff();
            $startDate = $startDate ?: $defaults['start'];
            $endDate = $endDate ?: $defaults['end'];
        }

        $query = AttendanceDay::with(['employee.department', 'shift'])
            ->whereBetween('work_date', [$startDate, $endDate])
            ->orderBy('work_date')
            ->orderBy('employee_id');

        if ($request->filled('search_name')) {
            $searchName = $request->search_name;
            $query->whereHas('employee', function ($q) use ($searchName) {
                $q->where('full_name', 'like', '%' . $searchName . '%')
                  ->orWhere('actual_name', 'like', '%' . $searchName . '%');
            });
        }

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }

        if ($request->filled('shift_id')) {
            $query->where('shift_id', $request->shift_id);
        }

        if ($request->filled('department_id')) {
            $deptId = $request->department_id;
            $query->whereHas('employee', function ($q) use ($deptId) {
                $q->where('department_id', $deptId);
            });
        }

        if ($request->filled('needs_review')) {
            $query->where('needs_review', $request->needs_review === '1');
        }

        $attendanceDays = $query->get();

        // Load overrides for each attendance day to show edit indicators
        $dayIds = $attendanceDays->pluck('id')->toArray();
        $overrides = AttendanceOverride::whereIn('attendance_day_id', $dayIds)
            ->with('updater')
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('attendance_day_id');

        // Build combined rows: attendance records + Day Off + Absent virtual rows
        $combinedRows = collect();

        // Determine which employees to show day off/absent rows for
        $filteredEmployeeQuery = Employee::where('status', 'active');
        if ($request->filled('employee_id')) {
            $filteredEmployeeQuery->where('id', $request->employee_id);
        }
        if ($request->filled('department_id')) {
            $filteredEmployeeQuery->where('department_id', $request->department_id);
        }
        if ($request->filled('search_name')) {
            $searchName = $request->search_name;
            $filteredEmployeeQuery->where(function ($q) use ($searchName) {
                $q->where('full_name', 'like', '%' . $searchName . '%')
                  ->orWhere('actual_name', 'like', '%' . $searchName . '%');
            });
        }
        $filteredEmployees = $filteredEmployeeQuery->with('department')->get();

        // Days of these employees with a request waiting for the CEO, in one query. The rows
        // below keep their official values; a pending day only gets a marker.
        $pendingDays = AttendanceChangeRequest::where('status', AttendanceChangeRequest::STATUS_PENDING)
            ->where('field', '!=', AttendanceChangeRequest::FIELD_ASSIGNMENT)
            ->whereBetween('work_date', [$startDate, $endDate])
            ->whereIn('employee_id', $filteredEmployees->pluck('id'))
            ->select('employee_id', 'work_date')
            ->distinct()
            ->get()
            ->mapWithKeys(fn ($r) => [$r->employee_id . '_' . substr((string) $r->work_date, 0, 10) => true])
            ->all();

        // Index attendance days by employee_id + date for quick lookup
        $attendanceIndex = [];
        foreach ($attendanceDays as $day) {
            $key = $day->employee_id . '_' . $day->work_date->format('Y-m-d');
            $attendanceIndex[$key] = $day;
        }

        // Generate rows for each date in range for each filtered employee
        $period = CarbonPeriod::create($startDate, $endDate);
        foreach ($filteredEmployees as $emp) {
            foreach ($period as $date) {
                $dateStr = $date->format('Y-m-d');
                $key = $emp->id . '_' . $dateStr;

                if (isset($attendanceIndex[$key])) {
                    // Has attendance record — present
                    $row = (object) [
                        'type' => 'present',
                        'attendance_day' => $attendanceIndex[$key],
                        'employee' => $emp,
                        'work_date' => $date->copy(),
                        'date_str' => $dateStr,
                    ];
                } elseif ($emp->isDayOff($dateStr)) {
                    // Day off
                    $row = (object) [
                        'type' => 'day_off',
                        'attendance_day' => null,
                        'employee' => $emp,
                        'work_date' => $date->copy(),
                        'date_str' => $dateStr,
                    ];
                } else {
                    // Absent
                    $row = (object) [
                        'type' => 'absent',
                        'attendance_day' => null,
                        'employee' => $emp,
                        'work_date' => $date->copy(),
                        'date_str' => $dateStr,
                    ];
                }
                $combinedRows->push($row);
            }
        }

        // Sort by date then employee name
        $combinedRows = $combinedRows->sortBy([
            ['date_str', 'asc'],
            [fn($a, $b) => strcmp($a->employee->display_name, $b->employee->display_name), 'asc'],
        ]);

        // Paginate manually
        $page = $request->input('page', 1);
        $perPage = 50;
        $total = $combinedRows->count();
        $days = new \Illuminate\Pagination\LengthAwarePaginator(
            $combinedRows->forPage($page, $perPage)->values(),
            $total,
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        // Employees with actual attendance records in this date range (for employee dropdown)
        $employeeIdsWithAttendance = AttendanceDay::whereBetween('work_date', [$startDate, $endDate])
            ->distinct()
            ->pluck('employee_id')
            ->toArray();

        // Cascade employee dropdown by selected department (if any)
        $employeesInRangeQuery = Employee::whereIn('id', $employeeIdsWithAttendance);
        if ($request->filled('department_id')) {
            $employeesInRangeQuery->where('department_id', $request->department_id);
        }
        $employeesInRange = $employeesInRangeQuery->orderBy('full_name')->get();

        // Departments with at least one employee that has attendance in this date range
        $departmentIdsInRange = Employee::whereIn('id', $employeeIdsWithAttendance)
            ->whereNotNull('department_id')
            ->distinct()
            ->pluck('department_id')
            ->toArray();
        $departmentsInRange = Department::whereIn('id', $departmentIdsInRange)
            ->orderBy('name')
            ->get();

        return view('attendance.index', compact(
            'days', 'employees', 'employeesInRange', 'shifts', 'departments', 'departmentsInRange',
            'startDate', 'endDate', 'overrides', 'pendingDays'
        ));
    }

    /**
     * API: Get employees that have records in a date range.
     */
    public function employeesInRange(Request $request)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date'   => 'required|date|after_or_equal:start_date',
        ]);

        $employeeIds = AttendanceDay::whereBetween('work_date', [$request->start_date, $request->end_date])
            ->distinct()
            ->pluck('employee_id')
            ->toArray();

        $employees = Employee::whereIn('id', $employeeIds)
            ->orderBy('full_name')
            ->get()
            ->map(function ($emp) {
                return [
                    'id' => $emp->id,
                    'name' => $emp->display_name,
                ];
            });

        return response()->json($employees);
    }

    /**
     * Return raw ZKTeco punches for an employee on a given date (read-only).
     */
    public function punches(Request $request)
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'date'        => 'required|date',
        ]);

        $employee = Employee::findOrFail($validated['employee_id']);
        $start = Carbon::parse($validated['date'])->startOfDay();
        $end = Carbon::parse($validated['date'])->endOfDay();

        $logs = AttendanceLog::where('employee_id', $employee->id)
            ->whereBetween('punched_at', [$start, $end])
            ->orderBy('punched_at')
            ->get(['punched_at']);

        return response()->json([
            'employee_name' => $employee->display_name,
            'date'    => $validated['date'],
            'count'   => $logs->count(),
            'punches' => $logs->map(fn ($l) => [
                'time'         => $l->punched_at->format('H:i:s'),
                'time_display' => $l->punched_at->format('g:i:s A'),
            ])->values(),
        ]);
    }

    /**
     * Compute attendance for a date range (respects overrides).
     */
    public function compute(Request $request)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date'   => 'required|date|after_or_equal:start_date',
        ]);

        $service = new AttendanceComputeService();
        $stats = $service->computeForDateRange(
            $request->start_date,
            $request->end_date,
            null,
            false // respect overrides
        );

        $msg = "Computed attendance: {$stats['processed']} days processed, {$stats['errors']} errors.";

        return redirect()->route('attendance.index', [
            'start_date' => $request->start_date,
            'end_date'   => $request->end_date,
        ])->with('success', $msg);
    }

    /**
     * Force recompute attendance — discards all overrides and recomputes from raw logs.
     */
    public function forceCompute(Request $request)
    {
        // CEO only — Force Recompute discards manual edits, too destructive for other roles
        if (Auth::user()?->role !== 'ceo') {
            abort(403, 'Only CEO can force recompute attendance.');
        }

        $request->validate([
            'start_date' => 'required|date',
            'end_date'   => 'required|date|after_or_equal:start_date',
        ]);

        $service = new AttendanceComputeService();
        $stats = $service->forceRecomputeForDateRange(
            $request->start_date,
            $request->end_date
        );

        $msg = "Force recomputed: {$stats['processed']} days processed, {$stats['errors']} errors, {$stats['overrides_deleted']} manual edits discarded.";

        return redirect()->route('attendance.index', [
            'start_date' => $request->start_date,
            'end_date'   => $request->end_date,
        ])->with('warning', $msg);
    }

    /**
     * API: Count overrides in a date range (for force recompute warning).
     */
    public function countOverrides(Request $request)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date'   => 'required|date|after_or_equal:start_date',
        ]);

        $service = new AttendanceComputeService();
        $count = $service->countOverridesInRange($request->start_date, $request->end_date);

        return response()->json(['count' => $count]);
    }

    /**
     * Save a time field or the shift of one day. The same rule as on the Attendance
     * Calendar: never on a locked date; written at once for the CEO; stored as a request
     * for HR staff and admins. The day is named by its attendance_day_id or, when it has
     * no row, by employee and date.
     */
    public function override(Request $request)
    {
        $validated = $request->validate([
            'attendance_day_id' => 'nullable|exists:attendance_days,id',
            'employee_id'       => 'required_without:attendance_day_id|nullable|exists:employees,id',
            'work_date'         => 'required_without:attendance_day_id|nullable|date',
            'field'             => 'required|in:time_in,lunch_out,lunch_in,time_out,shift_id',
            'new_value'         => 'nullable|string',
            'reason'            => 'required|string|min:3|max:500',
        ]);
        $field = $validated['field'];
        $request->validate([
            'new_value' => $field === 'shift_id'
                ? 'required|integer|exists:shifts,id'
                : ['nullable', 'string', $this->timeRule()],
        ]);

        if (!empty($validated['attendance_day_id'])) {
            $day      = AttendanceDay::findOrFail($validated['attendance_day_id']);
            $employee = Employee::findOrFail($day->employee_id);
            $dateStr  = $day->work_date->format('Y-m-d');
        } else {
            $employee = Employee::findOrFail($validated['employee_id']);
            $dateStr  = Carbon::parse($validated['work_date'])->toDateString();
            $day      = $this->changes->findDay($employee->id, $dateStr);
        }
        $this->changes->assertDateNotLocked($dateStr);

        $reason = $validated['reason'];
        $user   = Auth::user();

        if ($field === 'shift_id') {
            $newValue      = (string) (int) $validated['new_value'];
            $requestField  = AttendanceChangeRequest::FIELD_SHIFT;
        } else {
            $newValue      = !empty($validated['new_value']) ? AttendanceChangeService::normalizeTime($validated['new_value']) : null;
            $requestField  = $field;
        }

        if (AttendanceChangeRequestService::mustRequest($user)) {
            if ($this->requests->officialValue($employee, $dateStr, $requestField) === $newValue) {
                return response()->json(['success' => true, 'message' => 'No changes to save.']);
            }
            try {
                return $this->pendingResponse(
                    $this->requests->submit($employee, $dateStr, [$requestField => $newValue], $reason, $user, 'attendance_override')
                );
            } catch (AttendanceChangeRefused $e) {
                return $this->refusedResponse($e);
            }
        }

        if ($field === 'shift_id') {
            // The shift of a day is its single-day assignment, as on the calendar: setting
            // attendance_days.shift_id alone is put back by the next recompute.
            $this->changes->assignShiftForDate(
                $employee, $dateStr, (int) $newValue, 'Set via attendance page', AttendanceChangeService::RECOMPUTE_DAY
            );
        } elseif (!$day && $newValue === null) {
            return response()->json(['success' => true, 'message' => 'No changes to save.']);
        } else {
            $this->changes->applyTimes(
                $employee, $dateStr,
                [$field => ['value' => $newValue, 'reason' => $reason]],
                $user,
                'Manually created by CEO from absent row.'
            );
        }

        return response()->json(['success' => true, 'message' => 'Override saved and metrics recomputed.']);
    }

    /**
     * Create an attendance day record for an absent day. Never on a locked date. Only the
     * CEO gets a row: for HR staff and admins nothing is created, because a blank row would
     * already count as a worked day; the page then sends the time with employee and date.
     */
    public function createDay(Request $request)
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'work_date'   => 'required|date',
        ]);
        $dateStr = Carbon::parse($validated['work_date'])->toDateString();
        $this->changes->assertDateNotLocked($dateStr);

        $employee = Employee::findOrFail($validated['employee_id']);
        $existing = $this->changes->findDay($employee->id, $dateStr);

        if (AttendanceChangeRequestService::mustRequest(Auth::user())) {
            return response()->json([
                'success'           => true,
                'pending'           => true,
                'attendance_day_id' => $existing?->id,
                'message'           => 'Enter the time and save. It will be sent for approval.',
            ]);
        }

        if ($existing) {
            return response()->json([
                'success' => true,
                'attendance_day_id' => $existing->id,
                'message' => 'Attendance day already exists.',
            ]);
        }

        $day = $this->changes->createBlankDay($employee, $dateStr, 'Manually created by CEO from absent row.');

        return response()->json([
            'success' => true,
            'attendance_day_id' => $day->id,
            'message' => 'Attendance day created.',
        ]);
    }

    /**
     * Export current filter as CSV.
     */
    public function exportCsv(Request $request): StreamedResponse
    {
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        if (!$startDate || !$endDate) {
            $defaults = $this->getLastCompletedCutoff();
            $startDate = $startDate ?: $defaults['start'];
            $endDate = $endDate ?: $defaults['end'];
        }

        $query = AttendanceDay::with(['employee.department', 'shift'])
            ->whereBetween('work_date', [$startDate, $endDate])
            ->orderBy('work_date')
            ->orderBy('employee_id');

        if ($request->filled('search_name')) {
            $searchName = $request->search_name;
            $query->whereHas('employee', function ($q) use ($searchName) {
                $q->where('full_name', 'like', '%' . $searchName . '%')
                  ->orWhere('actual_name', 'like', '%' . $searchName . '%');
            });
        }
        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }
        if ($request->filled('shift_id')) {
            $query->where('shift_id', $request->shift_id);
        }
        if ($request->filled('department_id')) {
            $deptId = $request->department_id;
            $query->whereHas('employee', function ($q) use ($deptId) {
                $q->where('department_id', $deptId);
            });
        }
        if ($request->filled('needs_review')) {
            $query->where('needs_review', $request->needs_review === '1');
        }

        $days = $query->get();

        $filename = "attendance_{$startDate}_to_{$endDate}.csv";

        return response()->streamDownload(function () use ($days) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'Employee', 'Department', 'Date', 'Shift', 'Time In', 'Lunch Out', 'Lunch In', 'Time Out',
                'Work Min', 'Late Min', 'Early Min', 'OT Min', 'Payable Min', 'Needs Review', 'Notes',
            ]);

            foreach ($days as $day) {
                fputcsv($handle, [
                    $day->employee->display_name ?? '',
                    $day->employee->department->name ?? '',
                    $day->work_date->format('Y-m-d'),
                    $day->shift->name ?? '',
                    $day->time_in ? Carbon::parse($day->time_in)->format('H:i') : '',
                    $day->lunch_out ? Carbon::parse($day->lunch_out)->format('H:i') : '',
                    $day->lunch_in ? Carbon::parse($day->lunch_in)->format('H:i') : '',
                    $day->time_out ? Carbon::parse($day->time_out)->format('H:i') : '',
                    $day->computed_work_minutes,
                    $day->computed_late_minutes,
                    $day->computed_early_minutes,
                    $day->computed_overtime_minutes,
                    $day->payable_work_minutes,
                    $day->needs_review ? 'YES' : 'NO',
                    $day->notes ?? '',
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * Print-friendly view.
     */
    public function printView(Request $request)
    {
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        if (!$startDate || !$endDate) {
            $defaults = $this->getLastCompletedCutoff();
            $startDate = $startDate ?: $defaults['start'];
            $endDate = $endDate ?: $defaults['end'];
        }

        $query = AttendanceDay::with(['employee.department', 'shift'])
            ->whereBetween('work_date', [$startDate, $endDate])
            ->orderBy('work_date')
            ->orderBy('employee_id');

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }
        if ($request->filled('search_name')) {
            $searchName = $request->search_name;
            $query->whereHas('employee', function ($q) use ($searchName) {
                $q->where('full_name', 'like', '%' . $searchName . '%')
                  ->orWhere('actual_name', 'like', '%' . $searchName . '%');
            });
        }
        if ($request->filled('department_id')) {
            $deptId = $request->department_id;
            $query->whereHas('employee', function ($q) use ($deptId) {
                $q->where('department_id', $deptId);
            });
        }

        $days = $query->get();

        return view('attendance.print', compact('days', 'startDate', 'endDate'));
    }

    /**
     * Get the last completed cutoff period.
     * Cutoff periods: 11-25 and 26-10(next month)
     * Returns the most recently completed cutoff.
     */
    protected function getLastCompletedCutoff(): array
    {
        $today = Carbon::today();
        $day = $today->day;

        if ($day >= 26) {
            // We're in the 26-10 cutoff period, so last completed = 11-25 of this month
            return [
                'start' => $today->copy()->day(11)->format('Y-m-d'),
                'end'   => $today->copy()->day(25)->format('Y-m-d'),
            ];
        } elseif ($day >= 11) {
            // We're in the 11-25 cutoff period, so last completed = 26(prev month)-10(this month)
            return [
                'start' => $today->copy()->subMonth()->day(26)->format('Y-m-d'),
                'end'   => $today->copy()->day(10)->format('Y-m-d'),
            ];
        } else {
            // Day 1-10: We're in the 26-10 cutoff period, so last completed = 11-25 of prev month
            return [
                'start' => $today->copy()->subMonth()->day(11)->format('Y-m-d'),
                'end'   => $today->copy()->subMonth()->day(25)->format('Y-m-d'),
            ];
        }
    }

    /**
     * Get the current ongoing cutoff period.
     */
    protected function getCurrentCutoff(): array
    {
        $today = Carbon::today();
        $day = $today->day;

        if ($day >= 26) {
            // Current cutoff: 26 this month to 10 next month
            return [
                'start' => $today->copy()->day(26)->format('Y-m-d'),
                'end'   => $today->copy()->addMonth()->day(10)->format('Y-m-d'),
            ];
        } elseif ($day >= 11) {
            // Current cutoff: 11-25 this month
            return [
                'start' => $today->copy()->day(11)->format('Y-m-d'),
                'end'   => $today->copy()->day(25)->format('Y-m-d'),
            ];
        } else {
            // Day 1-10: Current cutoff: 26 prev month to 10 this month
            return [
                'start' => $today->copy()->subMonth()->day(26)->format('Y-m-d'),
                'end'   => $today->copy()->day(10)->format('Y-m-d'),
            ];
        }
    }

    /**
     * API: Get cutoff date ranges for quick buttons.
     */
    public function cutoffDates()
    {
        return response()->json([
            'this_cutoff' => $this->getCurrentCutoff(),
            'last_cutoff' => $this->getLastCompletedCutoff(),
        ]);
    }
}
