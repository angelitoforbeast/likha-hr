<?php

namespace Tests\Feature\Approvals;

use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Slice 006: beside each pending entry the CEO sees the punches the device stored
 * for that employee on that date, and which of them the day uses now.
 */
class ApprovalPunchesTest extends ApprovalsTestCase
{
    /* ── Helpers ── */

    private function page(): string
    {
        return $this->actingAs($this->c)->get('/approvals')->assertStatus(200)->getContent();
    }

    /** The HTML of one entry card on the page. */
    private function card(string $html, Employee $employee, string $date): string
    {
        $anchor = $this->anchorId($employee, $date);
        $start = strpos($html, 'data-anchor="' . $anchor . '"');
        $this->assertNotFalse($start, "no entry for employee {$employee->id} on {$date}");
        $end = strpos($html, 'class="approval-entry card', $start + 1);
        if ($end === false) {
            $end = strpos($html, 'id="approvalListEnd"', $start);
        }

        return substr($html, $start, $end - $start);
    }

    /** The punch block of one entry. */
    private function block(string $html, Employee $employee, string $date): string
    {
        $card = $this->card($html, $employee, $date);
        $this->assertMatchesRegularExpression('/<div class="approval-punches[^"]*"/', $card, 'the entry shows no device punches');
        preg_match('/<div class="approval-punches[^"]*".*?<!-- \/approval-punches -->/s', $card, $m);

        return $m[0];
    }

