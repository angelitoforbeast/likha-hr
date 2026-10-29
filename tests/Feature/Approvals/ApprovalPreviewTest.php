<?php

namespace Tests\Feature\Approvals;

use App\Models\AttendanceChangeRequest;
use App\Models\AttendanceDay;
use App\Models\Employee;
use App\Services\AttendanceApprovalService;
use App\Services\AttendanceComputeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Slice 005: beside each entry the CEO sees the day now and as Approve would save it.
 * The preview runs the approval's own code and rolls it back.
 */
class ApprovalPreviewTest extends ApprovalsTestCase
{
    /** More open weekdays of the pay period. */
    private const D9  = '2026-10-09';
    private const D12 = '2026-10-12';
    private const D13 = '2026-10-13';

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

    /**
     * The preview of one entry as shown: label => [now, if approved, highlighted].
     *
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    private function shown(string $html, Employee $employee, string $date): array
    {
        $card = $this->card($html, $employee, $date);
        preg_match_all(
            '/<div role="row" data-line="([^"]+)"\s*class="([^"]*)"\s*>\s*<div role="rowheader"[^>]*>.*?<\/div>\s*<div role="cell">(.*?)<\/div>\s*<div role="cell">(.*?)<\/div>/s',
            $card,
            $m,
            PREG_SET_ORDER
        );
        $this->assertNotEmpty($m, 'the entry shows no preview');
        $lines = [];
        foreach ($m as $line) {
            $lines[html_entity_decode($line[1])] = [
                trim(html_entity_decode($line[3])),
                trim(html_entity_decode($line[4])),
                str_contains($line[2] ?? '', 'approval-preview-changed'),
            ];
        }

        return $lines;
    }

    /** The warning lines of an entry's preview. */
    private function warnings(string $html, Employee $employee, string $date): array
    {
        $card = $this->card($html, $employee, $date);
        if (!preg_match('/<ul class="[^"]*approval-preview-warnings">(.*?)<\/ul>/s', $card, $m)) {
            return [];
        }
        preg_match_all('/<li>(.*?)<\/li>/s', $m[1], $items);

        return array_map(fn ($item) => trim(html_entity_decode($item)), $items[1]);
    }

    /** The preview the page computes for an entry, from the service the page uses. */
    private function preview(Employee $employee, string $date): array
    {
        $rows = app(AttendanceApprovalService::class)->entryRows($employee->id, $date);

        return app(AttendanceApprovalService::class)->preview(Employee::find($employee->id), $date, $rows, $this->c);
    }

    /** Every row of every table, to prove a page load wrote nothing. */
    private function everything(): array
    {
        $tables = collect(DB::select("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name"))->pluck('name');
        $out = [];
        foreach ($tables as $table) {
            $out[$table] = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        }

        return $out;
    }

    private function askOvertime(Employee $employee, string $date, string $hours): void
    {
        $this->saveAll($this->h, $employee, $date, ['approved_ot_hours' => $hours])->assertStatus(200)->assertJson(['pending' => true]);
    }

    /** A shift for one date, asked from the calendar's own shift control. */
    private function askShiftFromCalendar(Employee $employee, string $date): void
    {
        $this->actingAs($this->h)->postJson('/attendance-calendar/assign-shift', [
            'employee_id' => $employee->id, 'date' => $date, 'shift_id' => $this->otherShift->id, 'reason' => 'Lumipat ng shift',
        ])->assertStatus(200)->assertJson(['pending' => true]);
    }

    /* ── S-005.1 ── */

