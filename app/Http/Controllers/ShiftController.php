<?php

namespace App\Http\Controllers;

use App\Models\Shift;
use Illuminate\Http\Request;

class ShiftController extends Controller
{
    public function index()
    {
        $shifts = Shift::withCount('employees')->orderBy('name')->get();
        return view('shifts.index', compact('shifts'));
    }

    public function create()
    {
        return view('shifts.form', ['shift' => null]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'                                  => 'required|string|max:255|unique:shifts,name',
            'start_time'                            => 'required|date_format:H:i',
            'end_time'                              => 'required|date_format:H:i',
            'lunch_start'                           => 'required|date_format:H:i',
            'lunch_end'                             => 'required|date_format:H:i',
            'required_work_minutes'                 => 'required|integer|min:1|max:1440',
            'grace_in_minutes'                      => 'required|integer|min:0|max:120',
            'grace_out_minutes'                     => 'required|integer|min:0|max:120',
            'lunch_inference_window_before_minutes' => 'required|integer|min:0|max:120',
            'lunch_inference_window_after_minutes'  => 'required|integer|min:0|max:120',
        ]);

        Shift::create($validated);

        return redirect()->route('shifts.index')
            ->with('success', "Shift \"{$validated['name']}\" created successfully.");
    }

    public function edit(Shift $shift)
    {
        return view('shifts.form', compact('shift'));
    }

    public function update(Request $request, Shift $shift)
    {
        $validated = $request->validate([
            'name'                                  => 'required|string|max:255|unique:shifts,name,' . $shift->id,
            'start_time'                            => 'required|date_format:H:i',
            'end_time'                              => 'required|date_format:H:i',
            'lunch_start'                           => 'required|date_format:H:i',
            'lunch_end'                             => 'required|date_format:H:i',
            'required_work_minutes'                 => 'required|integer|min:1|max:1440',
            'grace_in_minutes'                      => 'required|integer|min:0|max:120',
            'grace_out_minutes'                     => 'required|integer|min:0|max:120',
            'lunch_inference_window_before_minutes' => 'required|integer|min:0|max:120',
            'lunch_inference_window_after_minutes'  => 'required|integer|min:0|max:120',
        ]);

        $shift->update($validated);

        return redirect()->route('shifts.index')
            ->with('success', "Shift \"{$shift->name}\" updated successfully.");
    }

    public function destroy(Shift $shift)
    {
        $employeeCount = $shift->employees()->count();

        if ($employeeCount > 0) {
            return redirect()->route('shifts.index')
                ->with('error', "Cannot delete \"{$shift->name}\" — {$employeeCount} employee(s) are still assigned to this shift.");
        }

        $name = $shift->name;
        $shift->delete();

        return redirect()->route('shifts.index')
            ->with('success', "Shift \"{$name}\" deleted successfully.");
    }
}
