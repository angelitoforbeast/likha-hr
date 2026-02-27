<?php

namespace App\Http\Controllers;

use App\Models\EmploymentStatus;
use Illuminate\Http\Request;

class EmploymentStatusController extends Controller
{
    public function index()
    {
        $statuses = EmploymentStatus::orderBy('sort_order')->get();
        return view('settings.employment-statuses', compact('statuses'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'  => 'required|string|max:100|unique:employment_statuses,name',
            'color' => 'nullable|string|max:20',
        ]);

        $maxSort = EmploymentStatus::max('sort_order') ?? 0;
        $validated['sort_order'] = $maxSort + 1;

        EmploymentStatus::create($validated);

        return redirect()->route('employment-statuses.index')
            ->with('success', 'Employment status added.');
    }

    public function destroy(EmploymentStatus $employmentStatus)
    {
        // Check if any employees use this status
        if ($employmentStatus->statusHistories()->exists()) {
            return redirect()->route('employment-statuses.index')
                ->with('error', 'Cannot delete — this status is used by employees.');
        }

        $employmentStatus->delete();

        return redirect()->route('employment-statuses.index')
            ->with('success', 'Employment status removed.');
    }
}