    public function test_s_005_1_missed_timeout_shows_the_completed_day(): void
    {
        $this->punchedDay($this->e1, self::DX, ['08:00:00', '12:00:00', '13:00:00']);
        $this->assertEquals(1, $this->day($this->e1, self::DX)['needs_review']);
        $this->saveAll($this->h, $this->e1, self::DX, ['time_out' => '17:00'])->assertStatus(200);

        $html = $this->page();
        $shown = $this->shown($html, $this->e1, self::DX);

        $this->assertSame(['8:00 AM', '8:00 AM', false], $shown['Time in']);
        $this->assertSame(['12:00 PM', '12:00 PM', false], $shown['Lunch out']);
        $this->assertSame(['1:00 PM', '1:00 PM', false], $shown['Lunch in']);
        $this->assertSame(['none', '5:00 PM', true], $shown['Time out']);
        $this->assertSame(['1 h', '1 h', false], $shown['Lunch']);
        $this->assertSame(['4 h', '8 h', true], $shown['Worked']);
        $this->assertSame(['Yes', 'No', true], $shown['Needs review']);
        $this->assertSame([], $this->warnings($html, $this->e1, self::DX));
        $card = html_entity_decode($this->card($html, $this->e1, self::DX));
        $this->assertStringContainsString('Note now: No Time Out recorded (missed timeout).', $card);
        $this->assertStringContainsString('Note if approved: Preserved overrides: time_out.', $card);
        $this->assertStringNotContainsString('Shift</th>', $this->card($html, $this->e1, self::DX), 'no shift line when no shift change is asked');
    }

    /* ── S-005.2 ── */

    public function test_s_005_2_lunch_fix_shows_the_new_lunch_length_and_hours(): void
    {
        // Back from lunch at 2:00 PM: an hour late and an hour short.
        $this->punchedDay($this->e1, self::DX, ['08:00:00', '12:00:00', '14:00:00', '17:00:00']);
        $this->saveAll($this->h, $this->e1, self::DX, ['lunch_in' => '13:00'])->assertStatus(200);

        $html = $this->page();
        $shown = $this->shown($html, $this->e1, self::DX);

        $this->assertSame(['2:00 PM', '1:00 PM', true], $shown['Lunch in']);
        $this->assertSame(['2 h', '1 h', true], $shown['Lunch']);
        $this->assertSame(['7 h', '8 h', true], $shown['Worked']);
        $this->assertSame(['1 h', '0 m', true], $shown['Late']);
        $this->assertSame([], $this->warnings($html, $this->e1, self::DX));
    }

    public function test_s_005_2_lunch_still_too_long_is_warned(): void
    {
        $this->punchedDay($this->e1, self::DX, ['08:00:00', '12:00:00', '13:00:00', '17:00:00']);
        // The shape of a real request: lunch moved to the evening, time out after it.
        $this->saveAll($this->h, $this->e1, self::DX, ['lunch_out' => '16:00', 'lunch_in' => '16:45', 'time_out' => '21:04'])->assertStatus(200);
        $this->punchedDay($this->e2, self::DX, ['08:00:00', '12:00:00', '13:00:00', '17:00:00']);
        $this->saveAll($this->h, $this->e2, self::DX, ['lunch_in' => '14:30'])->assertStatus(200);

        $html = $this->page();

        $this->assertSame(['1 h', '45 m', true], $this->shown($html, $this->e1, self::DX)['Lunch']);
        $this->assertContains('4 h 4 m of overtime is not covered by approved overtime.', $this->warnings($html, $this->e1, self::DX));
        $this->assertSame(['1 h', '2 h 30 m', true], $this->shown($html, $this->e2, self::DX)['Lunch']);
        $this->assertSame([
            "Lunch is 2 h 30 m, longer than the shift's 1 h.",
            "Worked 6 h 30 m, under the shift's 8 h.",
        ], $this->warnings($html, $this->e2, self::DX));
    }

    public function test_s_005_2_other_unusual_results_are_warned(): void
    {
        // Lunch in before lunch out, and a time out before the time in.
        $this->punchedDay($this->e1, self::DX, ['08:00:00', '12:00:00', '13:00:00', '17:00:00']);
        $this->saveAll($this->h, $this->e1, self::DX, ['lunch_in' => '11:00', 'time_out' => '07:00'])->assertStatus(200);
        // Only the lunch pair asked for: the time out is still missing.
        $this->punchedDay($this->e2, self::DX, ['08:00:00']);
        $this->saveAll($this->h, $this->e2, self::DX, ['lunch_out' => '12:00', 'lunch_in' => '13:00'])->assertStatus(200);

        $html = $this->page();

        $first = $this->warnings($html, $this->e1, self::DX);
        $this->assertContains('Lunch in is before lunch out.', $first);
        $this->assertContains('Time out is before time in.', $first);
        $this->assertSame(['No', 'Yes', true], $this->shown($html, $this->e1, self::DX)['Needs review']);
        $this->assertSame(['Still missing: time out.', "Worked 4 h, under the shift's 8 h."], $this->warnings($html, $this->e2, self::DX));
    }

