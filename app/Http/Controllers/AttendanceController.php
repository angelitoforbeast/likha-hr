<?php

namespace App\Http\Controllers;

use App\Models\AttendanceDay;
use App\Models\AttendanceOverride;
use App\Models\CutoffRule;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Shift;
use App\Services\AttendanceComputeService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttendanceController extends Controller
{
    /**
     * Attendance viewer with filters.
     */
    public function index(Request $request)
    {
        $cutoffRules = CutoffRule::all();
        $employees = Employee::where('status', 'active')->orderBy('full_name')->get();
        $shifts = Shift::all();
        $departments = Department::orderBy('name')->get();

        $dateRange = $this->resolveDateRange($request);
        $startDate = $dateRange['start'];
        $endDate = $dateRange['end'];

        $query = AttendanceDay::with(['employee.department', 'shift'])
            ->whereBetween('work_date', [$startDate, $endDate])
            ->orderBy('work_date')
            ->orderBy('employee_id');

        if ($request->filled('search_name')) {
            $searchName = $request->search_name;
            $query->whereHas('employee', function ($q) use ($searchName) {
                $q->where('full_name', 'like', '%' . $searchName . '%');
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

        $days = $query->paginate(50)->withQueryString();

        // Load overrides for each attendance day to show edit indicators
        $dayIds = $days->pluck('id')->toArray();
        $overrides = AttendanceOverride::whereIn('attendance_day_id', $dayIds)
            ->with('updater')
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('attendance_day_id');

        return view('attendance.index', compact(
            'days', 'cutoffRules', 'employees', 'shifts', 'departments',
            'startDate', 'endDate', 'overrides'
        ));
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
     * Save an override for a time field or shift.
     */
    public function override(Request $request)
    {
        $request->validate([
            'attendance_day_id' => 'required|exists:attendance_days,id',
            'field'             => 'required|in:time_in,lunch_out,lunch_in,time_out,shift_id',
            'new_value'         => 'nullable|string',
            'reason'            => 'required|string|min:3|max:500',
        ]);

        $day = AttendanceDay::findOrFail($request->attendance_day_id);
        $field = $request->field;
        $oldValue = null;
        $newValue = $request->new_value;

        if ($field === 'shift_id') {
            $oldValue = $day->shift_id ? (string) $day->shift_id : null;
            $day->shift_id = $newValue ?: null;
        } else {
            $oldValue = $day->{$field} ? Carbon::parse($day->{$field})->format('H:i') : null;

            if ($newValue) {
                $day->{$field} = Carbon::parse($day->work_date->format('Y-m-d') . ' ' . $newValue);
            } else {
                $day->{$field} = null;
            }
        }

        $day->save();

        AttendanceOverride::create([
            'attendance_day_id' => $day->id,
            'employee_id'       => $day->employee_id,
            'work_date'         => $day->work_date,
            'field'             => $field,
            'old_value'         => $oldValue,
            'new_value'         => $newValue,
            'reason'            => $request->reason,
            'updated_by'        => Auth::id(),
        ]);

        if ($field !== 'shift_id' || $newValue) {
            $day->load('shift');
            $service = new AttendanceComputeService();
            $service->recomputeDay($day);
        }

        return response()->json(['success' => true, 'message' => 'Override saved and metrics recomputed.']);
    }

    /**
     * Export current filter as CSV.
     */
    public function exportCsv(Request $request): StreamedResponse
    {
        $dateRange = $this->resolveDateRange($request);
        $startDate = $dateRange['start'];
        $endDate = $dateRange['end'];

        $query = AttendanceDay::with(['employee.department', 'shift'])
            ->whereBetween('work_date', [$startDate, $endDate])
            ->orderBy('work_date')
            ->orderBy('employee_id');

        if ($request->filled('search_name')) {
            $searchName = $request->search_name;
            $query->whereHas('employee', function ($q) use ($searchName) {
                $q->where('full_name', 'like', '%' . $searchName . '%');
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
                    $day->employee->full_name ?? '',
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
        $dateRange = $this->resolveDateRange($request);
        $startDate = $dateRange['start'];
        $endDate = $dateRange['end'];

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
                $q->where('full_name', 'like', '%' . $searchName . '%');
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
     * Resolve date range from request (cutoff rule or manual dates).
     */
    protected function resolveDateRange(Request $request): array
    {
        if ($request->filled('cutoff_rule_id')) {
            $rule = CutoffRule::find($request->cutoff_rule_id);
            if ($rule && $rule->rule_json) {
                return $this->computeCutoffDates($rule, $request->input('cutoff_month', now()->format('Y-m')));
            }
        }

        $start = $request->input('start_date', now()->startOfMonth()->format('Y-m-d'));
        $end = $request->input('end_date', now()->format('Y-m-d'));

        return ['start' => $start, 'end' => $end];
    }

    /**
     * Compute actual start/end dates from a cutoff rule and reference month.
     */
    protected function computeCutoffDates(CutoffRule $rule, string $yearMonth): array
    {
        $ranges = $rule->rule_json['ranges'] ?? [];
        if (empty($ranges)) {
            return ['start' => now()->startOfMonth()->format('Y-m-d'), 'end' => now()->format('Y-m-d')];
        }

        $range = $ranges[0];
        $ref = Carbon::parse($yearMonth . '-01');

        $startDay = $range['start_day'];
        $endDay = $range['end_day'];
        $crossMonth = $range['cross_month'] ?? false;

        $start = $ref->copy()->day(min($startDay, $ref->daysInMonth));

        if ($crossMonth) {
            $end = $ref->copy()->addMonth()->day(min($endDay, $ref->copy()->addMonth()->daysInMonth));
        } else {
            $end = $ref->copy()->day(min($endDay, $ref->daysInMonth));
        }

        return [
            'start' => $start->format('Y-m-d'),
            'end'   => $end->format('Y-m-d'),
        ];
    }
}
