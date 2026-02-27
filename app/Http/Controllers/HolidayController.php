<?php

namespace App\Http\Controllers;

use App\Models\Holiday;
use Carbon\Carbon;
use Illuminate\Http\Request;

class HolidayController extends Controller
{
    /**
     * Show the holiday calendar page.
     */
    public function index(Request $request)
    {
        $year = $request->input('year', now()->year);

        $holidays = Holiday::whereYear('date', $year)
            ->orderBy('date')
            ->get();

        // Also include recurring holidays that don't have an entry for this year
        $recurringHolidays = Holiday::where('recurring', true)
            ->whereYear('date', '!=', $year)
            ->get()
            ->filter(function ($rh) use ($holidays, $year) {
                // Check if there's already a holiday on this month-day for the target year
                $targetDate = Carbon::create($year, $rh->date->month, $rh->date->day);
                return !$holidays->contains(fn($h) => $h->date->toDateString() === $targetDate->toDateString());
            })
            ->map(function ($rh) use ($year) {
                $virtual = clone $rh;
                $virtual->date = Carbon::create($year, $rh->date->month, $rh->date->day);
                $virtual->is_virtual = true;
                return $virtual;
            });

        $allHolidays = $holidays->merge($recurringHolidays)->sortBy(fn($h) => $h->date->toDateString())->values();

        $years = range(now()->year - 1, now()->year + 2);

        return view('settings.holidays', compact('allHolidays', 'year', 'years'));
    }

    /**
     * Store a new holiday.
     */
    public function store(Request $request)
    {
        $request->validate([
            'date' => 'required|date',
            'name' => 'required|string|max:255',
            'type' => 'required|in:regular,special',
            'recurring' => 'nullable|boolean',
            'remarks' => 'nullable|string|max:1000',
        ]);

        // Check if holiday already exists on this date
        $existing = Holiday::whereDate('date', $request->date)->first();
        if ($existing) {
            return back()->with('error', 'A holiday already exists on this date: ' . $existing->name);
        }

        Holiday::create([
            'date' => $request->date,
            'name' => $request->name,
            'type' => $request->type,
            'recurring' => $request->boolean('recurring'),
            'remarks' => $request->remarks,
        ]);

        return back()->with('success', 'Holiday added successfully.');
    }

    /**
     * Update an existing holiday.
     */
    public function update(Request $request, Holiday $holiday)
    {
        $request->validate([
            'date' => 'required|date',
            'name' => 'required|string|max:255',
            'type' => 'required|in:regular,special',
            'recurring' => 'nullable|boolean',
            'remarks' => 'nullable|string|max:1000',
        ]);

        // Check if another holiday exists on the new date
        $existing = Holiday::whereDate('date', $request->date)
            ->where('id', '!=', $holiday->id)
            ->first();
        if ($existing) {
            return back()->with('error', 'Another holiday already exists on this date: ' . $existing->name);
        }

        $holiday->update([
            'date' => $request->date,
            'name' => $request->name,
            'type' => $request->type,
            'recurring' => $request->boolean('recurring'),
            'remarks' => $request->remarks,
        ]);

        return back()->with('success', 'Holiday updated successfully.');
    }

    /**
     * Delete a holiday.
     */
    public function destroy(Holiday $holiday)
    {
        $holiday->delete();
        return back()->with('success', 'Holiday deleted successfully.');
    }
}
