<?php

namespace App\Http\Controllers;

use App\Models\Department;
use Illuminate\Http\Request;

class DepartmentController extends Controller
{
    public function index()
    {
        $departments = Department::withCount('employees')
            ->orderBy('name')
            ->paginate(20);

        return view('departments.index', compact('departments'));
    }

    public function create()
    {
        return view('departments.form', ['department' => null]);
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

    public function edit(Department $department)
    {
        return view('departments.form', compact('department'));
    }

    public function update(Request $request, Department $department)
    {
        $request->validate([
            'name'        => 'required|string|max:100|unique:departments,name,' . $department->id,
            'description' => 'nullable|string|max:255',
        ]);

        $department->update($request->only('name', 'description'));

        return redirect()->route('departments.index')
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
}
