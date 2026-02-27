<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\DepartmentShiftAssignment;
use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use App\Models\Shift;
use Illuminate\Http\Request;

class DepartmentController extends Controller
{
    public function index()
    {
        $departments = Department::withCount('employees')
            ->orderBy('name')
            ->paginate(20);

        // Attach current shift for each department
        $departments->getCollection()->transform(function ($dept) {
            $dept->current_shift = $dept->getCurrentShift();
            return $dept;
        });

        return view('departments.index', compact('departments'));
    }

    public function create()
    {
        $shifts = Shift::orderBy('name')->get();
        return view('departments.form', ['department' => null, 'shifts' => $shifts]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name'        => 'required|string|max:100|unique:departments,name',
            'description' => 'nullable|string|max:255',
        ]);

        Department::create($request->only('name', 'description'));

        return redirect()->route('departments.index')
            ->with('success', 'Department created successfully.');
    }

    /**
     * Show department detail page with employees, shift assignments.
     */
    public function show(Department $department)
    {
        $department->load(['employees.defaultShift', 'shiftAssignments.shift']);
        $shifts = Shift::orderBy('name')->get();

        // Get employees NOT in this department (for add dropdown)
        $availableEmployees = Employee::where(function ($q) use ($department) {
                $q->whereNull('department_id')
                  ->orWhere('department_id', '!=', $department->id);
            })
            ->orderBy('full_name')
            ->get();

        $currentShift = $department->getCurrentShift();

        return view('departments.show', compact('department', 'shifts', 'availableEmployees', 'currentShift'));
    }

    public function edit(Department $department)
    {
        $shifts = Shift::orderBy('name')->get();
        return view('departments.form', compact('department', 'shifts'));
    }

    public function update(Request $request, Department $department)
    {
        $request->validate([
            'name'        => 'required|string|max:100|unique:departments,name,' . $department->id,
            'description' => 'nullable|string|max:255',
        ]);

        $department->update($request->only('name', 'description'));

        return redirect()->route('departments.show', $department)
            ->with('success', 'Department updated successfully.');
    }

    public function destroy(Department $department)
    {
        if ($department->employees()->count() > 0) {
            return back()->with('error', 'Cannot delete department with assigned employees. Reassign them first.');
        }

        $department->delete();

        return redirect()->route('departments.index')
            ->with('success', 'Department deleted successfully.');
    }

    /**
     * Assign a shift to the department with effective date.
     * Auto-replicate to employees in 'department' schedule mode.
     */
    public function assignShift(Request $request, Department $department)
    {
        $validated = $request->validate([
            'shift_id'       => 'required|exists:shifts,id',
            'effective_date' => 'required|date',
            'remarks'        => 'nullable|string|max:500',
        ]);

        // Check for existing assignment on same effective date
        $existing = DepartmentShiftAssignment::where('department_id', $department->id)
            ->where('effective_date', $validated['effective_date'])
            ->first();

        if ($existing) {
            $existing->update($validated);
        } else {
            $department->shiftAssignments()->create($validated);
        }

        // Auto-replicate to employees in 'department' mode
        $this->replicateShiftToEmployees($department, $validated['shift_id'], $validated['effective_date']);

        return redirect()->route('departments.show', $department)
            ->with('success', 'Department shift assigned and replicated to employees in Department mode.');
    }

    /**
     * Delete a department shift assignment.
     */
    public function deleteShiftAssignment(Department $department, DepartmentShiftAssignment $assignment)
    {
        if ($assignment->department_id !== $department->id) {
            abort(403);
        }

        $assignment->delete();

        return redirect()->route('departments.show', $department)
            ->with('success', 'Department shift assignment removed.');
    }

    /**
     * Add an existing employee to this department.
     */
    public function addEmployee(Request $request, Department $department)
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
        ]);

        $employee = Employee::findOrFail($validated['employee_id']);
        $employee->department_id = $department->id;
        $employee->schedule_mode = Employee::MODE_DEPARTMENT;
        $employee->save();

        // Auto-assign current department shift to this employee
        $currentDeptShift = $department->getCurrentShiftAssignment();
        if ($currentDeptShift) {
            // Create shift assignment for the employee if not already exists for this date
            $existing = EmployeeShiftAssignment::where('employee_id', $employee->id)
                ->where('effective_date', $currentDeptShift->effective_date)
                ->first();

            if (!$existing) {
                EmployeeShiftAssignment::create([
                    'employee_id'    => $employee->id,
                    'shift_id'       => $currentDeptShift->shift_id,
                    'effective_date' => $currentDeptShift->effective_date,
                    'remarks'        => 'Auto-assigned from department: ' . $department->name,
                ]);
            }
        }

        return redirect()->route('departments.show', $department)
            ->with('success', "Employee \"{$employee->display_name}\" added to department.");
    }

    /**
     * Remove an employee from this department.
     */
    public function removeEmployee(Request $request, Department $department, Employee $employee)
    {
        if ($employee->department_id !== $department->id) {
            abort(403);
        }

        $employee->department_id = null;
        $employee->schedule_mode = Employee::MODE_MANUAL;
        $employee->save();

        return redirect()->route('departments.show', $department)
            ->with('success', "Employee \"{$employee->display_name}\" removed from department.");
    }

    /**
     * Replicate a shift assignment to all employees in 'department' mode.
     */
    protected function replicateShiftToEmployees(Department $department, int $shiftId, string $effectiveDate): void
    {
        $employees = $department->employees()
            ->where('schedule_mode', Employee::MODE_DEPARTMENT)
            ->get();

        foreach ($employees as $employee) {
            // Check for existing assignment on same effective date
            $existing = EmployeeShiftAssignment::where('employee_id', $employee->id)
                ->where('effective_date', $effectiveDate)
                ->first();

            if ($existing) {
                $existing->update([
                    'shift_id' => $shiftId,
                    'remarks'  => 'Auto-updated from department: ' . $department->name,
                ]);
            } else {
                EmployeeShiftAssignment::create([
                    'employee_id'    => $employee->id,
                    'shift_id'       => $shiftId,
                    'effective_date' => $effectiveDate,
                    'remarks'        => 'Auto-assigned from department: ' . $department->name,
                ]);
            }
        }
    }
}
