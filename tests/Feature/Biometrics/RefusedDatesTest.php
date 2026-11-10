<?php

namespace Tests\Feature\Biometrics;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * S-012.6 (server side) A punch from a device whose clock reset is refused and counted.
 */
class RefusedDatesTest extends BiometricsTestCase
{
    #[TestDox('S-012.6 year 2000, before 2020-01-01, and more than one day ahead are refused: never stored, never computed, counted')]
    public function test_s_012_6_reset_clock_punches_are_refused_and_counted(): void
    {
        // Now is 2026-11-10 10:00 Manila.
        $response = $this->postPunches([
            $this->punch('1', '2000-01-01', '00:05:00'),
            $this->punch('1', '2019-12-31', '23:59:59'),
            $this->punch('1', '2026-11-12', '08:00:00'),   // tomorrow + 1
            $this->punch('1', '2026-11-11', '10:00:01'),   // one second past one day ahead
            $this->punch('54', '2000-01-01', '00:06:00'),  // refused, not held
            $this->punch('1', '2020-01-01', '00:00:00'),   // the first accepted moment
            $this->punch('2', '2026-11-11', '10:00:00'),   // exactly one day ahead: accepted
        ])->assertOk();

        $this->assertSame(5, $response->json('refused'));
        $this->assertSame(2, $response->json('inserted'));
        $this->assertSame(0, $response->json('held'));
        $this->assertSame([
            $this->a->id . '|2020-01-01 00:00:00',
            $this->b->id . '|2026-11-11 10:00:00',
        ], $this->storedPunches());
        $this->assertNull($this->day($this->a, '2000-01-01'));
        $this->assertNull($this->day($this->a, '2026-11-12'));
        $this->assertSame(5, $this->feedStatus()->json('refused_count'));
    }

    #[TestDox('S-012.6 a punch refused as too far ahead stays refused when its date comes near: never stored later')]
    public function test_s_012_6_refused_future_punch_stays_refused(): void
    {
        // The device clock jumped a month ahead; the punch is posted every minute until cleared.
        $line = $this->punch('1', '2026-12-10', '08:00:00');
        $this->postPunches($line)->assertOk()->assertJsonPath('refused', 1);

        $this->travel(30)->days();
        $again = $this->postPunches($line)->assertOk();

        $this->assertSame(1, $again->json('refused'));
        $this->assertSame(0, $again->json('inserted'));
        $this->assertSame(0, DB::table('attendance_logs')->count());
        $this->assertNull($this->day($this->a, '2026-12-10'));
    }

    #[TestDox('S-012.6 the same refused punch posted every minute is counted once')]
    public function test_s_012_6_refused_punch_is_counted_once(): void
    {
        $line = $this->punch('1', '2000-01-01', '00:05:00');
        $this->postPunches($line)->assertOk();
        $second = $this->postPunches($line)->assertOk();

        $this->assertSame(1, $second->json('refused'), 'the response counts what this post refused');
        $this->assertSame(1, $this->feedStatus()->json('refused_count'), 'the page counts each refused punch once');
        $this->assertSame(0, DB::table('attendance_logs')->count());
    }

    #[TestDox('S-012.6 the Import page shows the refused-date count')]
    public function test_s_012_6_import_page_shows_refused_count(): void
    {
        $this->postPunches([$this->punch('1', '2000-01-01', '00:05:00'), $this->punch('2', '2000-01-01', '00:07:00')])->assertOk();

        $html = $this->actingAs($this->uploader)->get('/import')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/id="feed-refused"[^>]*>\s*2\b/', $html);
    }

    #[TestDox('S-012.6 a manual upload keeps its own date window from the preview: the date check is the agent\'s')]
    public function test_s_012_6_manual_upload_keeps_its_window(): void
    {
        $run = $this->process($this->makeRun([
            $this->line('1', '2000-01-01', '00:05:00'),
            $this->line('1', self::D1, '08:00:00'),
        ]));

        $this->assertSame('done', $run->status);
        $this->assertSame(0, $run->stats_json['refused_dates']);
        $this->assertSame(2, $run->stats_json['total_logs_inserted']);
    }

    #[TestDox('S-012.6 held punches are checked too: a held punch is never applied on a refused date')]
    public function test_s_012_6_held_application_checks_dates(): void
    {
        $this->postPunches($this->punch('54', '2026-11-02', '08:00:00'))->assertOk();
        // A held row that is not in range any more (written by hand, say) is refused, not stored.
        DB::table('biometric_feed')->insert(['kind' => 'held', 'key' => '54', 'value' => '2019-05-05 08:00:00',
            'created_at' => now(), 'updated_at' => now()]);

        $hire = \App\Models\Employee::create(['zkteco_id' => '54', 'full_name' => 'New Hire', 'status' => 'active',
            'default_shift_id' => $this->shift->id]);

        $this->assertSame([$hire->id . '|2026-11-02 08:00:00'], array_values(array_filter(
            $this->storedPunches(), fn ($p) => str_starts_with($p, $hire->id . '|'))));
    }
}
