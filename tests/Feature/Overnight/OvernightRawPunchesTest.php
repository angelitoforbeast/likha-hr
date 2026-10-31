<?php

namespace Tests\Feature\Overnight;

use App\Models\Employee;
use App\Models\Shift;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Process\Process;

/**
 * S-011: the raw punches of a date include the next date's punches inside its overnight window,
 * flagged as next day, for the Raw Punches modal and the punch pickers of the calendar.
 * The day of the cases: In 11:12 · Lunch out 16:00 · Lunch in 16:59 on D, Out 01:11:04 and a
 * shift start 11:56 on D+1, on an 11:00–20:00 shift on both dates.
 */
class OvernightRawPunchesTest extends OvernightTestCase
{
    protected Shift $eleven;
    protected Employee $x;

    protected function setUp(): void
    {
        parent::setUp();

        $this->eleven = Shift::create([
            'name'                  => 'Eleven to eight',
            'start_time'            => '11:00:00',
            'end_time'              => '20:00:00',
            'lunch_start'           => '16:00:00',
            'lunch_end'             => '17:00:00',
            'required_work_minutes' => 480,
        ]);
        $this->x = $this->warehouseEmployee('906', 'Eleven One');
        $this->x->update(['default_shift_id' => $this->eleven->id]);
        $this->x = $this->x->fresh();
    }

    private function rawPunches(string $date, ?Employee $employee = null): TestResponse
    {
        return $this->actingAs($this->c)
            ->getJson('/attendance/punches?employee_id=' . ($employee ?? $this->x)->id . '&date=' . $date)
            ->assertStatus(200);
    }

    /** The punches of case .1: three on D, then 01:11:04 and 11:56:00 on D+1. */
    private function caseOnePunches(): void
    {
        $this->punches($this->x, [
            self::D . ' 11:12:00', self::D . ' 16:00:00', self::D . ' 16:59:00',
            self::D1 . ' 01:11:04', self::D1 . ' 11:56:00',
        ]);
    }

    #[TestDox('S-011.1 the punches of D are its own three, then 01:11:04 of D+1 flagged next day; 11:56 is not included')]
    public function test_s_011_1_next_day_time_out_is_listed_after_the_date_own(): void
    {
        $this->caseOnePunches();

        $this->assertSame([
            'employee_name' => $this->x->display_name,
            'date'    => self::D,
            'count'   => 4,
            'punches' => [
                ['time' => '11:12:00', 'time_display' => '11:12:00 AM'],
                ['time' => '16:00:00', 'time_display' => '4:00:00 PM'],
                ['time' => '16:59:00', 'time_display' => '4:59:00 PM'],
                ['time' => '01:11:04', 'time_display' => '1:11:04 AM', 'next_day' => true, 'date' => self::D1],
            ],
        ], $this->rawPunches(self::D)->json());
    }

    #[TestDox('S-011.1 the modal renders "1:11:04 AM (next day)" and "4 punches total"; the time-out picker offers it (the page script run in node)')]
    public function test_s_011_1_modal_and_pickers_render_the_next_day_punch(): void
    {
        $this->caseOnePunches();
        $rendered = $this->renderWithPageScript($this->rawPunches(self::D)->json());

        $this->assertSame([
            '11:12:00  (11:12:00 AM)',
            '16:00:00  (4:00:00 PM)',
            '16:59:00  (4:59:00 PM)',
            '1:11:04 AM (next day)',
        ], $rendered['modal']);
        $this->assertSame('4 punches total.', $rendered['count']);
        $this->assertStringContainsString('<option value="01:11:04">1:11:04 AM (next day)</option>', $rendered['time_out']);
        // A time in or a lunch saved from it would be stored on D, so the other pickers only show it.
        foreach (['time_in', 'lunch_out', 'lunch_in'] as $field) {
            $this->assertStringContainsString('<option value="01:11:04" disabled>1:11:04 AM (next day)</option>', $rendered[$field], $field);
        }
        $this->assertStringContainsString('<option value="16:59:00">16:59:00</option>', $rendered['time_out']);
    }

    #[TestDox('S-011.2 window edges: 05:59 of D+1 is included and 06:00 is not')]
    public function test_s_011_2_until_edge(): void
    {
        $this->punches($this->x, [
            self::D . ' 11:12:00', self::D . ' 16:00:00', self::D . ' 16:59:00',
            self::D1 . ' 05:59:00', self::D1 . ' 06:00:00',
        ]);

        $this->assertSame(['11:12:00', '16:00:00', '16:59:00', '05:59:00'], array_column($this->rawPunches(self::D)->json('punches'), 'time'));
        $this->assertSame(4, $this->rawPunches(self::D)->json('count'));
    }

