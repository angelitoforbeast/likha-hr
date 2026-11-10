<?php

namespace App\Services;

use App\Models\AttendanceImportRun;
use App\Models\BiometricFeed;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * The server side of the office biometrics feed (spec 012). Punches posted by the agent and
 * held punches go through the import path of a manual upload: a run row, the attlog file in
 * the run's folder, ZktecoParserService::parse (de-duplication, holds, refusals, compute).
 */
class BiometricFeedService
{
    public function __construct(protected ZktecoParserService $parser)
    {
    }

    /** The non-empty lines of an attlog text, whatever its line endings. */
    public static function attlogLines(string $text): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $text);

        return array_values(array_filter(array_map('trim', $lines), fn ($line) => $line !== ''));
    }

    /**
     * Import the lines of one agent post as one run, then apply held punches whose employee
     * now exists. Runs under the ingest lock.
     *
     * @throws \Illuminate\Contracts\Cache\LockTimeoutException when another import keeps the lock
     */
    public function ingestAgentPost(array $lines, string $agentId): AttendanceImportRun
    {
        return AttendanceIngestLock::run(function () use ($lines, $agentId) {
            $run = $this->importLines($lines, null, ['source' => 'agent', 'agent_id' => $agentId]);
            $this->applyHeldPunches(null);
            $this->settleQuietRun($run);
            $this->pruneQuietRuns();

            return $run;
        }, (int) config('biometrics.lock_wait_seconds', 20));
    }

    /**
     * Apply held punches whose zkteco_id now belongs to an employee: one run through the import
     * path, then the applied rows are removed. Call it inside the ingest lock. Returns the run,
     * or null when nothing was waiting.
     */
    public function applyHeldPunches(?int $userId, ?string $onlyZkteco = null): ?AttendanceImportRun
    {
        $query = DB::table('biometric_feed')
            ->join('employees', 'employees.zkteco_id', '=', 'biometric_feed.key')
            ->where('biometric_feed.kind', BiometricFeed::HELD)
            ->select('biometric_feed.id', 'biometric_feed.key', 'biometric_feed.value', 'employees.zkteco_id')
            ->orderBy('biometric_feed.key')->orderBy('biometric_feed.value');
        if ($onlyZkteco !== null) {
            $query->where('biometric_feed.key', $onlyZkteco);
        }
        // The import matches IDs exactly; a MySQL collation also matches "54" with "54 ", and
        // such a row would be deleted below without being stored. Only exact matches go on.
        $rows = $query->get()->filter(fn ($row) => (string) $row->key === (string) $row->zkteco_id)->values();
        if ($rows->isEmpty()) {
            return null;
        }

        $lines = $rows->map(fn ($row) => $row->key . "\t" . $row->value)->all();
        $run = $this->importLines($lines, $userId, ['source' => 'held']);

        if ($run->status === 'done') {
            foreach ($rows->pluck('id')->chunk(500) as $ids) {
                DB::table('biometric_feed')->whereIn('id', $ids->all())->delete();
            }
        }

        return $run;
    }

    /**
     * When HR adds an employee (or gives one a zkteco_id), apply what was held for that ID.
     * Never fails the save: if the lock stays busy or the import fails, the punches stay held
     * and the next agent post or manual import applies them.
     */
    public function applyHeldForEmployee(Employee $employee): void
    {
        $zk = (string) $employee->zkteco_id;
        if ($zk === '' || AttendanceIngestLock::active()) {
            // Inside an import (a user.dat that creates employees): it applies them itself
            // once its own file is done, so its de-duplication is not interleaved.
            return;
        }
        if (!DB::table('biometric_feed')->where('kind', BiometricFeed::HELD)->where('key', $zk)->exists()) {
            return;
        }

        try {
            AttendanceIngestLock::run(
                fn () => $this->applyHeldPunches(auth()->id(), $zk),
                (int) config('biometrics.lock_wait_seconds', 20)
            );
        } catch (\Throwable $e) {
            Log::warning('Held punches for a new employee were not applied yet (' . get_class($e) . '); the next import applies them.');
        }
    }

    /**
     * A run row with the lines as its attlog.dat, parsed the way the queue worker parses an
     * upload. The caller holds the ingest lock.
     */
    protected function importLines(array $lines, ?int $userId, array $origin): AttendanceImportRun
    {
        $run = AttendanceImportRun::create([
            'uploaded_by' => $userId,
            'status'      => 'queued',
            'stats_json'  => $origin,
        ]);

        Storage::disk('local')->put("imports/{$run->id}/attlog.dat", implode("\n", $lines) . "\n");
        $this->parser->parse($run);

        return $run->fresh();
    }

    /**
     * Mark an agent run that changed nothing, so the Import page does not list a run a minute.
     * Nothing stored, held, newly refused or computed, and no compute error. A locked date's
     * skip alone is no change: the same punches of a locked date come in every minute.
     */
    protected function settleQuietRun(AttendanceImportRun $run): void
    {
        $stats = $run->statsArray();
        $compute = $stats['compute'] ?? [];
        $quiet = $run->status === 'done'
            && (int) ($stats['total_logs_inserted'] ?? 0) === 0
            && (int) ($stats['held_punches'] ?? 0) === 0
            && (int) ($stats['refused_new'] ?? 0) === 0
            && (int) ($compute['days_computed'] ?? 0) === 0
            && (int) ($compute['errors'] ?? 0) === 0;

        if ($quiet) {
            $stats['quiet'] = 'yes';
            $run->update(['stats_json' => $stats]);
            // The lines are all known already; keep no file of them.
            Storage::disk('local')->deleteDirectory("imports/{$run->id}");
        }
    }

    /** Remove quiet agent runs older than a day. They hold no punch and no day refers to them. */
    protected function pruneQuietRuns(): void
    {
        $ids = AttendanceImportRun::whereNull('uploaded_by')
            ->where('stats_json->source', 'agent')
            ->where('stats_json->quiet', 'yes')
            ->where('created_at', '<', now()->subHours((int) config('biometrics.quiet_run_hours', 24)))
            ->orderBy('id')->limit(1000)->pluck('id');

        if ($ids->isNotEmpty()) {
            AttendanceImportRun::whereIn('id', $ids->all())->delete();
        }
    }

    /* ── Agent state ── */

    public function setState(string $key, ?string $value): void
    {
        if ($value === null) {
            BiometricFeed::where('kind', BiometricFeed::STATE)->where('key', $key)->delete();
            return;
        }

        $row = BiometricFeed::updateOrCreate(['kind' => BiometricFeed::STATE, 'key' => $key], ['value' => $value]);
        $row->touch();
        // Two calls at the same moment could each create a row; keep the one just written.
        BiometricFeed::where('kind', BiometricFeed::STATE)->where('key', $key)->where('id', '!=', $row->id)->delete();
    }

    public function state(string $key): ?string
    {
        return BiometricFeed::where('kind', BiometricFeed::STATE)->where('key', $key)
            ->orderByDesc('updated_at')->orderByDesc('id')->value('value');
    }

    /** Every authenticated call of the agent, except the read-only status, is a contact. */
    public function recordContact(): void
    {
        $this->setState('last_contact', Carbon::now()->utc()->format('Y-m-d H:i:s'));
    }

    /** The daily clock check: the latest drift, and one log row per Manila date. */
    public function recordClockCheck(int $driftSeconds, bool $set): void
    {
        $this->setState('clock_drift_seconds', (string) $driftSeconds);
        $this->setState('clock_set', $set ? '1' : '0');
        $this->setState('clock_checked_at', Carbon::now()->utc()->format('Y-m-d H:i:s'));

        $date = Carbon::now('Asia/Manila')->format('Y-m-d');
        DB::transaction(function () use ($date, $driftSeconds, $set) {
            BiometricFeed::where('kind', BiometricFeed::DRIFT)->where('key', $date)->delete();
            BiometricFeed::create([
                'kind' => BiometricFeed::DRIFT, 'key' => $date, 'value' => $driftSeconds . ($set ? ' set' : ' not set'),
            ]);
        });
    }

    /**
     * Replace the list of device users who are not employees. It only lists them: no employee
     * is created, renamed or removed (spec 012, decision 6).
     *
     * @param array<int, array{user_id:string, name:?string}> $users
     * @return array{device_users:int, not_in_hr:int}
     */
    public function replaceDeviceUsers(array $users): array
    {
        $known = Employee::whereNotNull('zkteco_id')->pluck('zkteco_id')->map(fn ($zk) => (string) $zk)->flip();
        $byId = [];
        foreach ($users as $user) {
            $id = (string) $user['user_id'];
            $byId[$id] ??= trim((string) ($user['name'] ?? ''));
        }
        $missing = array_filter($byId, fn ($name, $id) => !isset($known[(string) $id]), ARRAY_FILTER_USE_BOTH);

        DB::transaction(function () use ($missing) {
            BiometricFeed::where('kind', BiometricFeed::DEVICE_USER)->delete();
            $now = now();
            $rows = [];
            foreach ($missing as $id => $name) {
                $rows[] = ['kind' => BiometricFeed::DEVICE_USER, 'key' => (string) $id, 'value' => $name,
                           'created_at' => $now, 'updated_at' => $now];
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('biometric_feed')->insert($chunk);
            }
        });
        $this->setState('device_users_at', Carbon::now()->utc()->format('Y-m-d H:i:s'));

        return ['device_users' => count($byId), 'not_in_hr' => count($missing)];
    }

    /**
     * The latest punch stored, as Manila "Y-m-d H:i:s", or null. A held punch counts as
     * received too. Bounded by now + 1 day, the latest time an import accepts.
     */
    public function lastPunchAt(): ?string
    {
        $limit = $this->parser->acceptedRange()[1];
        $stored = DB::table('attendance_logs')->where('punched_at', '<=', $limit)->max('punched_at');
        $held = DB::table('biometric_feed')->where('kind', BiometricFeed::HELD)->where('value', '<=', $limit)->max('value');

        $latest = max((string) $stored, (string) $held);

        return $latest === '' ? null : substr($latest, 0, 19);
    }
}