    /* ── S-005.3 ── */

    public function test_s_005_3_overtime_request_shows_the_approved_overtime(): void
    {
        $this->punchedDay($this->e1, self::DX, ['08:00:00', '12:00:00', '13:00:00', '19:00:00']);
        $this->askOvertime($this->e1, self::DX, '1.5');

        $html = $this->page();
        $shown = $this->shown($html, $this->e1, self::DX);

        $this->assertSame(['2 h', '2 h', false], $shown['Overtime worked']);
        $this->assertSame(['not set', '1 h 30 m', true], $shown['Overtime approved']);
        $this->assertSame(['30 m of overtime is not covered by approved overtime.'], $this->warnings($html, $this->e1, self::DX));

        // All of it approved: nothing left to warn about.
        $this->askOvertime($this->e1, self::DX, '2');
        $html = $this->page();
        $this->assertSame(['not set', '2 h', true], $this->shown($html, $this->e1, self::DX)['Overtime approved']);
        $this->assertSame([], $this->warnings($html, $this->e1, self::DX));
    }

    /* ── S-005.4 ── */

    public function test_s_005_4_shift_change_shows_the_new_shift_and_recomputed_late_and_undertime(): void
    {
        // Dr: 8:03 AM to 5:00 PM on the 8-to-5 shift. Asked: the 9-to-6 shift with lunch 1 to 2 PM.
        $this->actingAs($this->h)->postJson('/attendance-calendar/save-all', [
            'employee_id' => $this->e1->id, 'date' => self::DR, 'shift_id' => $this->otherShift->id, 'reason' => 'Lumipat ng shift',
        ])->assertStatus(200)->assertJson(['pending' => true]);

        $html = $this->page();
        $shown = $this->shown($html, $this->e1, self::DR);

        $this->assertSame(['Day shift', 'Late shift', true], $shown['Shift']);
        $this->assertSame(['3 m', '0 m', true], $shown['Late']);
        // Lunch out an hour before the new lunch, time out an hour before the new end.
        $this->assertSame(['0 m', '2 h', true], $shown['Undertime']);
        $this->assertSame(['7 h 57 m', '6 h', true], $shown['Worked']);
        $this->assertSame(['8:03 AM', '8:03 AM', false], $shown['Time in']);
        $this->assertSame(["Worked 6 h, under the shift's 8 h."], $this->warnings($html, $this->e1, self::DR));
    }

    /* ── S-005.5 ── */

    public function test_s_005_5_rendering_the_page_writes_nothing(): void
    {
        $this->silSetup($this->e2);
        // One entry of every kind, including the ones whose approval creates a day row,
        // logs a rest day, applies SIL and rebuilds the day after its commit.
        $this->punchedDay($this->e1, self::DX, ['08:00:00', '12:00:00', '13:00:00']);
        $this->saveAll($this->h, $this->e1, self::DX, ['time_out' => '17:00'])->assertStatus(200);
        $this->askFourTimes();
        $this->askOvertime($this->e1, self::DR, '1');
        $this->askShiftFromCalendar($this->e2, self::DR);
        $this->punchedDay($this->e2, self::D9, ['08:00:00', '12:00:00', '13:00:00', '17:00:00']);
        $this->toggleDayOff($this->h, $this->e2, self::D9, 'add_day_off')->assertStatus(200)->assertJson(['pending' => true]);
        $this->applySil($this->h, $this->e2, self::D12)->assertStatus(200)->assertJson(['pending' => true]);
        $this->punchedDay($this->e2, self::DX, ['08:00:00', '12:00:00', '13:00:00', '17:00:00']);
        $this->saveAll($this->h, $this->e2, self::DX, ['lunch_in' => '13:30'])->assertStatus(200);

        // Every timestamp written from here on would differ from the ones above.
        $this->travel(2)->hours();
        Queue::fake();
        $before = $this->everything();

        $html = $this->page();
        $html .= $this->page();

        $this->assertSame($before, $this->everything(), 'a table changed while the page was rendered');
        Queue::assertNothingPushed();
        // Every entry did get a preview, so the dry runs did write before rolling back.
        $this->assertSame(7, substr_count($html, 'data-preview="ok"') / 2);
        $this->assertStringNotContainsString('Preview not available', $html);
    }

