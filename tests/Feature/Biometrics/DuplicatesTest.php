<?php

namespace Tests\Feature\Biometrics;

use App\Models\AttendanceImportRun;
use App\Services\AttendanceIngestLock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * S-012.3 The same punch posted again, every minute, twice at once, or after a timeout is stored once.
 */
class DuplicatesTest extends BiometricsTestCase
{
    #[TestDox('S-012.3 the same punch posted twice is stored once; the second run says 1 duplicate')]
    public function test_s_012_3_same_punch_twice(): void
    {
        $line = $this->punch('1', self::DAY, '08:00:12');
        $this->postPunches($line)->assertOk();
        $second = $this->postPunches($line)->assertOk();

        $this->assertSame(1, DB::table('attendance_logs')->count());
        $this->assertSame(1, $second->json('duplicates'));
        $this->assertSame(0, $second->json('inserted'));
        $run = AttendanceImportRun::findOrFail($second->json('run_id'));
        $this->assertSame(1, $run->stats_json['total_duplicates_skipped']);
    }

    #[TestDox('S-012.3 the 60-second re-read of 200 known records stores nothing new and recomputes nothing')]
    public function test_s_012_3_reread_of_200_known_records(): void
    {
        $lines = [];
        foreach (['1', '2', '4'] as $zk) {
            foreach (['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06', '2026-11-07',
                      '2026-11-08', '2026-11-09', '2026-11-10', '2026-10-30', '2026-10-31', '2026-11-01',
                      '2026-10-29', '2026-10-28', '2026-10-27', '2026-10-26', '2026-10-25'] as $date) {
                foreach (self::FULL_DAY as $time) {
                    $lines[] = $this->punch($zk, $date, $time);
                }
            }
        }
        $lines = array_slice($lines, 0, 200);
        $this->postPunches($lines)->assertOk();
        $logs = DB::table('attendance_logs')->count();
        $days = DB::table('attendance_days')->orderBy('id')->get(['id', 'updated_at'])->all();

        $again = $this->postPunches($lines)->assertOk();

        $this->assertSame(200, $logs);
        $this->assertSame($logs, DB::table('attendance_logs')->count());
        $this->assertSame(0, $again->json('inserted'));
        $this->assertSame(200, $again->json('duplicates'));
        $this->assertSame(0, $again->json('days_computed'));
        $this->assertEquals($days, DB::table('attendance_days')->orderBy('id')->get(['id', 'updated_at'])->all());
    }

    #[TestDox('S-012.3 a post while another ingest holds the lock waits and stores nothing; it is refused with 503 and the retry stores the punch once')]
    public function test_s_012_3_concurrent_post_is_serialized(): void
    {
        config(['biometrics.lock_wait_seconds' => 0]);
        $line = $this->punch('1', self::DAY, '08:00:12');

        $other = Cache::lock(AttendanceIngestLock::NAME, 60);
        $this->assertTrue($other->get(), 'the test holds the ingest lock, as a running ingest would');
        try {
            $busy = $this->postPunches($line);
            $busy->assertStatus(503);
            $this->assertSame(0, DB::table('attendance_logs')->count());
            $this->assertNotNull($busy->json('server_time'));
        } finally {
            $other->release();
        }

        $this->postPunches($line)->assertOk();
        $this->postPunches($line)->assertOk();
        $this->assertSame(1, DB::table('attendance_logs')->count());
    }

    #[TestDox('S-012.3 a retry after a timeout (the first post was stored, the agent never saw the reply) gives no double row')]
    public function test_s_012_3_retry_after_timeout(): void
    {
        $lines = [$this->punch('1', self::DAY, '08:00:12'), $this->punch('1', self::DAY, '17:02:00')];
        $this->postPunches($lines)->assertOk();
        // The retry carries the same lines and one punch that arrived since, within the 3-minute window of another.
        $retry = $this->postPunches(array_merge($lines, [$this->punch('1', self::DAY, '17:03:30')]))->assertOk();

        $this->assertSame(2, DB::table('attendance_logs')->count());
        $this->assertSame(3, $retry->json('duplicates'));
    }

    #[TestDox('S-012.3 posts that change nothing leave one quiet run a day, hidden from the Import page')]
    public function test_s_012_3_quiet_runs_are_pruned_and_hidden(): void
    {
        $line = $this->punch('1', self::DAY, '08:00:12');
        $first = $this->postPunches($line)->json('run_id');
        $q1 = $this->postPunches($line)->json('run_id');

        $this->travel(2)->days();
        $q2 = $this->postPunches($line)->json('run_id');

        $this->assertNotNull(AttendanceImportRun::find($first), 'a run that stored punches is kept');
        $this->assertNull(AttendanceImportRun::find($q1), 'a quiet run older than a day is removed');
        $this->assertNotNull(AttendanceImportRun::find($q2), 'the latest quiet run is kept');

        $html = $this->actingAs($this->uploader)->get('/import')->assertOk()->getContent();
        $this->assertStringContainsString('data-run-id="' . $first . '"', $html);
        $this->assertStringNotContainsString('data-run-id="' . $q2 . '"', $html);
    }
}
