<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\AttendanceImportRun;
use App\Models\PayrollRun;

class DashboardController extends Controller
{
    public function index()
    {
        $stats = [
            'total_employees' => Employee::where('status', 'active')->count(),
            'total_imports'   => AttendanceImportRun::count(),
            'total_payrolls'  => PayrollRun::count(),
        ];

        return view('dashboard.index', compact('stats'));
    }
}
