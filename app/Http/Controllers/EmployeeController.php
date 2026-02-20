<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Shift;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    public function index(Request $request)
    {
        $query = Employee::with('defaultShift');

        if ($request->filled('search')) {
            $query->where('full_name', 'like', '%' . $request->search . '%');
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $employees = $query->orderBy('full_name')->paginate(25);
        $shifts = Shift::all();

        return view('employees.index', compact('employees', 'shifts'));
    }

    public function edit(Employee $employee)
    {
        $shifts = Shift::all();
        return view('employees.edit', compact('employee', 'shifts'));
    }

    public function update(Request $request, Employee $employee)
    {
        $validated = $request->validate([
            'full_name'        => 'required|string|max:255',
            'status'           => 'required|in:active,inactive',
            'default_shift_id' => 'nullable|exists:shifts,id',
        ]);

        $employee->update($validated);

        return redirect()->route('employees.index')
            ->with('success', "Employee {$employee->full_name} updated successfully.");
    }
}