    #[TestDox('S-011.2 window edges: with a D+1 shift starting at 08:00 the window ends 05:00; 04:59 is included and 05:30 is not')]
    public function test_s_011_2_next_shift_edge(): void
    {
        $eight = Shift::create([
            'name' => 'Eight to five', 'start_time' => '08:00:00', 'end_time' => '17:00:00',
            'lunch_start' => '12:00:00', 'lunch_end' => '13:00:00', 'required_work_minutes' => 480,
        ]);
        $this->shiftOn($this->x, self::D1, $eight);
        $this->punches($this->x, [
            self::D . ' 11:12:00', self::D . ' 16:00:00', self::D . ' 16:59:00',
            self::D1 . ' 04:59:00', self::D1 . ' 05:30:00',
        ]);

        $punches = $this->rawPunches(self::D)->json('punches');
        $this->assertSame(['11:12:00', '16:00:00', '16:59:00', '04:59:00'], array_column($punches, 'time'));
        $this->assertSame(['next_day' => true, 'date' => self::D1], array_intersect_key($punches[3], ['next_day' => 1, 'date' => 1]));
    }

    #[TestDox('S-011.3 a normal day with no punch after midnight returns exactly the JSON of before')]
    public function test_s_011_3_normal_day_is_unchanged(): void
    {
        $this->punches($this->x, [
            self::D . ' 11:02:00', self::D . ' 16:00:00', self::D . ' 16:59:00', self::D . ' 20:03:00',
            self::D1 . ' 10:58:00', self::D1 . ' 20:01:00',
        ]);

        $this->assertSame(
            '{"employee_name":"' . $this->x->display_name . '","date":"' . self::D . '","count":4,"punches":['
            . '{"time":"11:02:00","time_display":"11:02:00 AM"},{"time":"16:00:00","time_display":"4:00:00 PM"},'
            . '{"time":"16:59:00","time_display":"4:59:00 PM"},{"time":"20:03:00","time_display":"8:03:00 PM"}]}',
            $this->rawPunches(self::D)->getContent()
        );
    }

    #[TestDox('S-011.4 the list of D+1 is unchanged: 01:11:04 is still its own first punch')]
    public function test_s_011_4_next_date_keeps_its_own_list(): void
    {
        $this->caseOnePunches();

        $this->assertSame([
            'employee_name' => $this->x->display_name,
            'date'    => self::D1,
            'count'   => 2,
            'punches' => [
                ['time' => '01:11:04', 'time_display' => '1:11:04 AM'],
                ['time' => '11:56:00', 'time_display' => '11:56:00 AM'],
            ],
        ], $this->rawPunches(self::D1)->json());
    }

    /** D computed before the D+1 punches came in, so it has no time out yet: what HR opens. */
    private function dayWithoutTimeOut(Employee $employee): void
    {
        $this->punches($employee, [self::D . ' 11:12:00', self::D . ' 16:00:00', self::D . ' 16:59:00']);
        $this->recompute(self::D, self::D);
        $this->assertNull($this->day($employee, self::D)['time_out']);
        $this->punches($employee, [self::D1 . ' 01:11:04', self::D1 . ' 11:56:00']);
    }

    /** The day as the compute makes it from the same punches on $shift (what the repair of spec 009 gives). */
    private function asComputed(Shift $shift): array
    {
        $other = $this->warehouseEmployee('907', 'Same Punches');
        $other->update(['default_shift_id' => $shift->id]);
        $this->punches($other, [
            self::D . ' 11:12:00', self::D . ' 16:00:00', self::D . ' 16:59:00',
            self::D1 . ' 01:11:04', self::D1 . ' 11:56:00',
        ]);
        $this->recompute(self::D, self::D1);

        return $this->metrics($other->fresh(), self::D);
    }

    /** Picks the 4th punch of D (the next-day one) and saves it as the time out, as $user. */
    private function pickAndSave(\App\Models\User $user, Employee $employee): TestResponse
    {
        $picked = $this->rawPunches(self::D, $employee)->json('punches.3');
        $this->assertSame(['time' => '01:11:04', 'time_display' => '1:11:04 AM', 'next_day' => true, 'date' => self::D1], $picked);

        // The picker puts the option's value, the time of day, into the time-out input; Save All sends it.
        return $this->saveAll($user, $employee, self::D, ['time_out' => $picked['time']])->assertStatus(200);
    }

