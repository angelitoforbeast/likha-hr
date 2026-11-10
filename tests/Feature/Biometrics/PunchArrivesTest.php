<?php

namespace Tests\Feature\Biometrics;

use App\Models\AttendanceImportRun;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * S-012.1 A punch posted by the office PC agent goes through the import path.
 */
class PunchArrivesTest extends BiometricsTestCase
{
    #[TestDox('S-012.1 one punch with the right key: one stored punch for zk 1, a run with source agent, the day computed')]
    public function test_s_012_1_one_punch_is_stored_and_computed(): void
    {
        $response = $this->postPunches($this->punch('1', self::DAY, '08:00:12'))->assertOk();

        $this->assertSame([$this->a->id . '|2026-11-02 08:00:12'], $this->storedPunches());
        $run = AttendanceImportRun::findOrFail($response->json('run_id'));
        $this->assertSame('done', $run->status);
        $this->assertNull($run->uploaded_by, 'an agent run has no uploader');
        $this->assertSame('agent', $run->stats_json['source']);
        $this->assertSame('office-pc', $run->stats_json['agent_id']);
        $this->assertNotNull($this->day($this->a, self::DAY));
        $this->assertSame($run->id, (int) $this->day($this->a, self::DAY)['source_run_id']);
        $this->assertSame(1, $response->json('inserted'));
        $this->assertSame(1, $response->json('days_computed'));
        $this->assertSame('2026-11-02 08:00:12', $response->json('last_punch_at'));
        $this->assertSame('2026-11-10T02:00:00.000Z', $response->json('server_time'));
    }

    #[TestDox('S-012.1 a four-punch day in one post gives 4 inserted and 1 day computed')]
    public function test_s_012_1_four_punch_day(): void
    {
        $lines = array_map(fn ($t) => $this->punch('1', self::DAY, $t), self::FULL_DAY);

        $response = $this->postPunches($lines)->assertOk();

        $this->assertSame(4, $response->json('inserted'));
        $this->assertSame(1, $response->json('days_computed'));
        $this->assertSame(0, $response->json('duplicates'));
        $day = $this->day($this->a, self::DAY);
        $this->assertSame(self::DAY . ' 08:00:00', $day['time_in']);
        $this->assertSame(self::DAY . ' 17:00:00', $day['time_out']);
    }

    #[TestDox('S-012.1 bad lines are counted invalid, never stored and never echoed back')]
    public function test_s_012_1_bad_lines_are_counted_not_stored_not_echoed(): void
    {
        $bad = [
            '1 2026-11-02 08:00:00',               // no tab
            "1\t2026-11-02 8:00",                  // bad date shape
            "1\t2026-13-02 08:00:00",              // month 13
            "1\tSECRET-MARKER-xyz",                // garbage
            "abc\t2026-11-02 09:00:00",            // junk zkteco id
            "1234567890\t2026-11-02 09:00:00",     // ten digits: junk
            "-5\t2026-11-02 09:00:00",             // junk
        ];
        $response = $this->postPunches(array_merge($bad, [$this->punch('1', self::DAY, '08:00:00')]))->assertOk();

        $this->assertSame(7, $response->json('invalid'));
        $this->assertSame(1, $response->json('inserted'));
        $this->assertSame(0, $response->json('held'), 'a junk id is refused, not held');
        $this->assertSame([$this->a->id . '|2026-11-02 08:00:00'], $this->storedPunches());
        $this->assertSame([], $this->feedRows('held'));
        $this->assertStringNotContainsString('SECRET-MARKER', $response->getContent());
        $this->assertStringNotContainsString('abc', $response->getContent());
    }

    #[TestDox('S-012.1 Windows line endings and extra device columns parse')]
    public function test_s_012_1_crlf_and_extra_columns(): void
    {
        $text = "1\t2026-11-02 08:00:00\t0\t1\t\t0\t0\r\n2\t2026-11-02 08:05:00\t1\t1\t\t0\t0\r\n";

        $response = $this->postPunches($text)->assertOk();

        $this->assertSame(2, $response->json('inserted'));
        $this->assertSame(0, $response->json('invalid'));
        $this->assertSame([
            $this->a->id . '|2026-11-02 08:00:00',
            $this->b->id . '|2026-11-02 08:05:00',
        ], $this->storedPunches());
    }

    #[TestDox('S-012.1 the result equals a manual upload of the same file')]
    public function test_s_012_1_same_result_as_a_manual_upload(): void
    {
        $lines = array_merge(
            array_map(fn ($t) => $this->punch('1', self::DAY, $t), self::FULL_DAY),
            array_map(fn ($t) => $this->punch('2', '2026-11-03', $t), ['08:20:00', '12:01:00', '12:58:00', '18:30:00']),
        );

        $this->process($this->makeRun($lines));
        $manual = [$this->storedPunches(), $this->dayValues()];

        DB::table('attendance_days')->delete();
        DB::table('attendance_logs')->delete();

        $this->postPunches($lines)->assertOk();
        $this->assertSame($manual, [$this->storedPunches(), $this->dayValues()]);
    }

    #[TestDox('S-012.1 times are Manila with no shift; a midnight edge stays on its own date')]
    public function test_s_012_1_midnight_edge_keeps_its_date(): void
    {
        $this->postPunches([
            $this->punch('1', self::DAY, '23:59:59'),
            $this->punch('2', '2026-11-03', '00:00:00'),
        ])->assertOk();

        $this->assertSame([
            $this->a->id . '|2026-11-02 23:59:59',
            $this->b->id . '|2026-11-03 00:00:00',
        ], $this->storedPunches());
    }

    #[TestDox('S-012.1 over 1,000 lines is refused with 422 and nothing is stored, never a silent partial')]
    public function test_s_012_1_over_1000_lines_is_refused_whole(): void
    {
        $lines = [];
        for ($i = 0; $i < 1001; $i++) {
            $lines[] = $this->punch('1', self::DAY, sprintf('%02d:%02d:00', intdiv($i, 60) % 24, $i % 60));
        }
        $before = $this->writeCounts();

        $this->postPunches($lines)->assertStatus(422);
        $this->assertSame($before, $this->writeCounts());

        // Exactly 1,000 is accepted.
        $this->postPunches(array_slice($lines, 0, 1000))->assertOk();
    }

    #[TestDox('S-012.1 a post with no attlog text is refused with 422 and writes nothing')]
    public function test_s_012_1_missing_attlog_is_422(): void
    {
        $before = $this->writeCounts();
        $this->postPunches('')->assertStatus(422);
        $this->call('POST', '/api/biometrics/punches', [], [], [], $this->headers(self::KEY), json_encode(['attlog' => ['x']]))
            ->assertStatus(422);
        $this->assertSame($before, $this->writeCounts());
    }

    /** The computed values of every day row, without ids and timestamps. */
    private function dayValues(): array
    {
        return DB::table('attendance_days')->orderBy('employee_id')->orderBy('work_date')->get()
            ->map(fn ($row) => collect((array) $row)->except(['id', 'source_run_id', 'created_at', 'updated_at'])->all())
            ->all();
    }
}
