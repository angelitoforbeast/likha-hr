<?php

namespace App\Http\Controllers;

use App\Models\BenefitType;
use App\Models\CashAdvance;
use App\Models\DayOff;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeBenefit;
use App\Models\EmployeeRate;
use Carbon\Carbon;
use App\Models\EmployeeShiftAssignment;
use App\Models\EmployeeStatusHistory;
use App\Models\EmploymentStatus;
use App\Models\RestDayPattern;
use App\Models\Shift;
use App\Models\FeaturePermission;
use Illuminate\Http\Request;
use App\Models\EmployeeActiveStatus;
use Illuminate\Support\Facades\Auth;

class EmployeeController extends Controller
{
    public function index(Request $request)
    {
        $query = Employee::with(['defaultShift', 'department']);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', '%' . $search . '%')
                  ->orWhere('actual_name', 'like', '%' . $search . '%');
            });
        }

        // Default filter: active + with department (when no filters explicitly set)
        $hasAnyFilter = $request->filled('search') || $request->has('status') || $request->filled('department_id') || $request->has('has_department');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        } elseif (!$hasAnyFilter) {
            // Default: show active only
            $query->where('status', 'active');
        }

        if ($request->filled('department_id')) {
            $query->where('department_id', $request->department_id);
        }

        // "Has Department" filter
        if ($request->has('has_department') && $request->has_department == '1') {
            $query->whereNotNull('department_id');
        } elseif ($request->has('has_department') && $request->has_department == '0') {
            $query->whereNull('department_id');
        } elseif (!$hasAnyFilter) {
            // Default: with department only
            $query->whereNotNull('department_id');
        }

        $employees = $query->orderBy('full_name')->paginate(25);
        $shifts = Shift::all();
        $departments = Department::orderBy('name')->get();

        $employees->getCollection()->transform(function ($emp) {
            $emp->current_shift = $emp->getCurrentShift();
            $emp->current_rate = $emp->getCurrentRate();
            $emp->current_status = $emp->getCurrentStatus();
            return $emp;
        });

        return view('employees.index', compact('employees', 'shifts', 'departments'));
    }

    public function edit(Employee $employee)
    {
        $shifts = Shift::orderBy('name')->get();
        $departments = Department::orderBy('name')->get();
        $employmentStatuses = EmploymentStatus::orderBy('sort_order')->get();
        $benefitTypes = BenefitType::active()->orderBy('sort_order')->get();

        $employee->load([
            'shiftAssignments.shift',
            'employeeRates',
            'department',
            'statusHistory.employmentStatus',
            'benefits.benefitType',
            'restDayPatterns',
            'dayOffs',
            'cashAdvances',
            'activeStatuses',
        ]);

        $userRole = Auth::user()->role;
        $permissions = FeaturePermission::getForRole($userRole);

        return view('employees.edit', compact(
            'employee', 'shifts', 'departments',
            'employmentStatuses', 'benefitTypes', 'permissions'
        ));
    }

    public function update(Request $request, Employee $employee)
    {
        $validated = $request->validate([
            'actual_name'                  => 'nullable|string|max:255',
            'default_shift_id'             => 'nullable|exists:shifts,id',
            'department_id'                => 'nullable|exists:departments,id',
            'schedule_mode'                => 'required|in:department,manual',
            'night_differential_eligible'  => 'sometimes|boolean',
        ]);

        // If employee has no department, force manual mode
        if (empty($validated['department_id'])) {
            $validated['schedule_mode'] = Employee::MODE_MANUAL;
        }

        $validated['night_differential_eligible'] = $request->has('night_differential_eligible');

        $employee->update($validated);

        return redirect()->route('employees.edit', $employee)
            ->with('success', "Employee {$employee->display_name} updated successfully.");
    }

    /* ── Shift Assignments ── */

    public function assignShift(Request $request, Employee $employee)
    {
        $this->guardFeature('shift_assignments');
        $validated = $request->validate([
            'shift_id'        => 'required|exists:shifts,id',
            'effective_date'  => 'required|date',
            'effective_until' => 'nullable|date|after_or_equal:effective_date',
            'remarks'         => 'nullable|string|max:500',
        ]);

        // Check for overlapping assignments
        $overlap = $this->checkShiftOverlap($employee->id, $validated['effective_date'], $validated['effective_until'] ?? null);
        if ($overlap) {
            return redirect()->route('employees.edit', $employee)
                ->with('error', "Shift assignment overlaps with existing assignment (effective {$overlap->effective_date->format('M d, Y')}).");
        }

        $employee->shiftAssignments()->create($validated);
        $message = "Shift assignment added effective {$validated['effective_date']}.";

        if ($employee->isDepartmentMode()) {
            $employee->update(['schedule_mode' => Employee::MODE_MANUAL]);
            $message .= ' Schedule mode set to Manual.';
        }

        return redirect()->route('employees.edit', $employee)
            ->with('success', $message);
    }

    public function deleteShiftAssignment(Employee $employee, EmployeeShiftAssignment $assignment)
    {
        $this->guardFeature('shift_assignments');
        if ($assignment->employee_id !== $employee->id) abort(403);
        $assignment->delete();
        return redirect()->route('employees.edit', $employee)->with('success', 'Shift assignment removed.');
    }

    /* ── Rates ── */

    public function addRate(Request $request, Employee $employee)
    {
        $this->guardFeature('daily_rates');
        $validated = $request->validate([
            'daily_rate'      => 'required|numeric|min:0|max:999999.99',
            'effective_date'  => 'required|date',
            'effective_until' => 'nullable|date|after_or_equal:effective_date',
            'remarks'         => 'nullable|string|max:500',
        ]);

        $newStart = $validated['effective_date'];

        // Auto-close any open-ended rate that starts before the new rate
        $openRates = EmployeeRate::where('employee_id', $employee->id)
            ->whereNull('effective_until')
            ->where('effective_date', '<', $newStart)
            ->get();

        foreach ($openRates as $openRate) {
            $openRate->effective_until = Carbon::parse($newStart)->subDay()->format('Y-m-d');
            $openRate->save();
        }

        // Check for remaining overlaps (with closed-end rates)
        $overlap = $this->checkRateOverlap($employee->id, $newStart, $validated['effective_until'] ?? null);
        if ($overlap) {
            return redirect()->route('employees.edit', $employee)
                ->with('error', "Rate overlaps with existing rate (effective {$overlap->effective_date->format('M d, Y')}).");
        }

        $employee->employeeRates()->create($validated);

        return redirect()->route('employees.edit', $employee)
            ->with('success', "Rate added effective {$newStart}. Previous open rate auto-closed.");
    }

    public function deleteRate(Employee $employee, EmployeeRate $rate)
    {
        $this->guardFeature('daily_rates');
        if ($rate->employee_id !== $employee->id) abort(403);
        $rate->delete();
        return redirect()->route('employees.edit', $employee)->with('success', 'Rate entry removed.');
    }

    /* ── Employment Status ── */

    public function addStatus(Request $request, Employee $employee)
    {
        $this->guardFeature('employment_status');
        $validated = $request->validate([
            'employment_status_id' => 'required|exists:employment_statuses,id',
            'effective_from'       => 'required|date',
            'effective_until'      => 'nullable|date|after_or_equal:effective_from',
            'remarks'              => 'nullable|string|max:500',
        ]);

        $employee->statusHistory()->create($validated);

        return redirect()->route('employees.edit', $employee)
            ->with('success', 'Employment status added.');
    }

    public function deleteStatus(Employee $employee, EmployeeStatusHistory $status)
    {
        $this->guardFeature('employment_status');
        if ($status->employee_id !== $employee->id) abort(403);
        $status->delete();
        return redirect()->route('employees.edit', $employee)->with('success', 'Employment status removed.');
    }

    /* ── Benefits ── */

    public function addBenefit(Request $request, Employee $employee)
    {
        $this->guardFeature('benefits_deductions');
        $validated = $request->validate([
            'benefit_type_id' => 'required|exists:benefit_types,id',
            'is_eligible'     => 'sometimes|boolean',
            'amount'          => 'required|numeric|min:0|max:999999.99',
            'effective_from'  => 'required|date',
            'effective_until' => 'nullable|date|after_or_equal:effective_from',
            'remarks'         => 'nullable|string|max:500',
        ]);

        $validated['is_eligible'] = $request->has('is_eligible') ? true : true; // default eligible when adding

        $employee->benefits()->create($validated);

        return redirect()->route('employees.edit', $employee)
            ->with('success', 'Benefit/deduction added.');
    }

    public function deleteBenefit(Employee $employee, EmployeeBenefit $benefit)
    {
        $this->guardFeature('benefits_deductions');
        if ($benefit->employee_id !== $employee->id) abort(403);
        $benefit->delete();
        return redirect()->route('employees.edit', $employee)->with('success', 'Benefit/deduction removed.');
    }

    /* ── Rest Day Patterns ── */

    public function addRestDay(Request $request, Employee $employee)
    {
        $this->guardFeature('rest_day_pattern');
        $validated = $request->validate([
            'day_of_week'     => 'required|integer|between:0,6',
            'effective_from'  => 'required|date',
            'effective_until' => 'nullable|date|after_or_equal:effective_from',
            'remarks'         => 'nullable|string|max:500',
        ]);

        $employee->restDayPatterns()->create($validated);

        return redirect()->route('employees.edit', $employee)
            ->with('success', 'Rest day pattern added.');
    }

    public function deleteRestDay(Employee $employee, RestDayPattern $restday)
    {
        $this->guardFeature('rest_day_pattern');
        if ($restday->employee_id !== $employee->id) abort(403);
        $restday->delete();
        return redirect()->route('employees.edit', $employee)->with('success', 'Rest day pattern removed.');
    }

    /* ── Day Off Overrides ── */

    public function addDayOff(Request $request, Employee $employee)
    {
        $this->guardFeature('day_off_overrides');
        $validated = $request->validate([
            'off_date' => 'required|date',
            'type'     => 'required|in:day_off,cancel_day_off',
            'remarks'  => 'nullable|string|max:500',
        ]);

        // Check for existing override on same date
        $existing = DayOff::where('employee_id', $employee->id)
            ->where('off_date', $validated['off_date'])
            ->first();

        if ($existing) {
            $existing->update($validated);
            $message = 'Day off override updated.';
        } else {
            $employee->dayOffs()->create($validated);
            $message = 'Day off override added.';
        }

        return redirect()->route('employees.edit', $employee)->with('success', $message);
    }

    public function deleteDayOff(Employee $employee, DayOff $dayoff)
    {
        $this->guardFeature('day_off_overrides');
        if ($dayoff->employee_id !== $employee->id) abort(403);
        $dayoff->delete();
        return redirect()->route('employees.edit', $employee)->with('success', 'Day off override removed.');
    }

    /* ── Cash Advances ── */

    public function addCashAdvance(Request $request, Employee $employee)
    {
        $this->guardFeature('cash_advance');
        $validated = $request->validate([
            'amount'               => 'required|numeric|min:1|max:999999.99',
            'deduction_per_cutoff' => 'required|numeric|min:0|max:999999.99',
            'date_granted'         => 'required|date',
            'effective_from'       => 'required|date',
            'effective_until'      => 'nullable|date|after_or_equal:effective_from',
            'remarks'              => 'nullable|string|max:500',
        ]);

        $validated['remaining_balance'] = $validated['amount'];
        $validated['status'] = 'active';

        $employee->cashAdvances()->create($validated);

        return redirect()->route('employees.edit', $employee)
            ->with('success', 'Cash advance added.');
    }

    public function deleteCashAdvance(Employee $employee, CashAdvance $cashadvance)
    {
        $this->guardFeature('cash_advance');
        if ($cashadvance->employee_id !== $employee->id) abort(403);
        $cashadvance->delete();
        return redirect()->route('employees.edit', $employee)->with('success', 'Cash advance removed.');
    }

    /* ── Inline Update (AJAX) ── */

    public function inlineUpdate(Request $request, Employee $employee)
    {
        $validated = $request->validate([
            'field' => 'required|in:actual_name',
            'value' => 'nullable|string|max:255',
        ]);

        $employee->update([$validated['field'] => $validated['value'] ?: null]);

        return response()->json([
            'success' => true,
            'display_name' => $employee->display_name,
        ]);
    }

    /* ── Bulk Operations ── */

    public function bulkAction(Request $request)
    {
        $validated = $request->validate([
            'employee_ids'   => 'required|array|min:1',
            'employee_ids.*' => 'exists:employees,id',
            'action'         => 'required|string',
        ]);

        $employeeIds = $validated['employee_ids'];
        $action = $validated['action'];
        $count = count($employeeIds);

        switch ($action) {
            case 'assign_shift':
                $request->validate([
                    'shift_id'        => 'required|exists:shifts,id',
                    'effective_date'  => 'required|date',
                    'effective_until' => 'nullable|date|after_or_equal:effective_date',
                ]);
                foreach ($employeeIds as $id) {
                    EmployeeShiftAssignment::create([
                        'employee_id'    => $id,
                        'shift_id'       => $request->shift_id,
                        'effective_date'  => $request->effective_date,
                        'effective_until' => $request->effective_until,
                    ]);
                }
                $msg = "Shift assigned to {$count} employees.";
                break;

            case 'change_department':
                $request->validate(['department_id' => 'required|exists:departments,id']);
                Employee::whereIn('id', $employeeIds)->update(['department_id' => $request->department_id]);
                $msg = "{$count} employees moved to new department.";
                break;

            case 'change_status':
                $request->validate([
                    'employment_status_id' => 'required|exists:employment_statuses,id',
                    'effective_from'       => 'required|date',
                    'effective_until'      => 'nullable|date|after_or_equal:effective_from',
                ]);
                foreach ($employeeIds as $id) {
                    EmployeeStatusHistory::create([
                        'employee_id'          => $id,
                        'employment_status_id' => $request->employment_status_id,
                        'effective_from'       => $request->effective_from,
                        'effective_until'      => $request->effective_until,
                    ]);
                }
                $msg = "Employment status set for {$count} employees.";
                break;

            case 'set_rest_day':
                $request->validate([
                    'day_of_week'     => 'required|integer|between:0,6',
                    'effective_from'  => 'required|date',
                    'effective_until' => 'nullable|date|after_or_equal:effective_from',
                ]);
                foreach ($employeeIds as $id) {
                    RestDayPattern::create([
                        'employee_id'    => $id,
                        'day_of_week'    => $request->day_of_week,
                        'effective_from'  => $request->effective_from,
                        'effective_until' => $request->effective_until,
                    ]);
                }
                $msg = "Rest day pattern set for {$count} employees.";
                break;

            case 'add_benefit':
                $request->validate([
                    'benefit_type_id' => 'required|exists:benefit_types,id',
                    'amount'          => 'required|numeric|min:0',
                    'effective_from'  => 'required|date',
                    'effective_until' => 'nullable|date|after_or_equal:effective_from',
                ]);
                foreach ($employeeIds as $id) {
                    EmployeeBenefit::create([
                        'employee_id'    => $id,
                        'benefit_type_id' => $request->benefit_type_id,
                        'is_eligible'    => true,
                        'amount'         => $request->amount,
                        'effective_from'  => $request->effective_from,
                        'effective_until' => $request->effective_until,
                    ]);
                }
                $msg = "Benefit added to {$count} employees.";
                break;

            case 'set_daily_rate':
                $request->validate([
                    'daily_rate'      => 'required|numeric|min:0',
                    'effective_date'  => 'required|date',
                    'effective_until' => 'nullable|date|after_or_equal:effective_date',
                ]);
                foreach ($employeeIds as $id) {
                    EmployeeRate::create([
                        'employee_id'    => $id,
                        'daily_rate'     => $request->daily_rate,
                        'effective_date'  => $request->effective_date,
                        'effective_until' => $request->effective_until,
                    ]);
                }
                $msg = "Daily rate set for {$count} employees.";
                break;

            case 'set_schedule_mode':
                $request->validate(['schedule_mode' => 'required|in:department,manual']);
                Employee::whereIn('id', $employeeIds)->update(['schedule_mode' => $request->schedule_mode]);
                $msg = "{$count} employees set to {$request->schedule_mode} mode.";
                break;

            case 'set_night_diff':
                $request->validate(['night_differential_eligible' => 'required|boolean']);
                Employee::whereIn('id', $employeeIds)->update(['night_differential_eligible' => $request->night_differential_eligible]);
                $status = $request->night_differential_eligible ? 'eligible' : 'not eligible';
                $msg = "{$count} employees set to {$status} for night differential.";
                break;

            case 'activate':
                Employee::whereIn('id', $employeeIds)->update(['status' => 'active']);
                $msg = "{$count} employees activated.";
                break;

            case 'deactivate':
                Employee::whereIn('id', $employeeIds)->update(['status' => 'inactive']);
                $msg = "{$count} employees deactivated.";
                break;

            default:
                return redirect()->route('employees.index')->with('error', 'Unknown bulk action.');
        }

        return redirect()->route('employees.index')->with('success', $msg);
    }

    /* ── Update Methods (Edit existing records) ── */

    public function updateStatus(Request $request, Employee $employee, EmployeeStatusHistory $status)
    {
        $this->guardFeature('employment_status');
        if ($status->employee_id !== $employee->id) abort(403);

        $validated = $request->validate([
            'employment_status_id' => 'required|exists:employment_statuses,id',
            'effective_from'       => 'required|date',
            'effective_until'      => 'nullable|date|after_or_equal:effective_from',
            'remarks'              => 'nullable|string|max:500',
        ]);

        $status->update($validated);

        return redirect()->route('employees.edit', $employee)
            ->with('success', 'Employment status updated.');
    }

    public function updateShift(Request $request, Employee $employee, EmployeeShiftAssignment $assignment)
    {
        $this->guardFeature('shift_assignments');
        if ($assignment->employee_id !== $employee->id) abort(403);

        $validated = $request->validate([
            'shift_id'        => 'required|exists:shifts,id',
            'effective_date'  => 'required|date',
            'effective_until' => 'nullable|date|after_or_equal:effective_date',
            'remarks'         => 'nullable|string|max:500',
        ]);

        // Check for overlapping assignments (exclude current)
        $overlap = $this->checkShiftOverlap($employee->id, $validated['effective_date'], $validated['effective_until'] ?? null, $assignment->id);
        if ($overlap) {
            return redirect()->route('employees.edit', $employee)
                ->with('error', "Shift assignment overlaps with existing assignment (effective {$overlap->effective_date->format('M d, Y')}).");
        }

        $assignment->update($validated);

        return redirect()->route('employees.edit', $employee)
            ->with('success', 'Shift assignment updated.');
    }

    public function updateRate(Request $request, Employee $employee, EmployeeRate $rate)
    {
        $this->guardFeature('daily_rates');
        if ($rate->employee_id !== $employee->id) abort(403);

        $validated = $request->validate([
            'daily_rate'      => 'required|numeric|min:0|max:999999.99',
            'effective_date'  => 'required|date',
            'effective_until' => 'nullable|date|after_or_equal:effective_date',
            'remarks'         => 'nullable|string|max:500',
        ]);

        // Check for overlapping rates (exclude current)
        $overlap = $this->checkRateOverlap($employee->id, $validated['effective_date'], $validated['effective_until'] ?? null, $rate->id);
        if ($overlap) {
            return redirect()->route('employees.edit', $employee)
                ->with('error', "Rate overlaps with existing rate (effective {$overlap->effective_date->format('M d, Y')}).");
        }

        $rate->update($validated);

        return redirect()->route('employees.edit', $employee)
            ->with('success', 'Daily rate updated.');
    }

    public function updateBenefit(Request $request, Employee $employee, EmployeeBenefit $benefit)
    {
        $this->guardFeature('benefits_deductions');
        if ($benefit->employee_id !== $employee->id) abort(403);

        $validated = $request->validate([
            'benefit_type_id' => 'required|exists:benefit_types,id',
            'amount'          => 'required|numeric|min:0|max:999999.99',
            'effective_from'  => 'required|date',
            'effective_until' => 'nullable|date|after_or_equal:effective_from',
            'remarks'         => 'nullable|string|max:500',
        ]);

        $benefit->update($validated);

        return redirect()->route('employees.edit', $employee)
            ->with('success', 'Benefit/deduction updated.');
    }

    public function updateRestDay(Request $request, Employee $employee, RestDayPattern $restday)
    {
        $this->guardFeature('rest_day_pattern');
        if ($restday->employee_id !== $employee->id) abort(403);

        $validated = $request->validate([
            'day_of_week'     => 'required|integer|between:0,6',
            'effective_from'  => 'required|date',
            'effective_until' => 'nullable|date|after_or_equal:effective_from',
            'remarks'         => 'nullable|string|max:500',
        ]);

        $restday->update($validated);

        return redirect()->route('employees.edit', $employee)
            ->with('success', 'Rest day pattern updated.');
    }

    public function updateDayOff(Request $request, Employee $employee, DayOff $dayoff)
    {
        $this->guardFeature('day_off_overrides');
        if ($dayoff->employee_id !== $employee->id) abort(403);

        $validated = $request->validate([
            'off_date' => 'required|date',
            'type'     => 'required|in:day_off,cancel_day_off',
            'remarks'  => 'nullable|string|max:500',
        ]);

        $dayoff->update($validated);

        return redirect()->route('employees.edit', $employee)
            ->with('success', 'Day off override updated.');
    }

    public function updateCashAdvance(Request $request, Employee $employee, CashAdvance $cashadvance)
    {
        $this->guardFeature('cash_advance');
        if ($cashadvance->employee_id !== $employee->id) abort(403);

        $validated = $request->validate([
            'amount'               => 'required|numeric|min:1|max:999999.99',
            'deduction_per_cutoff' => 'required|numeric|min:0|max:999999.99',
            'date_granted'         => 'required|date',
            'effective_from'       => 'required|date',
            'effective_until'      => 'nullable|date|after_or_equal:effective_from',
            'remarks'              => 'nullable|string|max:500',
            'status'               => 'required|in:active,paid,cancelled',
        ]);

        $cashadvance->update($validated);

        return redirect()->route('employees.edit', $employee)
            ->with('success', 'Cash advance updated.');
    }

    /* ── Active/Inactive Status ── */

    public function addActiveStatus(Request $request, Employee $employee)
    {
        $validated = $request->validate([
            'status'          => 'required|in:active,inactive',
            'effective_from'  => 'required|date',
            'effective_until' => 'nullable|date|after_or_equal:effective_from',
            'remarks'         => 'nullable|string|max:500',
        ]);

        $employee->activeStatuses()->create($validated);
        EmployeeActiveStatus::syncEmployeeStatus($employee->id);

        return redirect()->route('employees.edit', $employee)
            ->with('success', 'Active/Inactive status added.');
    }

    public function updateActiveStatus(Request $request, Employee $employee, EmployeeActiveStatus $activestatus)
    {
        if ($activestatus->employee_id !== $employee->id) abort(403);

        $validated = $request->validate([
            'status'          => 'required|in:active,inactive',
            'effective_from'  => 'required|date',
            'effective_until' => 'nullable|date|after_or_equal:effective_from',
            'remarks'         => 'nullable|string|max:500',
        ]);

        $activestatus->update($validated);
        EmployeeActiveStatus::syncEmployeeStatus($employee->id);

        return redirect()->route('employees.edit', $employee)
            ->with('success', 'Active/Inactive status updated.');
    }

    public function deleteActiveStatus(Employee $employee, EmployeeActiveStatus $activestatus)
    {
        if ($activestatus->employee_id !== $employee->id) abort(403);
        $activestatus->delete();
        EmployeeActiveStatus::syncEmployeeStatus($employee->id);

        return redirect()->route('employees.edit', $employee)
            ->with('success', 'Active/Inactive status removed.');
    }

    /* ── Permission Guard ── */

    protected function guardFeature(string $featureKey): void
    {
        $role = Auth::user()->role;
        if (!FeaturePermission::canEdit($role, $featureKey)) {
            abort(403, 'You do not have permission to edit this section.');
        }
    }

    /* ── Overlap Validation Helpers ── */

    protected function checkShiftOverlap(int $employeeId, string $start, ?string $end, ?int $excludeId = null)
    {
        $query = EmployeeShiftAssignment::where('employee_id', $employeeId);
        if ($excludeId) $query->where('id', '!=', $excludeId);

        return $query->where(function ($q) use ($start, $end) {
            $q->where(function ($q2) use ($start, $end) {
                // New record overlaps with existing
                $q2->where('effective_date', '<=', $end ?? '9999-12-31')
                   ->where(function ($q3) use ($start) {
                       $q3->whereNull('effective_until')
                          ->orWhere('effective_until', '>=', $start);
                   });
            });
        })->first();
    }

    protected function checkRateOverlap(int $employeeId, string $start, ?string $end, ?int $excludeId = null)
    {
        $query = EmployeeRate::where('employee_id', $employeeId);
        if ($excludeId) $query->where('id', '!=', $excludeId);

        return $query->where(function ($q) use ($start, $end) {
            $q->where(function ($q2) use ($start, $end) {
                $q2->where('effective_date', '<=', $end ?? '9999-12-31')
                   ->where(function ($q3) use ($start) {
                       $q3->whereNull('effective_until')
                          ->orWhere('effective_until', '>=', $start);
                   });
            });
        })->first();
    }
}