    /* ── S-005.6 ── */

    public function test_s_005_6_preview_equals_the_day_after_approval(): void
    {
        $this->silSetup($this->e2);
        $cases = [];

        // Missed time out.
        $this->punchedDay($this->e1, self::DX, ['08:00:00', '12:00:00', '13:00:00']);
        $this->saveAll($this->h, $this->e1, self::DX, ['time_out' => '17:00'])->assertStatus(200);
        $cases['missed timeout'] = [$this->e1, self::DX];

        // Lunch fix.
        $this->punchedDay($this->e1, self::D9, ['08:00:00', '12:00:00', '14:00:00', '17:00:00']);
        $this->saveAll($this->h, $this->e1, self::D9, ['lunch_in' => '13:00'])->assertStatus(200);
        $cases['lunch fix'] = [$this->e1, self::D9];

        // Overtime.
        $this->punchedDay($this->e1, self::D12, ['08:00:00', '12:00:00', '13:00:00', '19:00:00']);
        $this->askOvertime($this->e1, self::D12, '2');
        $cases['overtime'] = [$this->e1, self::D12];

        // Shift change with times, from Save All.
        $this->actingAs($this->h)->postJson('/attendance-calendar/save-all', [
            'employee_id' => $this->e1->id, 'date' => self::DR, 'shift_id' => $this->otherShift->id,
            'time_out' => '18:00', 'reason' => 'Lumipat ng shift',
        ])->assertStatus(200);
        $cases['shift change'] = [$this->e1, self::DR];

        // Shift change from the calendar's shift control: its day is rebuilt after the commit.
        $this->punchedDay($this->e2, self::DR, ['08:03:00', '12:00:00', '13:00:00', '17:00:00']);
        $this->askShiftFromCalendar($this->e2, self::DR);
        $cases['shift change, rebuilt after commit'] = [$this->e2, self::DR];

        // A day created from nothing.
        $this->askFourTimes();
        $cases['new day'] = [$this->e1, self::DN];

        // A rest day on a worked day, and SIL on a day nobody worked.
        $this->punchedDay($this->e2, self::D9, ['08:00:00', '12:00:00', '13:00:00', '17:00:00']);
        $this->toggleDayOff($this->h, $this->e2, self::D9, 'add_day_off')->assertStatus(200);
        $cases['rest day'] = [$this->e2, self::D9];
        $this->applySil($this->h, $this->e2, self::D12)->assertStatus(200);
        $cases['sil'] = [$this->e2, self::D12];

        $previews = [];
        foreach ($cases as $name => [$employee, $date]) {
            $previews[$name] = $this->preview($employee, $date);
            $this->assertNull($previews[$name]['error'], "{$name}: " . $previews[$name]['error']);
        }
        $html = $this->page();

        foreach ($cases as $name => [$employee, $date]) {
            $this->approve($this->c, $employee, $date)->assertStatus(200);

            $after = $previews[$name]['after'];
            $saved = app(AttendanceApprovalService::class)->dayPicture(Employee::find($employee->id), $date);
            $this->assertSame($saved, $after, "{$name}: the preview differs from the approved day");

            // And read straight from the table, not through the code that made the preview.
            $row = $this->day($employee, $date);
            $time = fn ($value) => $value === null ? null : substr($value, 11, 8);
            $this->assertSame($row !== null, $after['has_row'], $name);
            if ($row) {
                $this->assertSame([
                    $time($row['time_in']), $time($row['lunch_out']), $time($row['lunch_in']), $time($row['time_out']),
                    (int) $row['shift_id'], (int) $row['computed_work_minutes'], (int) $row['computed_late_minutes'],
                    (int) $row['computed_early_minutes'], (int) $row['computed_overtime_minutes'],
                    $row['approved_overtime_minutes'] === null ? null : (int) $row['approved_overtime_minutes'],
                    (bool) $row['needs_review'], $row['notes'],
                ], [
                    $after['time_in'], $after['lunch_out'], $after['lunch_in'], $after['time_out'],
                    $after['shift_id'], $after['work'], $after['late'], $after['undertime'], $after['overtime'],
                    $after['approved_overtime'], $after['needs_review'], $after['notes'],
                ], "{$name}: the preview differs from the saved row");
            }

            // The page showed the same as the service.
            $shown = $this->shown($html, $employee, $date);
            $this->assertSame(AttendanceApprovalService::minutesText($after['work']), $shown['Worked'][1], $name);
        }

        // What the cases are about, from the cases themselves.
        $this->assertSame('17:00:00', $previews['missed timeout']['after']['time_out']);
        $this->assertFalse($previews['missed timeout']['after']['needs_review']);
        $this->assertSame(480, $previews['lunch fix']['after']['work']);
        $this->assertSame(120, $previews['overtime']['after']['approved_overtime']);
        $this->assertSame($this->otherShift->id, $previews['shift change']['after']['shift_id']);
        $this->assertSame($this->otherShift->id, $previews['shift change, rebuilt after commit']['after']['shift_id']);
        $this->assertTrue($previews['new day']['after']['has_row']);
        $this->assertTrue($previews['rest day']['after']['rest_day']);
        $this->assertTrue($previews['sil']['after']['sil']);
    }

