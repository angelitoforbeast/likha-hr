<?php

namespace App\Http\Controllers;

use App\Models\DayOff;
use App\Models\Department;
use App\Models\Employee;
use App\Models\RestDayPattern;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;

class DayOffCalendarController extends Controller
{
    /**
     * Show the Day Off Calendar page.
     */
    public function index(Request $request)
    {
        $departments = Department::orderBy('name')->get();
        $employees = Employee::where('status', 'active')->orderBy('full_name')->get();

        // Default to current month
        $month = $request->input('month', now()->format('Y-m'));
        $startOfMonth = Carbon::parse($month . '-01')->startOfMonth();
        $endOfMonth = $startOfMonth->copy()->endOfMonth();

        $filterType = $request->input('filter_type', 'all'); // all, department, employee
        $departmentId = $request->input('department_id');
        $employeeId = $request->input('employee_id');

        // Get filtered employees
        $query = Employee::where('status', 'active');
        if ($filterType === 'department' && $departmentId) {
            $query->where('department_id', $departmentId);
        } elseif ($filterType === 'employee' && $employeeId) {
            $query->where('id', $employeeId);
        }
        $filteredEmployees = $query->orderBy('full_name')->get();

        // Build calendar data
        $calendarData = [];
        $daysInMonth = $startOfMonth->daysInMonth;

        foreach ($filteredEmployees as $emp) {
            $empData = [
                'employee' => $emp,
                'days' => [],
            ];

            // Get rest day patterns for this month
            $patterns = $emp->restDayPatterns()
                ->where('effective_from', '<=', $endOfMonth)
                ->where(function ($q) use ($startOfMonth) {
                    $q->whereNull('effective_until')
                      ->orWhere('effective_until', '>=', $startOfMonth);
                })
                ->get();

            // Get day off overrides for this month
            $overrides = $emp->dayOffs()
                ->whereBetween('off_date', [$startOfMonth, $endOfMonth])
                ->get()
                ->keyBy(function ($item) {
                    return $item->off_date->format('Y-m-d');
                });

            for ($d = 1; $d <= $daysInMonth; $d++) {
                $date = $startOfMonth->copy()->day($d);
                $dateStr = $date->format('Y-m-d');
                $dayOfWeek = $date->dayOfWeek;

                $status = 'work'; // default

                // Check rest day patterns
                foreach ($patterns as $pattern) {
                    if ($pattern->isActiveOn($dateStr) && $pattern->day_of_week === $dayOfWeek) {
                        $status = 'rest_day';
                        break;
                    }
                }

                // Check overrides
                if (isset($overrides[$dateStr])) {
                    $override = $overrides[$dateStr];
                    if ($override->type === DayOff::TYPE_DAY_OFF) {
                        $status = 'day_off'; // extra day off
                    } elseif ($override->type === DayOff::TYPE_CANCEL_DAY_OFF) {
                        $status = 'work'; // cancelled rest day, must work
                    }
                }

                $empData['days'][$d] = [
                    'date' => $dateStr,
                    'status' => $status,
                    'has_override' => isset($overrides[$dateStr]),
                ];
            }

            $calendarData[] = $empData;
        }

        return view('dayoff.index', compact(
            'departments', 'employees', 'calendarData',
            'month', 'startOfMonth', 'daysInMonth',
            'filterType', 'departmentId', 'employeeId'
        ));
    }

    /**
     * Toggle a day off via AJAX.
     */
    public function toggle(Request $request)
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
                $existing->update(['type' => DayOff::TYPE_DAY_OFF, 'remarks' => 'Set via calendar']);
            } else {
                DayOff::create([
                    'employee_id' => $employee->id,
                    'off_date' => $date,
                    'type' => DayOff::TYPE_DAY_OFF,
                    'remarks' => 'Set via calendar',
                ]);
            }
            $message = 'Day off added.';
        } elseif ($validated['action'] === 'cancel_day_off') {
            if ($existing) {
                $existing->update(['type' => DayOff::TYPE_CANCEL_DAY_OFF, 'remarks' => 'Cancelled via calendar']);
            } else {
                DayOff::create([
                    'employee_id' => $employee->id,
                    'off_date' => $date,
                    'type' => DayOff::TYPE_CANCEL_DAY_OFF,
                    'remarks' => 'Cancelled via calendar',
                ]);
            }
            $message = 'Day off cancelled (must work).';
        }

        // Return updated status for the cell
        $isDayOff = $employee->isDayOff($date);

        return response()->json([
            'success' => true,
            'message' => $message,
            'is_day_off' => $isDayOff,
        ]);
    }

    /**
     * Get day off data for a specific employee and month (AJAX).
     */
    public function employeeMonth(Request $request)
    {
        $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'month'        => 'required|date_format:Y-m',
        ]);

        $employee = Employee::findOrFail($request->employee_id);
        $startOfMonth = Carbon::parse($request->month . '-01')->startOfMonth();
        $endOfMonth = $startOfMonth->copy()->endOfMonth();

        $days = [];
        for ($d = 1; $d <= $startOfMonth->daysInMonth; $d++) {
            $date = $startOfMonth->copy()->day($d)->format('Y-m-d');
            $days[$d] = [
                'date' => $date,
                'is_day_off' => $employee->isDayOff($date),
            ];
        }

        return response()->json(['days' => $days]);
    }
}