    private static function withoutReview(array $m): array
    {
        return array_diff_key($m, ['review' => true]);
    }

    #[TestDox('S-011.5 the CEO picks "1:11:04 AM (next day)" as the time out and saves (11:00 shift): time out D+1 01:11:04, the day as the compute makes it (468: 12 m late)')]
    public function test_s_011_5_picked_next_day_time_out_saved_directly(): void
    {
        $this->dayWithoutTimeOut($this->x);
        $this->pickAndSave($this->c, $this->x)
            ->assertJson(['success' => true, 'values' => ['time_out' => ['raw' => '01:11:04']]]);

        $metrics = $this->metrics($this->x, self::D);
        $this->assertSame(self::D1 . ' 01:11:04', $metrics['time_out']);
        // In 11:12 on an 11:00 shift is 12 minutes late: 480 - 12. The compute gives the same.
        $this->assertSame(['late' => 12, 'undertime' => 0, 'payable' => 468], array_intersect_key($metrics, ['late' => 1, 'undertime' => 1, 'payable' => 1]));
        $this->assertSame(self::withoutReview($this->asComputed($this->eleven)), self::withoutReview($metrics));
    }

    #[TestDox('S-011.5 the same pick on the live day\'s 12:00 shift: time out D+1 01:11:04 and payable 480 (8 h), as the repair made it')]
    public function test_s_011_5_picked_next_day_time_out_is_a_full_day_on_the_live_shift(): void
    {
        $this->dayWithoutTimeOut($this->w);
        $this->pickAndSave($this->c, $this->w)->assertJson(['success' => true]);

        $metrics = $this->metrics($this->w, self::D);
        $this->assertSame(self::D1 . ' 01:11:04', $metrics['time_out']);
        $this->assertSame(480, $metrics['work']);
        $this->assertSame(480, $metrics['payable']);
        $this->assertSame(self::withoutReview($this->asComputed($this->warehouse)), self::withoutReview($metrics));
    }

    #[TestDox('S-011.5 HR picks the next-day time out: the request, once approved, stores time out D+1 01:11:04, the day as the compute makes it')]
    public function test_s_011_5_picked_next_day_time_out_requested_and_approved(): void
    {
        $this->dayWithoutTimeOut($this->w);
        $this->pickAndSave($this->h, $this->w)->assertJson(['pending' => true]);
        $this->approve($this->c, $this->w, self::D)->assertStatus(200);

        $metrics = $this->metrics($this->w, self::D);
        $this->assertSame(self::D1 . ' 01:11:04', $metrics['time_out']);
        $this->assertSame(480, $metrics['payable']);
        $this->assertSame($this->asComputed($this->warehouse), $metrics);
    }

    #[TestDox('S-011.5 a day with no time in yet (no day row): the time-out picker shows the next-day punch but it cannot be picked, since the save would keep it on D')]
    public function test_s_011_5_next_day_time_out_cannot_be_picked_without_a_time_in(): void
    {
        $this->punches($this->w, [self::D . ' 11:12:00', self::D . ' 16:00:00', self::D . ' 16:59:00']);
        $this->assertNull($this->day($this->w, self::D));
        $this->punches($this->w, [self::D1 . ' 01:11:04', self::D1 . ' 11:56:00']);

        $rendered = $this->renderWithPageScript($this->rawPunches(self::D, $this->w)->json(), false);
        $this->assertStringContainsString('<option value="01:11:04" disabled>1:11:04 AM (next day)</option>', $rendered['time_out']);
        $this->assertStringContainsString('<option value="11:12:00">11:12:00</option>', $rendered['time_out']);

        // Why: with no time in to be before, the saved 01:11:04 stays on D (the rule of spec 009).
        $this->saveAll($this->c, $this->w, self::D, ['time_out' => '01:11:04'])->assertStatus(200);
        $this->assertSame(self::D . ' 01:11:04', $this->day($this->w, self::D)['time_out']);
    }

    #[TestDox('S-011.5 when the day already has the next-day time out, saving the same pick changes nothing')]
    public function test_s_011_5_same_pick_is_no_change(): void
    {
        $this->caseOnePunches();
        $this->recompute(self::D, self::D1);
        $this->assertSame(self::D1 . ' 01:11:04', $this->day($this->x, self::D)['time_out']);

        $this->saveAll($this->c, $this->x, self::D, ['time_out' => '01:11:04'])
            ->assertStatus(200)->assertJson(['message' => 'No changes to save.']);
        $this->assertSame(self::D1 . ' 01:11:04', $this->day($this->x, self::D)['time_out']);
    }