    private function text(string $html): string
    {
        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html))));
    }

    /** The punch lines of one entry as shown, e.g. "8:03 AM · Time in". */
    private function punches(string $html, Employee $employee, string $date): array
    {
        preg_match_all('/<li class="approval-punch"[^>]*>(.*?)<\/li>/s', $this->block($html, $employee, $date), $m);

        return array_map(fn ($line) => $this->text($line), $m[1]);
    }

    /** The muted next-day line of one entry, or null. */
    private function nextDay(string $html, Employee $employee, string $date): ?string
    {
        if (!preg_match('/<div class="[^"]*approval-punches-next[^"]*">(.*?)<\/div>/s', $this->block($html, $employee, $date), $m)) {
            return null;
        }

        return $this->text($m[1]);
    }

    /** A punch as an import stores it, without recomputing the day. */
    private function log(Employee $employee, string $at): void
    {
        DB::table('attendance_logs')->insert([
            'employee_id'   => $employee->id,
            'punched_at'    => $at,
            'source_run_id' => $this->importRunId(),
            'created_at'    => '2026-10-01 09:00:00',
            'updated_at'    => '2026-10-01 09:00:00',
        ]);
    }

    /** A pending lunch-in fix on E1/Dr, whose day row comes from 8:03, 12:00, 13:00, 17:00. */
    private function askLunchFixOnDr(): void
    {
        $this->saveAll($this->h, $this->e1, self::DR, ['lunch_in' => '13:30'])->assertStatus(200)->assertJson(['pending' => true]);
    }

    /** The preview card of one entry, as shown. */
    private function previewCard(string $html, Employee $employee, string $date): string
    {
        $card = $this->card($html, $employee, $date);
        $this->assertSame(1, preg_match('/<div class="approval-preview .*?(?=<div class="approval-actions)/s', $card, $m));

        return $m[0];
    }

    private function everything(): array
    {
        $tables = collect(DB::select("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name"))->pluck('name');
        $out = [];
        foreach ($tables as $table) {
            $out[$table] = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        }

        return $out;
    }

    /* ── S-006.1 ── */

    public function test_s_006_1_shows_every_punch_of_the_date_in_time_order_in_12_hour_format(): void
    {
        // Stored out of order, with seconds, at both edges of the calendar day and just outside it.
        $this->log($this->e1, '2026-10-05 18:42:51');
        $this->log($this->e1, '2026-10-05 00:00:00');
        $this->log($this->e1, '2026-10-05 23:59:59');
        $this->log($this->e1, '2026-10-04 23:59:59');
        $this->askLunchFixOnDr();

        $punches = $this->punches($this->page(), $this->e1, self::DR);

        $this->assertSame([
            '12:00 AM',
            '8:03 AM · Time in',
            '12:00 PM · Lunch out',
            '1:00 PM · Lunch in',
            '5:00 PM · Time out',
            '6:42 PM',
            '11:59 PM',
        ], $punches);
    }

    /* ── S-006.2 ── */

    public function test_s_006_2_a_punch_stored_twice_is_listed_once(): void
    {
        $this->log($this->e1, '2026-10-05 08:03:00');
        $this->log($this->e1, '2026-10-05 15:10:00');
        $this->log($this->e1, '2026-10-05 15:10:00');
        $this->askLunchFixOnDr();

        $punches = $this->punches($this->page(), $this->e1, self::DR);

        $this->assertSame(['8:03 AM · Time in', '12:00 PM · Lunch out', '1:00 PM · Lunch in', '3:10 PM', '5:00 PM · Time out'], $punches);
    }

    /* ── S-006.3 ── */

    public function test_s_006_3_early_next_day_punches_are_on_their_own_line_and_change_nothing(): void
    {
        $this->askLunchFixOnDr();
        $before = $this->page();
        $dayBefore = (array) DB::table('attendance_days')->where('employee_id', $this->e1->id)->where('work_date', self::DR)->first();

        $this->log($this->e1, '2026-10-06 01:11:00');
        $this->log($this->e1, '2026-10-06 05:59:59');
        $this->log($this->e1, '2026-10-06 06:00:00');
        $this->log($this->e1, '2026-10-06 06:30:00');
        $after = $this->page();

        $this->assertSame('Next day: 1:11 AM, 5:59 AM', $this->nextDay($after, $this->e1, self::DR));
        // Only shown: the day's own list, the preview and the day row stay as they were.
        $this->assertSame($this->punches($before, $this->e1, self::DR), $this->punches($after, $this->e1, self::DR));
        $this->assertSame($this->previewCard($before, $this->e1, self::DR), $this->previewCard($after, $this->e1, self::DR));
        $this->assertSame($dayBefore, (array) DB::table('attendance_days')->where('employee_id', $this->e1->id)->where('work_date', self::DR)->first());
        $this->assertNull($this->nextDay($before, $this->e1, self::DR));
    }

    /* ── S-006.4 ── */

    public function test_s_006_4_a_date_without_punches_says_so(): void
    {
        $this->askFourTimes();

        $html = $this->page();

        $this->assertSame([], $this->punches($html, $this->e1, self::DN));
        $this->assertStringContainsString('No punches from the device on this date.', $this->text($this->block($html, $this->e1, self::DN)));
        $this->assertNull($this->nextDay($html, $this->e1, self::DN));
    }

    public function test_s_006_4_a_date_without_punches_still_shows_the_next_day_line(): void
    {
        $this->askFourTimes();
        $this->log($this->e1, '2026-10-07 02:05:00');

        $html = $this->page();

        $this->assertStringContainsString('No punches from the device on this date.', $this->text($this->block($html, $this->e1, self::DN)));
        $this->assertSame('Next day: 2:05 AM', $this->nextDay($html, $this->e1, self::DN));
    }

    /* ── S-006.5 ── */

    public function test_s_006_5_the_punches_the_day_uses_now_carry_their_label(): void
    {
        $this->log($this->e1, '2026-10-05 10:15:00');
        // A punch in the same minute as the official time in, with other seconds, is that time in too.
        $this->log($this->e1, '2026-10-05 08:03:40');
        $this->askLunchFixOnDr();

        $punches = $this->punches($this->page(), $this->e1, self::DR);

        $this->assertSame([
            '8:03 AM · Time in',
            '8:03 AM · Time in',
            '10:15 AM',
            '12:00 PM · Lunch out',
            '1:00 PM · Lunch in',
            '5:00 PM · Time out',
        ], $punches);
    }

    public function test_s_006_5_labels_follow_the_official_times_now_not_the_punches(): void
    {
        // The official time out now is 5:30 PM (an approved override), so the 5:00 PM punch is unused.
        DB::table('attendance_days')->where('employee_id', $this->e1->id)->where('work_date', self::DR)
            ->update(['time_out' => '2026-10-05 17:30:00', 'lunch_out' => null]);
        $this->askLunchFixOnDr();

        $punches = $this->punches($this->page(), $this->e1, self::DR);

        $this->assertSame(['8:03 AM · Time in', '12:00 PM', '1:00 PM · Lunch in', '5:00 PM'], $punches);
    }

    /* ── S-006.6 ── */

    public function test_s_006_6_another_employees_punches_never_appear(): void
    {
        $this->punchedDay($this->e2, self::DR, ['07:55:00', '12:05:00', '12:58:00', '17:20:00']);
        $this->log($this->e2, '2026-10-06 01:30:00');
        $this->askLunchFixOnDr();
        $this->saveAll($this->h, $this->e2, self::DR, ['lunch_in' => '13:30'])->assertStatus(200)->assertJson(['pending' => true]);

        $html = $this->page();

        $this->assertSame(['8:03 AM · Time in', '12:00 PM · Lunch out', '1:00 PM · Lunch in', '5:00 PM · Time out'], $this->punches($html, $this->e1, self::DR));
        $this->assertNull($this->nextDay($html, $this->e1, self::DR));
        $this->assertSame(['7:55 AM · Time in', '12:05 PM · Lunch out', '12:58 PM · Lunch in', '5:20 PM · Time out'], $this->punches($html, $this->e2, self::DR));
        $this->assertSame('Next day: 1:30 AM', $this->nextDay($html, $this->e2, self::DR));
    }

    public function test_s_006_6_entries_of_one_employee_on_consecutive_dates_each_get_their_own_punches(): void
    {
        $this->askLunchFixOnDr();
        $this->askFourTimes();
        // 1:00 AM on Dn: the next-day line of Dr and the first punch of Dn itself.
        $this->log($this->e1, '2026-10-06 01:00:00');
        $this->log($this->e1, '2026-10-06 09:00:00');

        $html = $this->page();

        $this->assertSame('Next day: 1:00 AM', $this->nextDay($html, $this->e1, self::DR));
        $this->assertSame(['8:03 AM · Time in', '12:00 PM · Lunch out', '1:00 PM · Lunch in', '5:00 PM · Time out'], $this->punches($html, $this->e1, self::DR));
        $this->assertSame(['1:00 AM', '9:00 AM'], $this->punches($html, $this->e1, self::DN));
        $this->assertNull($this->nextDay($html, $this->e1, self::DN));
    }

    /* ── S-006.7 ── */

    public function test_s_006_7_rendering_the_punches_writes_nothing(): void
    {
        $this->log($this->e1, '2026-10-05 08:03:00');
        $this->log($this->e1, '2026-10-06 01:11:00');
        $this->askLunchFixOnDr();
        $this->askFourTimes();

        $this->travel(2)->hours();
        Queue::fake();
        $before = $this->everything();

        $html = $this->page();
        $html .= $this->page();

        $this->assertSame($before, $this->everything(), 'a table changed while the page was rendered');
        Queue::assertNothingPushed();
        $this->assertSame(4, substr_count($html, '<div class="approval-punches'));
    }

    /** The queries that read punches for the block, during one page load. */
    private function punchQueries(): int
    {
        $this->counted = 0;
        if (!$this->listening) {
            $this->listening = true;
            DB::listen(function ($query) {
                if ($this->counting && str_contains($query->sql, 'attendance_logs')) {
                    $this->counted++;
                }
            });
        }
        $this->counting = true;
        $this->lastPage = $this->page();
        $this->counting = false;

        return $this->counted;
    }

    private string $lastPage = '';
    private bool $listening = false;
    private bool $counting = false;
    private int $counted = 0;

    public function test_s_006_7_the_punch_queries_do_not_grow_with_the_groups(): void
    {
        $this->askLunchFixOnDr();
        $one = $this->punchQueries();
        $this->assertSame(1, substr_count($this->lastPage, '<div class="approval-punches'));

        // 30 groups: two employees on fifteen open dates, each with punches.
        $dates = collect(range(1, 20))
            ->map(fn ($d) => sprintf('2026-10-%02d', $d))
            ->reject(fn ($d) => in_array($d, [self::DR, self::DL], true))
            ->take(15);
        $this->assertCount(15, $dates);
        foreach ($dates as $date) {
            foreach ([$this->e1, $this->e2] as $employee) {
                $this->log($employee, "{$date} 08:00:00");
                $this->saveAll($this->h, $employee, $date, ['time_out' => '17:00'])->assertStatus(200)->assertJson(['pending' => true]);
            }
        }
        $thirtyOne = $this->punchQueries();
        $this->assertSame(31, substr_count($this->lastPage, '<div class="approval-punches'));

        $this->assertSame(1, $one);
        $this->assertSame(1, $thirtyOne);
    }

    /* ── S-006.8 ── */

    public function test_s_006_8_the_block_sits_below_the_request_and_never_widens_the_page(): void
    {
        $this->askLunchFixOnDr();

        $html = $this->page();
        $card = $this->card($html, $this->e1, self::DR);

        // In the page's order (a phone shows them one under the other): request, punches, preview, buttons.
        $request = strpos($card, 'class="approval-request"');
        $side = strpos($card, 'class="approval-side"');
        $punches = strpos($card, '<div class="approval-punches');
        $preview = strpos($card, '<div class="approval-preview ');
        $actions = strpos($card, 'approval-actions');
        $this->assertTrue($request < $side && $side < $punches && $punches < $preview && $preview < $actions);

        // The block's own styles let it wrap and set no width.
        preg_match_all('/\.approval-punch[^{]*\{([^}]*)\}/', $html, $rules);
        $this->assertNotEmpty($rules[1]);
        foreach ($rules[1] as $rule) {
            $this->assertDoesNotMatchRegularExpression('/(^|[;\s])(min-)?width\s*:|white-space\s*:\s*nowrap/', $rule);
        }
    }

    public function test_decided_entries_have_no_punch_block(): void
    {
        $this->askFourTimes();
        $this->approve($this->c, $this->e1, self::DN)->assertStatus(200);

        $this->actingAs($this->c)->get('/approvals?status=approved')->assertStatus(200)->assertDontSee('approval-punches', false);
    }
}
