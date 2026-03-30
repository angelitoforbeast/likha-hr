<?php

namespace App\Http\Controllers;

use App\Models\AttendanceDay;
use App\Models\AttendanceOverride;
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

        // Preload overrides for these attendance days to show edit indicators
        $attDayIds = $attendanceDays->pluck('id')->toArray();
        $overrides = AttendanceOverride::whereIn('attendance_day_id', $attDayIds)
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

                    if ($lateMin > 0 && $earlyMin > 0) {
                        $status = 'late_ut';
                    } elseif ($lateMin > 0) {
                        $status = 'late';
                    } elseif ($earlyMin > 0) {
                        $status = 'undertime';
                    }

                    // Check if any overrides exist for this day
                    $hasOverrides = isset($overrides[$attDay->id]);

                    $empData['days'][$idx] = [
                        'date' => $dateStr,
                        'status' => $status,
                        'attendance' => $attDay,
                        'has_overrides' => $hasOverrides,
                    ];
                } elseif ($emp->isDayOff($dateStr)) {
                    $empData['days'][$idx] = [
                        'date' => $dateStr,
                        'status' => 'day_off',
                        'attendance' => null,
                        'has_overrides' => false,
                    ];
                } else {
                    $empData['days'][$idx] = [
                        'date' => $dateStr,
                        'status' => 'absent',
                        'attendance' => null,
                        'has_overrides' => false,
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
}
