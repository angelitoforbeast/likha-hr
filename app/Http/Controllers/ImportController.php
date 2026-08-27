<?php

namespace App\Http\Controllers;

use App\Jobs\ParseZktecoImport;
use App\Models\AttendanceImportRun;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class ImportController extends Controller
{
    public function index()
    {
        // Role-based visibility:
        //  - CEO sees ALL import runs (from anyone).
        //  - Admin sees imports uploaded by admins/hr_staff (i.e., NOT CEO's imports).
        //  - HR Staff sees imports uploaded by hr_staff only (i.e., NOT CEO's or admin's).
        // Rationale: prevent lower roles from seeing the CEO's private upload history.
        $user = Auth::user();
        $query = AttendanceImportRun::with('uploader')->orderByDesc('created_at');

        if ($user && $user->role !== 'ceo') {
            $allowedRoles = $user->role === 'admin'
                ? ['admin', 'hr_staff']
                : ['hr_staff']; // default fallback for hr_staff / others
            $query->whereHas('uploader', function ($q) use ($allowedRoles) {
                $q->whereIn('role', $allowedRoles);
            });
        }

        $runs = $query->paginate(15);

        return view('import.index', compact('runs'));
    }

    public function upload(Request $request)
    {
        $request->validate([
            'user_dat'   => 'required|file|max:51200',  // 50MB max
            'attlog_dat' => 'required|file|max:51200',
        ], [
            'user_dat.required'   => 'The user.dat file is required.',
            'attlog_dat.required' => 'The attlog.dat file is required.',
        ]);

        // Create import run record
        $run = AttendanceImportRun::create([
            'uploaded_by' => Auth::id(),
            'status'      => 'queued',
        ]);

        // Store files
        $dir = "imports/{$run->id}";
        Storage::disk('local')->putFileAs($dir, $request->file('user_dat'), 'user.dat');
        Storage::disk('local')->putFileAs($dir, $request->file('attlog_dat'), 'attlog.dat');

        // Dispatch parsing job
        ParseZktecoImport::dispatch($run->id);

        return redirect()->route('import.index')
            ->with('success', "Import #{$run->id} queued for processing.");
    }

    public function status(AttendanceImportRun $run)
    {
        return response()->json([
            'id'         => $run->id,
            'status'     => $run->status,
            'stats_json' => $run->stats_json,
            'created_at' => $run->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $run->updated_at->format('Y-m-d H:i:s'),
        ]);
    }
}
