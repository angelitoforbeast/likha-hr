<?php

namespace App\Services;

use App\Models\AttendanceImportRun;
use App\Models\AttendanceLog;
use App\Models\Employee;
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

    /**
     * Parse user.dat and attlog.dat for a given import run.
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

        try {
            $run->update(['status' => 'processing']);

            // ── Parse user.dat ──
            $userMap = $this->parseUserDat($basePath . '/user.dat', $stats);

            // ── Parse attlog.dat ──
            $this->parseAttlogDat($basePath . '/attlog.dat', $run, $userMap, $stats);

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
     * Parse binary user.dat (ZKTeco 72-byte fixed-length records).
     *
     * Record structure (72 bytes):
     *   Bytes 0-1:   UID (uint16 little-endian)
     *   Bytes 2-10:  Flags/card data
     *   Bytes 11-38: Name (28 bytes, null-terminated ASCII)
     *   Bytes 39-71: Additional data (password, card number, group, etc.)
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

            // Extract UID (2 bytes, little-endian unsigned short)
            $uid = unpack('v', substr($record, 0, 2))[1];

            // Extract name (bytes 11-38, null-terminated)
            $nameRaw = substr($record, 11, 28);
            $nameEnd = strpos($nameRaw, "\x00");
            if ($nameEnd !== false) {
                $nameRaw = substr($nameRaw, 0, $nameEnd);
            }
            $fullName = trim($nameRaw);

            if ($uid <= 0 || empty($fullName)) {
                continue;
            }

            $zktecoId = (string) $uid;
            $stats['total_users_parsed']++;

            $employee = Employee::updateOrCreate(
                ['zkteco_id' => $zktecoId],
                ['full_name' => $fullName, 'status' => 'active']
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
                ['full_name' => $fullName, 'status' => 'active']
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
     * Parse attlog.dat and insert attendance logs.
     */
    protected function parseAttlogDat(string $path, AttendanceImportRun $run, array $userMap, array &$stats): void
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

        // Pre-load existing punches for dedup: employee_id => [timestamp => true]
        $existingPunches = [];

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
            if (!$parsed) {
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

            // Dedup within threshold
            if ($this->isDuplicate($existingPunches, $employeeId, $punchCarbon)) {
                $stats['total_duplicates_skipped']++;
                continue;
            }

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
