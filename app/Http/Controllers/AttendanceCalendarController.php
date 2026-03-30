<?php

namespace App\Http\Controllers;

use App\Models\AttendanceDay;
use App\Models\AttendanceOverride;
use App\Models\DayOff;
use App\Models\Department;
use App\Models\Employee;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;

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

        $filteredEmployees = $query->orderBy('full_name')->get();

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

                if ($attDay) {
                    $status = 'present';
                    $lateMin = $attDay->computed_late_minutes ?? 0;
                    $earlyMin = $attDay->computed_early_minutes ?? 0;

                    // Merge late and undertime into single "undertime" status
                    if ($lateMin > 0 || $earlyMin > 0) {
                        $status = 'undertime';
                    }

                    // Check if any overrides exist for this day
                    $dayOverrides = $overrides[$attDay->id] ?? collect();
                    $hasOverrides = $dayOverrides->isNotEmpty();

                    // Build per-field override details for modal display
                    $overrideDetails = [];
                    foreach ($dayOverrides as $ov) {
                        $overrideDetails[] = [
                            'field' => $ov->field,
                            'old_value' => $ov->old_value,
                            'new_value' => $ov->new_value,
                            'reason' => $ov->reason,
                            'updater' => $ov->updater->name ?? 'Unknown',
                            'date' => $ov->created_at->format('M d, Y g:i A'),
                        ];
                    }

                    $empData['days'][$idx] = [
                        'date' => $dateStr,
                        'status' => $status,
                        'attendance' => $attDay,
                        'has_overrides' => $hasOverrides,
                        'override_details' => $overrideDetails,
                    ];
                } elseif ($emp->isDayOff($dateStr)) {
                    $empData['days'][$idx] = [
                        'date' => $dateStr,
                        'status' => 'day_off',
                        'attendance' => null,
                        'has_overrides' => false,
                        'override_details' => [],
                    ];
                } else {
                    $empData['days'][$idx] = [
                        'date' => $dateStr,
                        'status' => 'absent',
                        'attendance' => null,
                        'has_overrides' => false,
                        'override_details' => [],
                    ];
                }
            }

            $calendarData[] = $empData;
        }

        return view('attendance-calendar.index', compact(
            'departments', 'employees', 'calendarData',
            'dates', 'totalDays', 'dateFrom', 'dateTo',
            'filterType', 'departmentId', 'employeeId'
        ));
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
        ]);

        $employee = Employee::findOrFail($validated['employee_id']);
        $date = $validated['date'];

        $existing = DayOff::where('employee_id', $employee->id)
            ->where('off_date', $date)
            ->first();

        if ($validated['action'] === 'remove_override') {
            if ($existing) $existing->delete();
            $message = 'Override removed.';
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
        }

        // Return updated status
        $isDayOff = $employee->isDayOff($date);

        return response()->json([
            'success' => true,
            'message' => $message,
            'is_day_off' => $isDayOff,
        ]);
    }
}
