<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeRate;
use App\Models\EmployeeShiftAssignment;
use App\Models\Shift;
use Illuminate\Http\Request;

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

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('department_id')) {
            $query->where('department_id', $request->department_id);
        }

        $employees = $query->orderBy('full_name')->paginate(25);
        $shifts = Shift::all();
        $departments = Department::orderBy('name')->get();

        // Attach current shift and rate for display
        $employees->getCollection()->transform(function ($emp) {
            $emp->current_shift = $emp->getCurrentShift();
            $emp->current_rate = $emp->getCurrentRate();
            return $emp;
        });

        return view('employees.index', compact('employees', 'shifts', 'departments'));
    }

    public function edit(Employee $employee)
    {
        $shifts = Shift::orderBy('name')->get();
        $departments = Department::orderBy('name')->get();
        $employee->load(['shiftAssignments.shift', 'employeeRates', 'department']);

        return view('employees.edit', compact('employee', 'shifts', 'departments'));
    }

    public function update(Request $request, Employee $employee)
    {
        $validated = $request->validate([
            'actual_name'      => 'nullable|string|max:255',
            'status'           => 'required|in:active,inactive',
            'default_shift_id' => 'nullable|exists:shifts,id',
            'department_id'    => 'nullable|exists:departments,id',
            'schedule_mode'    => 'required|in:department,manual',
        ]);

        // If employee has no department, force manual mode
        if (empty($validated['department_id'])) {
            $validated['schedule_mode'] = Employee::MODE_MANUAL;
        }

        $employee->update($validated);

        return redirect()->route('employees.edit', $employee)
            ->with('success', "Employee {$employee->display_name} updated successfully.");
    }

    /**
     * Add a new shift assignment for an employee.
     * When manually adding, set schedule_mode to 'manual'.
     */
    public function assignShift(Request $request, Employee $employee)
    {
        $validated = $request->validate([
            'shift_id'       => 'required|exists:shifts,id',
            'effective_date' => 'required|date',
            'remarks'        => 'nullable|string|max:500',
        ]);

        // Check for duplicate effective_date
        $existing = EmployeeShiftAssignment::where('employee_id', $employee->id)
            ->where('effective_date', $validated['effective_date'])
            ->first();

        if ($existing) {
            // Update existing assignment for that date
            $existing->update($validated);
            $message = "Shift assignment updated for {$validated['effective_date']}.";
        } else {
            $employee->shiftAssignments()->create($validated);
            $message = "Shift assignment added effective {$validated['effective_date']}.";
        }

        // Set to manual mode since HR is manually setting shift
        if ($employee->isDepartmentMode()) {
            $employee->update(['schedule_mode' => Employee::MODE_MANUAL]);
            $message .= ' Schedule mode set to Manual.';
        }

        return redirect()->route('employees.edit', $employee)
            ->with('success', $message);
    }

    /**
     * Delete a shift assignment.
     */
    public function deleteShiftAssignment(Employee $employee, EmployeeShiftAssignment $assignment)
    {
        if ($assignment->employee_id !== $employee->id) {
            abort(403);
        }

        $assignment->delete();

        return redirect()->route('employees.edit', $employee)
            ->with('success', 'Shift assignment removed.');
    }

    /**
     * Add a new rate for an employee.
     */
    public function addRate(Request $request, Employee $employee)
    {
        $validated = $request->validate([
            'daily_rate'     => 'required|numeric|min:0|max:999999.99',
            'effective_date' => 'required|date',
            'remarks'        => 'nullable|string|max:500',
        ]);

        // Check for duplicate effective_date
        $existing = EmployeeRate::where('employee_id', $employee->id)
            ->where('effective_date', $validated['effective_date'])
            ->first();

        if ($existing) {
            $existing->update($validated);
            $message = "Rate updated for {$validated['effective_date']}.";
        } else {
            $employee->employeeRates()->create($validated);
            $message = "Rate added effective {$validated['effective_date']}.";
        }

        return redirect()->route('employees.edit', $employee)
            ->with('success', $message);
    }

    /**
     * Inline update a single field (AJAX).
     */
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

    /**
     * Delete a rate entry.
     */
    public function deleteRate(Employee $employee, EmployeeRate $rate)
    {
        if ($rate->employee_id !== $employee->id) {
            abort(403);
        }

        $rate->delete();

        return redirect()->route('employees.edit', $employee)
            ->with('success', 'Rate entry removed.');
    }
}
