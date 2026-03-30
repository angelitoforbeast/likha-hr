<?php

namespace App\Http\Controllers;

use App\Models\ManusEditLog;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ManusEditLogController extends Controller
{
    public function index(Request $request)
    {
        $dateFrom = $request->input('date_from');
        $dateTo   = $request->input('date_to');

        $query = ManusEditLog::orderBy('datetime', 'desc');

        if ($dateFrom) {
            $query->where('datetime', '>=', Carbon::parse($dateFrom)->startOfDay());
        }
        if ($dateTo) {
            $query->where('datetime', '<=', Carbon::parse($dateTo)->endOfDay());
        }

        $logs = $query->paginate(50)->appends($request->only(['date_from', 'date_to']));

        return view('manus-edit-logs.index', compact('logs', 'dateFrom', 'dateTo'));
    }
}
