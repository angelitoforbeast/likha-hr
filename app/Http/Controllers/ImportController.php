<?php

namespace App\Http\Controllers;

use App\Jobs\ParseZktecoImport;
use App\Models\AttendanceImportRun;
use App\Models\Employee;
use App\Services\ZktecoParserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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

    /**
     * Upload attlog.dat and stage it for preview.
     *
     * The file is stored in a per-user temp folder; the preview page reads
     * it read-only and either commits it into a real import run or discards
     * it on cancel. user.dat is handled separately by the Sync Users page,
     * so a stable-employee run doesn't need it every time.
     */
    public function upload(Request $request)
    {
        $request->validate([
            'attlog_dat' => 'required|file|max:51200',
        ], [
            'attlog_dat.required' => 'The attlog.dat file is required.',
        ]);

        $this->discardTempAttlog(); // any prior preview belonging to this user

        $tempId = (string) Str::uuid();
        $dir    = "import_temp/{$tempId}";
        Storage::disk('local')->putFileAs($dir, $request->file('attlog_dat'), 'attlog.dat');

        session(['import_temp_attlog' => [
            'id'       => $tempId,
            'filename' => $request->file('attlog_dat')->getClientOriginalName(),
            'uploaded_by' => Auth::id(),
        ]]);

        return redirect()->route('import.preview');
    }

    /**
     * Show a 30-day presence grid for a staged attlog.dat.
     * Blocks the Proceed button when the file contains ZKTeco IDs that are
     * not mapped to any employee (must be resolved first, per Q5=B).
     */
    public function preview(Request $request, ZktecoParserService $parser)
    {
        $stage = session('import_temp_attlog');
        if (!$stage || ($stage['uploaded_by'] ?? null) !== Auth::id()) {
            return redirect()->route('import.index')->with('error', 'No staged attlog.dat to preview. Please upload again.');
        }

        $path = Storage::disk('local')->path("import_temp/{$stage['id']}/attlog.dat");
        if (!file_exists($path)) {
            session()->forget('import_temp_attlog');
            return redirect()->route('import.index')->with('error', 'Staged file expired. Please upload again.');
        }

        // Date range — user-adjustable, defaults to the last 30 days from today.
        $to   = $this->normaliseDate($request->query('date_to'),   now()->format('Y-m-d'));
        $from = $this->normaliseDate($request->query('date_from'), now()->subDays(29)->format('Y-m-d'));
        if ($from > $to) [$from, $to] = [$to, $from];
        $summary = $parser->extractAttlogSummary($path, $from, $to);

        // Map zkteco_id -> employee row (with department)
        $byZk = Employee::with('department')
            ->select('id', 'zkteco_id', 'full_name', 'actual_name', 'department_id')
            ->get()
            ->keyBy('zkteco_id');

        $knownRows   = [];
        $unknownRows = [];
        foreach ($summary['presence'] as $zk => $dateMap) {
            if (isset($byZk[$zk])) {
                $e = $byZk[$zk];
                $knownRows[] = [
                    'zkteco_id'  => $zk,
                    'employee_id' => $e->id,
                    'name'       => $e->actual_name ?: $e->full_name,
                    'department' => $e->department->name ?? '—',
                    'presence'   => $dateMap,
                    'punch_days' => count($dateMap),
                ];
            } else {
                $unknownRows[] = [
                    'zkteco_id'  => $zk,
                    'presence'   => $dateMap,
                    'punch_days' => count($dateMap),
                ];
            }
        }

        usort($knownRows, fn ($a, $b) => strcmp($a['department'] . $a['name'], $b['department'] . $b['name']));
        usort($unknownRows, fn ($a, $b) => strcmp($a['zkteco_id'], $b['zkteco_id']));

        return view('import.preview-attlog', [
            'stage'        => $stage,
            'dateFrom'     => $from,
            'dateTo'       => $to,
            'dates'        => $summary['dates'],
            'knownRows'    => $knownRows,
            'unknownRows'  => $unknownRows,
            'totalLines'   => $summary['total_lines'],
            'invalidLines' => $summary['invalid_lines'],
            'outOfRange'   => $summary['out_of_range'],
        ]);
    }

    /**
     * Commit the staged attlog.dat into a real import run and dispatch parsing.
     * Filters the file to:
     *   - only lines within the chosen date_from..date_to window, and
     *   - only ZKTeco IDs the user kept checked (include_zkteco[]).
     * Refuses when there are unmatched ZKTeco IDs (Q5=B).
     */
    public function previewCommit(Request $request, ZktecoParserService $parser)
    {
        $stage = session('import_temp_attlog');
        if (!$stage || ($stage['uploaded_by'] ?? null) !== Auth::id()) {
            return redirect()->route('import.index')->with('error', 'No staged attlog.dat to commit.');
        }

        $path = Storage::disk('local')->path("import_temp/{$stage['id']}/attlog.dat");
        if (!file_exists($path)) {
            session()->forget('import_temp_attlog');
            return redirect()->route('import.index')->with('error', 'Staged file expired. Please upload again.');
        }

        // Range must match what the preview showed. Fall back to defaults if missing.
        $to   = $this->normaliseDate($request->input('date_to'),   now()->format('Y-m-d'));
        $from = $this->normaliseDate($request->input('date_from'), now()->subDays(29)->format('Y-m-d'));
        if ($from > $to) [$from, $to] = [$to, $from];

        // Re-check for unknown IDs — a resolution could have happened between preview and commit.
        $summary = $parser->extractAttlogSummary($path, $from, $to);
        $knownZk = Employee::whereNotNull('zkteco_id')->pluck('zkteco_id')->flip();
        foreach (array_keys($summary['presence']) as $zk) {
            if (!isset($knownZk[$zk])) {
                return redirect()->route('import.preview')
                    ->with('error', 'One or more ZKTeco IDs are still unmatched. Resolve them before proceeding.');
            }
        }

        // Determine which zkteco_ids the user included. When the form doesn't
        // send any (rare), we default to all known IDs in the summary.
        $included = (array) $request->input('include_zkteco', []);
        $included = array_map('strval', $included);
        $includedSet = array_flip($included);
        if (empty($includedSet)) {
            // Nothing checked at all — refuse rather than silently importing zero.
            return redirect()->route('import.preview')
                ->with('error', 'No employees were selected. Check at least one row to import.');
        }

        // Create the real run FIRST so we can write the filtered file into imports/{run}.
        $run = AttendanceImportRun::create([
            'uploaded_by' => Auth::id(),
            'status'      => 'queued',
        ]);
        $destDir  = "imports/{$run->id}";
        Storage::disk('local')->makeDirectory($destDir);
        $destPath = Storage::disk('local')->path("{$destDir}/attlog.dat");

        // Filter: keep only lines within the date window whose zkteco_id is
        // in the included set. Writes filtered content to the run's folder.
        $fromTs = strtotime($from . ' 00:00:00');
        $toTs   = strtotime($to   . ' 23:59:59');
        $kept = 0;
        $dropped = 0;
        $src = fopen($path, 'r');
        $dst = fopen($destPath, 'w');
        if (!$src || !$dst) {
            if ($src) fclose($src);
            if ($dst) fclose($dst);
            $run->delete();
            return redirect()->route('import.preview')->with('error', 'Could not stage the filtered file. Please try again.');
        }
        while (($line = fgets($src)) !== false) {
            $trim = trim($line);
            if ($trim === '') continue;
            $cols = preg_split('/\t+/', $trim);
            if (count($cols) < 2) { $dropped++; continue; }
            $zk = trim($cols[0]);
            $ts = strtotime(trim($cols[1]));
            if ($ts === false || $ts < $fromTs || $ts > $toTs) { $dropped++; continue; }
            if (!isset($includedSet[$zk])) { $dropped++; continue; }
            fwrite($dst, $line);
            $kept++;
        }
        fclose($src);
        fclose($dst);

        // Discard the staged file — filtered copy is now under imports/{run}/.
        Storage::disk('local')->deleteDirectory("import_temp/{$stage['id']}");
        session()->forget('import_temp_attlog');

        // Record the filter metadata on the run for later reference.
        $run->update([
            'stats_json' => json_encode([
                'preview_filter' => [
                    'date_from'      => $from,
                    'date_to'        => $to,
                    'included_count' => count($includedSet),
                    'kept_lines'     => $kept,
                    'dropped_lines'  => $dropped,
                ],
            ]),
        ]);

        ParseZktecoImport::dispatch($run->id);

        return redirect()->route('import.index')
            ->with('success', "Import #{$run->id} queued. Kept {$kept} of {$kept + $dropped} lines after preview filter.");
    }

    /**
     * Coerce a request date value to Y-m-d or fall back to the provided default.
     */
    protected function normaliseDate($raw, string $default): string
    {
        if (!is_string($raw) || $raw === '') return $default;
        $ts = strtotime($raw);
        return $ts === false ? $default : date('Y-m-d', $ts);
    }

    public function previewCancel()
    {
        $this->discardTempAttlog();
        return redirect()->route('import.index')->with('success', 'Preview cancelled. Staged file discarded.');
    }

    /**
     * Delete the currently-staged attlog.dat (if any) for the acting user.
     */
    protected function discardTempAttlog(): void
    {
        $stage = session('import_temp_attlog');
        if ($stage && ($stage['uploaded_by'] ?? null) === Auth::id()) {
            Storage::disk('local')->deleteDirectory("import_temp/{$stage['id']}");
        }
        session()->forget('import_temp_attlog');
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

    /**
     * Show the Sync Users form (user.dat upload only).
     */
    public function syncUsersForm()
    {
        return view('import.sync-users');
    }

    /**
     * Stage the uploaded user.dat and redirect to preview.
     */
    public function syncUsersUpload(Request $request)
    {
        $request->validate([
            'user_dat' => 'required|file|max:51200',
        ], [
            'user_dat.required' => 'The user.dat file is required.',
        ]);

        $this->discardTempUserDat();

        $tempId = (string) Str::uuid();
        Storage::disk('local')->putFileAs("import_temp/{$tempId}", $request->file('user_dat'), 'user.dat');

        session(['import_temp_user' => [
            'id'          => $tempId,
            'filename'    => $request->file('user_dat')->getClientOriginalName(),
            'uploaded_by' => Auth::id(),
        ]]);

        return redirect()->route('import.sync-users.preview');
    }

    /**
     * Show the mapping preview for a staged user.dat.
     */
    public function syncUsersPreview(ZktecoParserService $parser)
    {
        $stage = session('import_temp_user');
        if (!$stage || ($stage['uploaded_by'] ?? null) !== Auth::id()) {
            return redirect()->route('import.sync-users.form')->with('error', 'No staged user.dat. Please upload again.');
        }

        $path = Storage::disk('local')->path("import_temp/{$stage['id']}/user.dat");
        if (!file_exists($path)) {
            session()->forget('import_temp_user');
            return redirect()->route('import.sync-users.form')->with('error', 'Staged file expired. Please upload again.');
        }

        $rows = $parser->extractUsersFromDat($path);

        $byZk = Employee::pluck('id', 'zkteco_id')->toArray();
        $emps = Employee::select('id', 'zkteco_id', 'full_name', 'actual_name')->get()->keyBy('id');
        $byName = [];
        foreach ($emps as $e) {
            foreach ([$e->full_name, $e->actual_name] as $n) {
                $key = mb_strtolower(trim((string) $n));
                if ($key !== '') $byName[$key][] = $e->id;
            }
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
                $cat = mb_strtolower($sysName) === $lookup ? 'match_exact' : 'match_id_only';
                $results[] = [
                    'zkteco_id'   => $zk,
                    'file_name'   => $name,
                    'category'    => $cat,
                    'system_id'   => $existing->id,
                    'system_name' => $sysName,
                    'name_matches_other' => $byName[$lookup] ?? [],
                ];
            } else {
                $nameMatches = $byName[$lookup] ?? [];
                $cat = !empty($nameMatches) ? 'name_conflict' : 'new';
                $results[] = [
                    'zkteco_id'   => $zk,
                    'file_name'   => $name,
                    'category'    => $cat,
                    'system_id'   => null,
                    'system_name' => null,
                    'name_matches_other' => $nameMatches,
                ];
            }
            $counts[$cat]++;
        }

        return view('import.sync-users-preview', [
            'stage'   => $stage,
            'results' => $results,
            'counts'  => $counts,
        ]);
    }

    /**
     * Commit the staged user.dat by running the parser's user-sync step
     * (updateOrCreate on Employee for every row). No attendance import is
     * dispatched here.
     */
    public function syncUsersCommit(ZktecoParserService $parser)
    {
        $stage = session('import_temp_user');
        if (!$stage || ($stage['uploaded_by'] ?? null) !== Auth::id()) {
            return redirect()->route('import.sync-users.form')->with('error', 'No staged user.dat to commit.');
        }

        $path = Storage::disk('local')->path("import_temp/{$stage['id']}/user.dat");
        if (!file_exists($path)) {
            session()->forget('import_temp_user');
            return redirect()->route('import.sync-users.form')->with('error', 'Staged file expired.');
        }

        $rows = $parser->extractUsersFromDat($path);
        $changes = ['created' => 0, 'updated' => 0, 'unchanged' => 0];
        foreach ($rows as $row) {
            $zk = $row['zkteco_id'];
            $name = $row['full_name'];
            $emp = Employee::where('zkteco_id', $zk)->first();
            if (!$emp) {
                Employee::create(['zkteco_id' => $zk, 'full_name' => $name]);
                $changes['created']++;
            } elseif (trim((string) $emp->full_name) !== trim($name)) {
                $emp->update(['full_name' => $name]);
                $changes['updated']++;
            } else {
                $changes['unchanged']++;
            }
        }

        Storage::disk('local')->deleteDirectory("import_temp/{$stage['id']}");
        session()->forget('import_temp_user');

        $msg = "Users synced: {$changes['created']} created, {$changes['updated']} renamed, {$changes['unchanged']} unchanged.";
        return redirect()->route('import.index')->with('success', $msg);
    }

    public function syncUsersCancel()
    {
        $this->discardTempUserDat();
        return redirect()->route('import.index')->with('success', 'Sync cancelled. Staged file discarded.');
    }

    protected function discardTempUserDat(): void
    {
        $stage = session('import_temp_user');
        if ($stage && ($stage['uploaded_by'] ?? null) === Auth::id()) {
            Storage::disk('local')->deleteDirectory("import_temp/{$stage['id']}");
        }
        session()->forget('import_temp_user');
    }
}