    #[TestDox('S-011.6 the first day of the employee records, and a D+1 with no punches: no error, no extra rows')]
    public function test_s_011_6_first_day_and_empty_next_day(): void
    {
        $this->punches($this->x, [self::D . ' 11:12:00', self::D . ' 16:00:00', self::D . ' 16:59:00']);

        $this->assertSame(3, $this->rawPunches(self::D)->json('count'));
        $this->assertSame(['11:12:00', '16:00:00', '16:59:00'], array_column($this->rawPunches(self::D)->json('punches'), 'time'));
        $this->assertSame(['count' => 0, 'punches' => []], array_intersect_key($this->rawPunches(self::D1)->json(), ['count' => 1, 'punches' => 1]));
        // The day before the first record: nothing of its own, so nothing of the next date either.
        $this->assertSame(['count' => 0, 'punches' => []], array_intersect_key($this->rawPunches('2026-10-11')->json(), ['count' => 1, 'punches' => 1]));
    }

    #[TestDox('S-011.6 a date with no punches of its own lists none of the next date\'s early punches')]
    public function test_s_011_6_empty_date_lists_nothing_of_the_next_date(): void
    {
        $this->punches($this->x, [self::D1 . ' 01:11:04', self::D1 . ' 11:56:00']);

        $this->assertSame(['count' => 0, 'punches' => []], array_intersect_key($this->rawPunches(self::D)->json(), ['count' => 1, 'punches' => 1]));
    }

    #[TestDox('S-011.6 a date whose only punch is the time out of the date before lists no next-day punch, so none can be saved as its time out')]
    public function test_s_011_6_date_whose_punches_were_taken_lists_no_next_day_punch(): void
    {
        $before = '2026-10-11';
        $this->punches($this->x, [
            $before . ' 11:12:00', $before . ' 16:00:00', $before . ' 16:59:00',
            self::D . ' 01:11:04',
            self::D1 . ' 02:00:00', self::D1 . ' 11:56:00',
        ]);

        // D's own raw punch is still listed; the 02:00 of D+1 is not offered as D's time out.
        $this->assertSame([
            'employee_name' => $this->x->display_name,
            'date'    => self::D,
            'count'   => 1,
            'punches' => [['time' => '01:11:04', 'time_display' => '1:11:04 AM']],
        ], $this->rawPunches(self::D)->json());
        // The date before lists that 01:11:04 as its next-day time out.
        $this->assertSame(['time' => '01:11:04', 'time_display' => '1:11:04 AM', 'next_day' => true, 'date' => self::D],
            $this->rawPunches($before)->json('punches.3'));
    }

    /**
     * Runs the calendar page's own punch rendering (between the raw-punch-render markers) in node
     * on $data, the endpoint's JSON: the modal lines, its count line, and each picker's options
     * for a day that has ($hasTimeIn) or has not yet a stored time in.
     */
    private function renderWithPageScript(array $data, bool $hasTimeIn = true): array
    {
        $node = (new \Symfony\Component\Process\ExecutableFinder())->find('node');
        if (!$node) {
            $this->markTestSkipped('node is not installed: the page script cannot be run');
        }

        $html = $this->calendar($this->c);
        $this->assertSame(1, preg_match('#/\* raw-punch-render \*/(.*?)/\* /raw-punch-render \*/#s', $html, $m), 'the render functions are on the page');

        $script = $m[1] . "\nconst HAS_TIME_IN = " . ($hasTimeIn ? 'true' : 'false') . ";\n" . <<<'JS'
            const data = JSON.parse(require('fs').readFileSync(0, 'utf8'));
            const out = {
                modal: data.punches.map(p => rawPunchText(p)),
                count: `${data.count} punches total.`,
            };
            ['time_in', 'lunch_out', 'lunch_in', 'time_out'].forEach(f => { out[f] = punchPickerOptions(data.punches, f, HAS_TIME_IN); });
            process.stdout.write(JSON.stringify(out));
            JS;
        $base = tempnam(sys_get_temp_dir(), 'punches');
        $file = $base . '.js';
        file_put_contents($file, $script);
        try {
            $process = new Process([$node, $file]);
            $process->setInput(json_encode($data));
            $process->mustRun();
        } finally {
            @unlink($file);
            @unlink($base);
        }

        return json_decode($process->getOutput(), true);
    }
}
