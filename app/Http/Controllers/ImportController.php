<?php

namespace App\Http\Controllers;

use App\Jobs\ParseZktecoImport;
use App\Models\AttendanceImportRun;
use App\Models\Employee;
use App\Services\ZktecoParserService;
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

    /**
     * Show the "Check user.dat" upload form.
     * Read-only preview — nothing gets written to the database.
     */
    public function checkForm()
    {
        return view('import.check');
    }

    /**
     * Parse an uploaded user.dat WITHOUT saving anything, then compare each
     * entry against the current employees table so CEO/Admin can verify a
     * biometric transfer is mapped correctly before running a real import.
     *
     * Categorises every user as:
     *   - match_exact     — zkteco_id + full_name both agree with an existing row
     *   - match_id_only   — zkteco_id exists but the name in the file differs
     *   - name_conflict   — same person's full_name exists on a DIFFERENT zkteco_id
     *   - new             — zkteco_id has no existing employee
     */
    public function check(Request $request, ZktecoParserService $parser)
    {
        $request->validate([
            'user_dat' => 'required|file|max:51200',
        ], [
            'user_dat.required' => 'A user.dat file is required.',
        ]);

        $tempPath = $request->file('user_dat')->getRealPath();
        $rows = $parser->extractUsersFromDat($tempPath);

        // Snapshot current employees so we can compare in-memory.
        $byZk   = Employee::pluck('id', 'zkteco_id')->toArray();      // zkteco_id => employee_id
        $emps   = Employee::select('id', 'zkteco_id', 'full_name', 'actual_name')->get()->keyBy('id');
        $byName = [];
        foreach ($emps as $e) {
            $key = mb_strtolower(trim((string) $e->full_name));
            if ($key !== '') $byName[$key][] = $e->id;
            $actualKey = mb_strtolower(trim((string) $e->actual_name));
            if ($actualKey !== '' && $actualKey !== $key) $byName[$actualKey][] = $e->id;
        }

        $results = [];
        $counts  = ['match_exact' => 0, 'match_id_only' => 0, 'name_conflict' => 0, 'new' => 0];
        foreach ($rows as $row) {
            $zk     = (string) $row['zkteco_id'];
            $name   = (string) $row['full_name'];
            $lookup = mb_strtolower(trim($name));

            if (isset($byZk[$zk])) {
                $existing = $emps[$byZk[$zk]];
                $sysName  = trim((string) ($existing->actual_name ?: $existing->full_name));
                if (mb_strtolower($sysName) === $lookup) {
                    $category = 'match_exact';
                } else {
                    $category = 'match_id_only';
                }
                $results[] = [
                    'zkteco_id'    => $zk,
                    'file_name'    => $name,
                    'category'     => $category,
                    'system_id'    => $existing->id,
                    'system_name'  => $sysName,
                    'name_matches_other' => $byName[$lookup] ?? [],
                ];
            } else {
                // Not matching by ID — is the name known under a different ID?
                $nameMatches = $byName[$lookup] ?? [];
                $category    = !empty($nameMatches) ? 'name_conflict' : 'new';
                $results[] = [
                    'zkteco_id'   => $zk,
                    'file_name'   => $name,
                    'category'    => $category,
                    'system_id'   => null,
                    'system_name' => null,
                    'name_matches_other' => $nameMatches,
                ];
            }
            $counts[$category]++;
        }

        // Also flag employees in the system that were NOT present in the file
        // — these would appear missing after a full-device sync.
        $fileZkSet = array_flip(array_map(fn ($r) => (string) $r['zkteco_id'], $rows));
        $missing = [];
        foreach ($emps as $e) {
            $zk = (string) $e->zkteco_id;
            if ($zk === '') continue;
            if (!isset($fileZkSet[$zk])) {
                $missing[] = [
                    'zkteco_id'   => $zk,
                    'system_id'   => $e->id,
                    'system_name' => trim((string) ($e->actual_name ?: $e->full_name)),
                ];
            }
        }

        return view('import.check-result', [
            'results'  => $results,
            'counts'   => $counts,
            'missing'  => $missing,
            'fileName' => $request->file('user_dat')->getClientOriginalName(),
        ]);
    }
}
