<?php

namespace App\Http\Controllers;

use App\Models\AttendanceDay;
use App\Models\Department;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AttendanceCalendarController extends Controller
{
    /**
     * Show the Attendance Calendar page.
     * Monthly grid view — rows = employees, columns = days of month.
     * Only shows active employees that have at least one attendance log in the selected month.
     */
    public function index(Request $request)
    {
        $departments = Department::orderBy('name')->get();

        // Default to current month
        $month = $request->input('month', now()->format('Y-m'));
        $startOfMonth = Carbon::parse($month . '-01')->startOfMonth();
        $endOfMonth = $startOfMonth->copy()->endOfMonth();

        $filterType = $request->input('filter_type', 'all'); // all, department, employee
        $departmentId = $request->input('department_id');
        $employeeId = $request->input('employee_id');

        // Get employee IDs that have attendance records in this month
        $employeeIdsWithLogs = AttendanceDay::whereBetween('work_date', [$startOfMonth, $endOfMonth])
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

        // Preload all attendance days for the month for these employees
        $attendanceDays = AttendanceDay::with('shift')
            ->whereIn('employee_id', $filteredEmployees->pluck('id'))
            ->whereBetween('work_date', [$startOfMonth, $endOfMonth])
            ->get();

        // Index by employee_id + date
        $attendanceIndex = [];
        foreach ($attendanceDays as $day) {
            $key = $day->employee_id . '_' . $day->work_date->format('Y-m-d');
            $attendanceIndex[$key] = $day;
        }

        // Build calendar data
        $calendarData = [];
        $daysInMonth = $startOfMonth->daysInMonth;

        foreach ($filteredEmployees as $emp) {
            $empData = [
                'employee' => $emp,
                'days' => [],
            ];

            for ($d = 1; $d <= $daysInMonth; $d++) {
                $date = $startOfMonth->copy()->day($d);
                $dateStr = $date->format('Y-m-d');
                $key = $emp->id . '_' . $dateStr;

                $attDay = $attendanceIndex[$key] ?? null;

                if ($attDay) {
                    // Has attendance record — determine status
                    $status = 'present'; // default
                    $lateMin = $attDay->computed_late_minutes ?? 0;
                    $earlyMin = $attDay->computed_early_minutes ?? 0;

                    if ($lateMin > 0 && $earlyMin > 0) {
                        $status = 'late_ut';
                    } elseif ($lateMin > 0) {
                        $status = 'late';
                    } elseif ($earlyMin > 0) {
                        $status = 'undertime';
                    }

                    $empData['days'][$d] = [
                        'date' => $dateStr,
                        'status' => $status,
                        'attendance' => $attDay,
                    ];
                } elseif ($emp->isDayOff($dateStr)) {
                    $empData['days'][$d] = [
                        'date' => $dateStr,
                        'status' => 'day_off',
                        'attendance' => null,
                    ];
                } else {
                    $empData['days'][$d] = [
                        'date' => $dateStr,
                        'status' => 'absent',
                        'attendance' => null,
                    ];
                }
            }

            $calendarData[] = $empData;
        }

        return view('attendance-calendar.index', compact(
            'departments', 'employees', 'calendarData',
            'month', 'startOfMonth', 'daysInMonth',
            'filterType', 'departmentId', 'employeeId'
        ));
    }
}