    /* ── S-005.7 ── */

    public function test_s_005_7_preview_that_cannot_be_computed_says_so_and_can_still_be_approved(): void
    {
        $noShift = Employee::create(['zkteco_id' => '904', 'full_name' => 'Employee Four', 'status' => 'active']);
        $this->askFourTimes($this->h, $noShift);
        $this->askFourTimes();

        $html = $this->page();

        $card = $this->card($html, $noShift, self::DN);
        $this->assertStringContainsString('Preview not available: No shift is set for this employee on this date', $card);
        $this->assertStringContainsString('approval-approve', $card);
        $this->assertSame(1, substr_count($html, 'data-preview="ok"'), 'the other entry still has its preview');

        $this->approve($this->c, $noShift, self::DN)->assertStatus(200);
        $this->assertNotNull($this->day($noShift, self::DN));
    }

    public function test_s_005_7_an_error_in_the_preview_never_breaks_the_page(): void
    {
        $this->askFourTimes();
        $this->app->instance(AttendanceComputeService::class, new class extends AttendanceComputeService {
            public function recheckDay(AttendanceDay $day): AttendanceDay
            {
                throw new \RuntimeException('broken');
            }
        });
        $before = $this->everything();

        $html = $this->page();

        $this->assertStringContainsString('Preview not available: It could not be worked out.', $html);
        $this->assertStringContainsString('approval-approve', $html);
        $this->assertSame($before, $this->everything());
    }

    /* ── S-005.8 ── */

    public function test_s_005_8_preview_sits_between_the_request_and_the_buttons(): void
    {
        $this->askFourTimes();

        $html = $this->page();
        $card = $this->card($html, $this->e1, self::DN);

        // In the page's order (a phone shows them one under the other): request, preview, buttons.
        $request = strpos($card, 'class="approval-request"');
        $preview = strpos($card, 'class="approval-side"');
        $actions = strpos($card, 'approval-actions');
        $this->assertTrue($request < $preview && $preview < $actions);
        // One column below the laptop width, two from it; the preview's columns never widen the page.
        $this->assertMatchesRegularExpression('/\.approval-layout \{ display: grid; grid-template-columns: minmax\(0, 1fr\);/', $html);
        $this->assertMatchesRegularExpression('/@media \(min-width: 992px\) \{\s*\.approval-layout\.has-preview \{\s*grid-template-columns: minmax\(0, 7fr\) minmax\(0, 5fr\);/', $html);
        $this->assertStringContainsString('.approval-preview-grid { display: grid; grid-template-columns: minmax(0, 38fr) minmax(0, 31fr) minmax(0, 31fr);', $html);
    }

    public function test_decided_entries_have_no_preview(): void
    {
        $this->askFourTimes();
        $this->approve($this->c, $this->e1, self::DN)->assertStatus(200);

        $this->actingAs($this->c)->get('/approvals?status=approved')->assertStatus(200)->assertDontSee('data-preview=', false);
    }
}
