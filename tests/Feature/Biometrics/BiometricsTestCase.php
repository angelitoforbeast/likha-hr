<?php

namespace Tests\Feature\Biometrics;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Import\ImportTestCase;

/**
 * Slice 012 fixtures, on top of the import fixtures of slice 001 (employees A–D with zkteco
 * ids 1–4, A, B and D active, C inactive, all on the 08:00–17:00 shift): a test-only feed
 * key, a fixed "now" of Tuesday 2026-11-10 10:00 Manila, and helpers that call the
 * biometrics endpoints the way the office PC agent does.
 */
abstract class BiometricsTestCase extends ImportTestCase
{
    protected const KEY = 'test-only-biometrics-key-0123456789abcdef';
    protected const NOW = '2026-11-10 10:00:00';
    protected const DAY = '2026-11-02';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.biometrics_feed.key' => self::KEY]);
        Carbon::setTestNow(Carbon::parse(self::NOW, 'Asia/Manila'));
        $this->datesAsInProduction();
    }

    /**
     * As in the approvals fixtures: a MySQL DATE column drops the time part, a sqlite column
     * keeps "2026-11-02 00:00:00" as the models write it, and the compute's lookups by
     * "2026-11-02" would then miss the row on the second compute of a day. These triggers,
     * only in the test database, keep the date columns as production does.
     */
    protected function datesAsInProduction(): void
    {
        foreach (['attendance_days' => 'work_date', 'attendance_overrides' => 'work_date', 'attendance_date_locks' => 'lock_date'] as $table => $column) {
            foreach (['INSERT', 'UPDATE'] as $event) {
                DB::statement(
                    "CREATE TRIGGER IF NOT EXISTS date_only_{$table}_" . strtolower($event)
                    . " AFTER {$event} ON {$table} WHEN length(NEW.{$column}) > 10"
                    . " BEGIN UPDATE {$table} SET {$column} = substr({$column}, 1, 10) WHERE id = NEW.id; END"
                );
            }
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** POST /api/biometrics/punches with an attlog text (or lines joined by "\n"). */
    protected function postPunches(string|array $attlog, ?string $key = self::KEY, array $extra = [], array $server = []): TestResponse
    {
        $body = array_merge([
            'agent_id' => 'office-pc',
            'attlog'   => is_array($attlog) ? implode("\n", $attlog) : $attlog,
        ], $extra);

        return $this->call('POST', '/api/biometrics/punches', [], [], [],
            $this->headers($key, $server), json_encode($body));
    }

    protected function heartbeat(array $body = [], ?string $key = self::KEY): TestResponse
    {
        return $this->call('POST', '/api/biometrics/heartbeat', [], [], [],
            $this->headers($key), json_encode(array_merge([
                'agent_id'            => 'office-pc',
                'agent_version'       => '1.0.0',
                'device_reachable'    => true,
                'device_record_count' => 120,
                'device_user_count'   => 53,
            ], $body)));
    }

    protected function postUsers(array $users, ?string $key = self::KEY): TestResponse
    {
        return $this->call('POST', '/api/biometrics/users', [], [], [],
            $this->headers($key), json_encode(['agent_id' => 'office-pc', 'users' => $users]));
    }

    protected function feedStatus(?string $key = self::KEY): TestResponse
    {
        return $this->call('GET', '/api/biometrics/status', [], [], [], $this->headers($key));
    }

    protected function headers(?string $key, array $server = []): array
    {
        return array_merge(
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            $key === null ? [] : ['HTTP_X_BIOMETRICS_FEED_KEY' => $key],
            $server
        );
    }

    /** A plain attlog line: id, tab, date and time. */
    protected function punch(string $zktecoId, string $date, string $time): string
    {
        return "{$zktecoId}\t{$date} {$time}";
    }

    /** Every stored punch as "employee id|Y-m-d H:i:s", sorted. */
    protected function storedPunches(): array
    {
        return DB::table('attendance_logs')->orderBy('employee_id')->orderBy('punched_at')->get()
            ->map(fn ($row) => $row->employee_id . '|' . substr((string) $row->punched_at, 0, 19))
            ->all();
    }

    /** The rows of the feed table of one kind, as "key|value", sorted. */
    protected function feedRows(string $kind): array
    {
        return DB::table('biometric_feed')->where('kind', $kind)->orderBy('key')->orderBy('value')->get()
            ->map(fn ($row) => $row->key . '|' . $row->value)
            ->all();
    }

    /**
     * Every attendance write the endpoints could make, to prove a refused call wrote nothing.
     * The agent state rows (last contact) are checked on their own where it matters.
     */
    protected function writeCounts(): array
    {
        return [
            'attendance_logs'        => DB::table('attendance_logs')->count(),
            'attendance_days'        => DB::table('attendance_days')->count(),
            'attendance_import_runs' => DB::table('attendance_import_runs')->count(),
            'biometric_feed'         => DB::table('biometric_feed')->where('kind', '!=', 'state')->count(),
            'employees'              => DB::table('employees')->count(),
        ];
    }
}
