<?php

namespace App\Services;

use App\Models\AttendanceImportRun;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Services\AttendanceComputeService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ZktecoParserService
{
    /**
     * Configurable dedup threshold in minutes.
     */
    protected int $dedupThresholdMinutes = 3;

    public function __construct(protected AttendanceComputeService $compute)
    {
    }

    /**
     * Parse user.dat and attlog.dat for a given import run, then compute the
     * attendance days the file names, so the calendar is filled without a
     * separate "Compute Attendance" step.
     */
    public function parse(AttendanceImportRun $run): void
    {
        $startTime = microtime(true);
        $runId = $run->id;
        $basePath = "imports/{$runId}";

        $stats = [
            'total_users_parsed'       => 0,
            'total_logs_parsed'        => 0,
            'total_logs_inserted'      => 0,
            'total_duplicates_skipped' => 0,
            'unmatched_zkteco_ids'     => 0,
            'duration_seconds'         => 0,
        ];

        // The commit step stored which preview filter produced this file. It must
        // outlive the counts written below, on success and on failure alike.
        $previewFilter = $run->statsArray()['preview_filter'] ?? null;
        if (is_array($previewFilter)) {
            $stats['preview_filter'] = $previewFilter;
        }

        try {
            $run->update(['status' => 'processing']);

            // ── Parse user.dat ──
            $userMap = $this->parseUserDat($basePath . '/user.dat', $stats);

            // ── Parse attlog.dat ──
            $employeeDays = [];
            $this->parseAttlogDat($basePath . '/attlog.dat', $run, $userMap, $stats, $employeeDays);

            // ── Compute the days named in the file ──
            $stats['compute'] = $this->computeImportedDays($employeeDays, $runId);

            $stats['duration_seconds'] = round(microtime(true) - $startTime, 2);
            $run->update([
                'status'     => 'done',
                'stats_json' => $stats,
            ]);
        } catch (\Throwable $e) {
            Log::error("Import run #{$runId} failed: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            $stats['duration_seconds'] = round(microtime(true) - $startTime, 2);
            $stats['error'] = $e->getMessage();
            $run->update([
                'status'     => 'failed',
                'stats_json' => $stats,
            ]);
        }
    }

    /**
     * Compute the employee-days of an import and return the summary for the run's stats.
     *
     * The punches are already saved at this point. A compute problem must not turn
     * the run into "failed", because that would make the admin upload the file again;
     * it is logged and shows up as an error count instead.
     *
     * @param array<int, array<string, bool>> $employeeDays employee id => ['Y-m-d' => whether
     *                                                      this run stored a new punch on it]
     * @return array{days_computed:int, locked_skipped:int, errors:int}
     */
    protected function computeImportedDays(array $employeeDays, int $runId): array
    {
        try {
            $summary = $this->compute->computeEmployeeDays($employeeDays, $runId);

            return [
                'days_computed'  => (int) ($summary['days_computed'] ?? 0),
                'locked_skipped' => (int) ($summary['locked_skipped'] ?? 0),
                'errors'         => (int) ($summary['errors'] ?? 0),
            ];
        } catch (\Throwable $e) {
            // The class only: the message of a database error can carry query text and values.
            Log::error("Import run #{$runId}: computing the attendance days failed (" . get_class($e) . ').');

            return ['days_computed' => 0, 'locked_skipped' => 0, 'errors' => 1];
        }
    }

    /**
     * Parse user.dat and create/update employees.
     * Returns a map of zkteco_id => employee_id.
     *
     * Supports:
     *  - Binary format (72-byte fixed-length records from ZKTeco devices)
     *  - Text key=value format (UID=1\tID=1\tName=John Doe\tPri=0)
     *  - Text tab-separated format (UID\tID\tName\tPri)
     */
    protected function parseUserDat(string $path, array &$stats): array
    {
        $userMap = [];
        $fullPath = Storage::disk('local')->path($path);

        if (!file_exists($fullPath)) {
            Log::warning("user.dat not found at {$fullPath}, skipping user sync.");
            return $userMap;
        }

        $data = file_get_contents($fullPath);
        if ($data === false || strlen($data) === 0) {
            return $userMap;
        }

        // Detect format: binary vs text
        if ($this->isBinaryUserDat($data)) {
            return $this->parseBinaryUserDat($data, $stats);
        }

        return $this->parseTextUserDat($fullPath, $stats);
    }

    /**
     * Detect if user.dat is in binary format.
     * Binary files contain many null bytes in the first 100 bytes.
     */
    protected function isBinaryUserDat(string $data): bool
    {
        $sample = substr($data, 0, min(200, strlen($data)));
        $nullCount = substr_count($sample, "\x00");
        // If more than 20% of the sample is null bytes, it's binary
        return ($nullCount / strlen($sample)) > 0.2;
    }

    /**
     * Read a uploaded user.dat from an absolute local path and return the
     * parsed users WITHOUT touching the employees table. Used by the
     * "check user.dat" preview flow so CEO/Admin can verify a mapping
     * before running an actual import.
     *
     * @return array<int,array{zkteco_id:string,full_name:string}>
     */
    public function extractUsersFromDat(string $absolutePath): array
    {
        if (!file_exists($absolutePath)) {
            throw new \RuntimeException("File not found: {$absolutePath}");
        }

        $data = file_get_contents($absolutePath);
        if ($data === false || $data === '') {
            return [];
        }

        $rows = [];
        $stats = ['total_users_parsed' => 0];

        if ($this->isBinaryUserDat($data)) {
            $recordSize = 72;
            $numRecords = intdiv(strlen($data), $recordSize);
            for ($i = 0; $i < $numRecords; $i++) {
                $record = substr($data, $i * $recordSize, $recordSize);
                $nameRaw = substr($record, 11, 28);
                if (($e = strpos($nameRaw, "\x00")) !== false) $nameRaw = substr($nameRaw, 0, $e);
                $fullName = trim($nameRaw);
                $idRaw = substr($record, 48, 8);
                if (($e = strpos($idRaw, "\x00")) !== false) $idRaw = substr($idRaw, 0, $e);
                $zktecoId = trim($idRaw);
                if ($zktecoId === '' || $fullName === '') continue;
                $rows[] = ['zkteco_id' => $zktecoId, 'full_name' => $fullName];
            }
            return $rows;
        }

        // Text format — reuse the existing line parser.
        $handle = fopen($absolutePath, 'r');
        if (!$handle) return [];
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') continue;
            $parsed = $this->parseUserLine($line);
            if (!$parsed) continue;
            $rows[] = $parsed;
        }
        fclose($handle);
        return $rows;
    }

    /**
     * Parse binary user.dat (ZKTeco 72-byte fixed-length records).
     *
     * Record structure (72 bytes):
     *   Bytes 0-1:   UID (uint16 LE) — internal device record number (NOT the user ID)
     *   Bytes 2-10:  Flags/card data
     *   Bytes 11-38: Name (28 bytes, null-terminated ASCII)
     *   Bytes 39-47: Additional data (password, group, etc.)
     *   Bytes 48-55: ID (ASCII string, null-terminated) — the actual ZKTeco user ID used in attlog.dat
     *   Bytes 56-71: Remaining data
     */
    protected function parseBinaryUserDat(string $data, array &$stats): array
    {
        $userMap = [];
        $recordSize = 72;
        $numRecords = intdiv(strlen($data), $recordSize);

        Log::info("Parsing binary user.dat: {$numRecords} records detected ({$recordSize} bytes each).");

        for ($i = 0; $i < $numRecords; $i++) {
            $offset = $i * $recordSize;
            $record = substr($data, $offset, $recordSize);

            // Extract Name (bytes 11-38, null-terminated)
            $nameRaw = substr($record, 11, 28);
            $nameEnd = strpos($nameRaw, "\x00");
            if ($nameEnd !== false) {
                $nameRaw = substr($nameRaw, 0, $nameEnd);
            }
            $fullName = trim($nameRaw);

            // Extract ID (bytes 48-55, ASCII null-terminated) — this is the actual ZKTeco ID
            $idRaw = substr($record, 48, 8);
            $idEnd = strpos($idRaw, "\x00");
            if ($idEnd !== false) {
                $idRaw = substr($idRaw, 0, $idEnd);
            }
            $zktecoId = trim($idRaw);

            if (empty($zktecoId) || empty($fullName)) {
                continue;
            }

            $stats['total_users_parsed']++;

            $employee = Employee::updateOrCreate(
                ['zkteco_id' => $zktecoId],
                ['full_name' => $fullName]
            );

            $userMap[$zktecoId] = $employee->id;
        }

        Log::info("Binary user.dat parsed: {$stats['total_users_parsed']} employees created/updated.");
        return $userMap;
    }

    /**
     * Parse text-based user.dat (key=value or tab-separated).
     */
    protected function parseTextUserDat(string $fullPath, array &$stats): array
    {
        $userMap = [];
        $handle = fopen($fullPath, 'r');
        if (!$handle) {
            return $userMap;
        }

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            $parsed = $this->parseUserLine($line);
            if (!$parsed) {
                continue;
            }

            $stats['total_users_parsed']++;

            $zktecoId = $parsed['zkteco_id'];
            $fullName = $parsed['full_name'];

            $employee = Employee::updateOrCreate(
                ['zkteco_id' => $zktecoId],
                ['full_name' => $fullName]
            );

            $userMap[$zktecoId] = $employee->id;
        }

        fclose($handle);
        return $userMap;
    }

    /**
     * Parse a single text user.dat line.
     * Supports multiple ZKTeco text formats.
     */
    protected function parseUserLine(string $line): ?array
    {
        // Format 1: key=value pairs separated by tabs
        if (str_contains($line, '=')) {
            $parts = [];
            foreach (explode("\t", $line) as $segment) {
                $kv = explode('=', $segment, 2);
                if (count($kv) === 2) {
                    $parts[strtolower(trim($kv[0]))] = trim($kv[1]);
                }
            }

            $zktecoId = $parts['id'] ?? ($parts['uid'] ?? null);
            $fullName = $parts['name'] ?? null;

            if ($zktecoId && $fullName) {
                return [
                    'zkteco_id' => $this->normalizeString($zktecoId),
                    'full_name' => $this->normalizeString($fullName),
                ];
            }
        }

        // Format 2: tab-separated (UID, ID, Name, Pri, ...)
        $cols = preg_split('/\t+/', $line);
        if (count($cols) >= 3) {
            return [
                'zkteco_id' => $this->normalizeString($cols[1] ?? $cols[0]),
                'full_name' => $this->normalizeString($cols[2]),
            ];
        }

        return null;
    }

    /**
     * Read a uploaded attlog.dat from an absolute local path and return a
     * per-employee, per-date presence summary WITHOUT writing anything.
     *
     * Used by the attlog preview flow so CEO / Admin can see who has punches
     * before running the real import.
     *
     * @param  string  $absolutePath  Local file path to attlog.dat
     * @param  string  $dateFrom      'Y-m-d' — earliest date to include (inclusive)
     * @param  string  $dateTo        'Y-m-d' — latest date to include (inclusive)
     * @return array{
     *   dates: string[],
     *   presence: array<string, array<string,bool>>,   // [zkteco_id => [date => true]]
     *   total_lines: int,
     *   invalid_lines: int,
     *   out_of_range: int,
     * }
     */
    public function extractAttlogSummary(string $absolutePath, string $dateFrom, string $dateTo): array
    {
        if (!file_exists($absolutePath)) {
            throw new \RuntimeException("attlog.dat not found: {$absolutePath}");
        }

        $fromTs = strtotime($dateFrom . ' 00:00:00');
        $toTs   = strtotime($dateTo   . ' 23:59:59');
        if ($fromTs === false || $toTs === false || $toTs < $fromTs) {
            throw new \RuntimeException("Invalid date range: {$dateFrom} to {$dateTo}");
        }

        $presence   = [];
        $totalLines = 0;
        $invalid    = 0;
        $outRange   = 0;

        $handle = fopen($absolutePath, 'r');
        if (!$handle) return ['dates' => [], 'presence' => [], 'total_lines' => 0, 'invalid_lines' => 0, 'out_of_range' => 0];

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') continue;
            $totalLines++;

            $parsed = $this->parseAttlogLine($line);
            if (!$parsed) { $invalid++; continue; }

            $ts = strtotime($parsed['punched_at']);
            if ($ts === false) { $invalid++; continue; }

            if ($ts < $fromTs || $ts > $toTs) {
                $outRange++;
                continue;
            }

            $date = date('Y-m-d', $ts);
            $zk   = $parsed['zkteco_id'];
            $presence[$zk][$date] = true;
        }
        fclose($handle);

        // Build the ordered date list.
        $dates = [];
        for ($ts = $fromTs; $ts <= $toTs; $ts += 86400) {
            $dates[] = date('Y-m-d', $ts);
        }

        return [
            'dates'         => $dates,
            'presence'      => $presence,
            'total_lines'   => $totalLines,
            'invalid_lines' => $invalid,
            'out_of_range'  => $outRange,
        ];
    }

    /**
     * Parse attlog.dat and insert attendance logs.
     *
     * $employeeDays collects every employee and date that has a valid, matched line,
     * duplicates included, each with whether this run stored a new punch on it: a day
     * whose punches were all stored by an earlier import still has to be computed when
     * it has no day row yet, and is left alone when it has one.
     */
    protected function parseAttlogDat(string $path, AttendanceImportRun $run, array $userMap, array &$stats, array &$employeeDays = []): void
    {
        $fullPath = Storage::disk('local')->path($path);

        if (!file_exists($fullPath)) {
            throw new \RuntimeException("attlog.dat not found at {$fullPath}");
        }

        $handle = fopen($fullPath, 'r');
        if (!$handle) {
            throw new \RuntimeException("Cannot open attlog.dat");
        }

        // Pre-load existing employee zkteco_id => id map for unmatched tracking
        if (empty($userMap)) {
            $userMap = Employee::pluck('id', 'zkteco_id')->toArray();
        }

        // Pre-load the punches already stored for the employees and time span of this
        // file, so importing the same punches again does not store them twice:
        // employee_id => [timestamp, ...]
        $existingPunches = $this->loadStoredPunches($handle, $userMap);
        rewind($handle);

        $buffer = [];
        $chunkSize = 1000;
        $unmatchedIds = [];

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            $stats['total_logs_parsed']++;

            $parsed = $this->parseAttlogLine($line);
            if (!$parsed || !$this->isRealTimestamp($parsed['punched_at'])) {
                continue;
            }

            $zktecoId = $parsed['zkteco_id'];
            $punchedAt = $parsed['punched_at'];

            // Map to employee
            $employeeId = $userMap[$zktecoId] ?? null;
            if (!$employeeId) {
                $unmatchedIds[$zktecoId] = true;
                continue;
            }

            // Convert to Asia/Manila
            $punchCarbon = Carbon::parse($punchedAt, 'Asia/Manila');
            $punchKey = $punchCarbon->format('Y-m-d H:i:s');

            $punchDate = $punchCarbon->format('Y-m-d');
            $employeeDays[$employeeId][$punchDate] ??= false;

            // Dedup within threshold
            if ($this->isDuplicate($existingPunches, $employeeId, $punchCarbon)) {
                $stats['total_duplicates_skipped']++;
                continue;
            }

            $employeeDays[$employeeId][$punchDate] = true;

            // Track for dedup (store raw timestamp for performance)
            if (!isset($existingPunches[$employeeId])) {
                $existingPunches[$employeeId] = [];
            }
            $existingPunches[$employeeId][] = $punchCarbon->getTimestamp();

            $buffer[] = [
                'employee_id'  => $employeeId,
                'punched_at'   => $punchKey,
                'source_run_id' => $run->id,
                'created_at'   => now(),
                'updated_at'   => now(),
            ];

            if (count($buffer) >= $chunkSize) {
                AttendanceLog::insert($buffer);
                $stats['total_logs_inserted'] += count($buffer);
                $buffer = [];
            }
        }

        // Insert remaining
        if (!empty($buffer)) {
            AttendanceLog::insert($buffer);
            $stats['total_logs_inserted'] += count($buffer);
        }

        fclose($handle);

        $stats['unmatched_zkteco_ids'] = count($unmatchedIds);
    }

    /**
     * First pass over attlog.dat: find which employees and which time span the file
     * covers, and load the punches already stored for them.
     *
     * Limited to that span (widened by the duplicate window) so a small file does not
     * pull an employee's whole punch history into memory.
     *
     * @param resource $handle open attlog.dat; left at the end of the file
     * @return array<int, int[]> employee_id => stored punch timestamps
     */
    protected function loadStoredPunches($handle, array $userMap): array
    {
        $employeeIds = [];
        $minTs = null;
        $maxTs = null;

        while (($line = fgets($handle)) !== false) {
            $parsed = $this->parseAttlogLine(trim($line));
            if (!$parsed || !$this->isRealTimestamp($parsed['punched_at'])) {
                continue;
            }

            $employeeId = $userMap[$parsed['zkteco_id']] ?? null;
            if (!$employeeId) {
                continue;
            }

            $ts = Carbon::parse($parsed['punched_at'], 'Asia/Manila')->getTimestamp();
            $employeeIds[$employeeId] = true;
            $minTs = $minTs === null ? $ts : min($minTs, $ts);
            $maxTs = $maxTs === null ? $ts : max($maxTs, $ts);
        }

        $stored = [];
        if (empty($employeeIds)) {
            return $stored;
        }

        $window = $this->dedupThresholdMinutes * 60;
        $from = Carbon::createFromTimestamp($minTs - $window, 'Asia/Manila')->format('Y-m-d H:i:s');
        $to   = Carbon::createFromTimestamp($maxTs + $window, 'Asia/Manila')->format('Y-m-d H:i:s');
        if (strlen($to) > 19) {
            // The widened end passed year 9999, which a DATETIME column cannot hold or compare.
            $to = '9999-12-31 23:59:59';
        }

        foreach (array_chunk(array_keys($employeeIds), 500) as $chunk) {
            // Raw values, read the same way as the file's lines, so both sides of the
            // duplicate comparison are in the same time zone.
            $rows = DB::table('attendance_logs')
                ->whereIn('employee_id', $chunk)
                ->whereBetween('punched_at', [$from, $to])
                ->select('employee_id', 'punched_at')
                // cursor() avoids building one collection of all rows, but the MySQL driver
                // still buffers the result; what bounds the memory is the span limit above.
                ->cursor();

            foreach ($rows as $row) {
                $stored[$row->employee_id][] = Carbon::parse($row->punched_at, 'Asia/Manila')->getTimestamp();
            }
        }

        return $stored;
    }

    /**
     * Whether a timestamp that has the right shape is also a real date and time.
     * The file is uploaded content: month 13 or 30 February must be dropped as an
     * invalid line instead of failing the run or being shifted to another date.
     */
    protected function isRealTimestamp(string $datetime): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})\s+(\d{2}):(\d{2}):(\d{2})$/', $datetime, $m)) {
            return false;
        }

        // Years before 1000 are outside what the DATETIME column of the punches supports.
        return (int) $m[1] >= 1000
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1])
            && (int) $m[4] < 24 && (int) $m[5] < 60 && (int) $m[6] < 60;
    }

    /**
     * Parse a single attlog.dat line.
     * Typical format: 1\t2024-01-15 10:05:23\t0\t1\t\t0\t0
     * Also handles leading whitespace before the ID.
     */
    protected function parseAttlogLine(string $line): ?array
    {
        $cols = preg_split('/\t+/', $line);
        if (count($cols) < 2) {
            return null;
        }

        $zktecoId = trim($cols[0]);
        $datetime = trim($cols[1]);

        // Validate datetime
        if (!preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}$/', $datetime)) {
            return null;
        }

        return [
            'zkteco_id'  => $zktecoId,
            'punched_at' => $datetime,
        ];
    }

    /**
     * Check if a punch is a duplicate within the threshold window.
     * Uses raw timestamp comparison for performance (avoids Carbon::diffInMinutes overhead).
     */
    protected function isDuplicate(array &$existingPunches, int $employeeId, Carbon $punchTime): bool
    {
        if (!isset($existingPunches[$employeeId])) {
            return false;
        }

        $punchTs = $punchTime->getTimestamp();
        $thresholdSeconds = $this->dedupThresholdMinutes * 60;

        foreach ($existingPunches[$employeeId] as $existingTs) {
            if (abs($punchTs - $existingTs) < $thresholdSeconds) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize string: fix encoding, trim whitespace.
     */
    protected function normalizeString(string $value): string
    {
        // Try to detect and convert encoding
        $encoding = mb_detect_encoding($value, ['UTF-8', 'ISO-8859-1', 'Windows-1252', 'ASCII'], true);
        if ($encoding && $encoding !== 'UTF-8') {
            $value = mb_convert_encoding($value, 'UTF-8', $encoding);
        }

        // Normalize whitespace
        $value = preg_replace('/\s+/', ' ', $value);
        return trim($value);
    }
}
