<?php

namespace Tests\Feature\Overnight;

use App\Models\Employee;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * S-009.1 (typed time out) and S-009.7: a 01:11 time out typed on the calendar or asked for
 * through Approvals is the next day's, and the Approvals preview shows the right values.
 */
class OvernightApprovalsTest extends OvernightTestCase
{
    private function page(): string
    {
        return $this->actingAs($this->c)->get('/approvals')->assertStatus(200)->getContent();
    }

    private function card(string $html, Employee $employee, string $date): string
    {
        $start = strpos($html, 'data-anchor="' . $this->anchorId($employee, $date) . '"');
        $this->assertNotFalse($start, "no entry for employee {$employee->id} on {$date}");
        $end = strpos($html, 'class="approval-entry card', $start + 1);
        if ($end === false) {
            $end = strpos($html, 'id="approvalListEnd"', $start);
        }

        return substr($html, $start, $end - $start);
    }

    /** @return array<string, array{0: string, 1: string}> label => [now, if approved] */
    private function shown(string $html, Employee $employee, string $date): array
    {
        preg_match_all(
            '/<div role="row" data-line="([^"]+)"\s*class="[^"]*"\s*>\s*<div role="rowheader"[^>]*>.*?<\/div>\s*<div role="cell">(.*?)<\/div>\s*<div role="cell">(.*?)<\/div>/s',
            $this->card($html, $employee, $date),
            $m,
            PREG_SET_ORDER
        );
        $this->assertNotEmpty($m, 'the entry shows no preview');
        $lines = [];
        foreach ($m as $line) {
            $lines[html_entity_decode($line[1])] = [trim(html_entity_decode($line[2])), trim(html_entity_decode($line[3]))];
        }

        return $lines;
    }

    private function warnings(string $html, Employee $employee, string $date): array
    {
        if (!preg_match('/<ul class="[^"]*approval-preview-warnings">(.*?)<\/ul>/s', $this->card($html, $employee, $date), $m)) {
            return [];
        }
        preg_match_all('/<li>(.*?)<\/li>/s', $m[1], $items);

        return array_map(fn ($item) => trim(html_entity_decode($item)), $items[1]);
    }

    #[TestDox('S-009.1 a 01:11 time out typed by the CEO on the calendar is stored as the next day and the day is a full day')]
    public function test_s_009_1_typed_time_out_is_the_next_day(): void
    {
        $this->liveDayPunches($this->w, '');
        $this->recompute(self::D, self::D);

        $this->saveAll($this->c, $this->w, self::D, ['time_out' => '01:11'])->assertStatus(200);

        // "Needs review" of a direct edit is not decided again, today as before (only an approval rechecks).
        $without = fn (array $m) => array_diff_key($m, ['review' => true]);
        $this->assertSame($without($this->liveDayFixed()), $without($this->metrics($this->w, self::D)));
        $override = DB::table('attendance_overrides')->where('employee_id', $this->w->id)->where('field', 'time_out')->sole();
        $this->assertSame('01:11:00', $override->new_value, 'the edit itself keeps the value typed');
    }

    #[TestDox('S-009.1 a time out typed before the time in but outside the window stays on the date, as today')]
    public function test_s_009_1_typed_time_out_outside_the_window_is_not_moved(): void
    {
        $this->liveDayPunches($this->w, '');
        $this->recompute(self::D, self::D);

        $this->saveAll($this->c, $this->w, self::D, ['time_out' => '07:00'])->assertStatus(200);

        $this->assertSame(self::D . ' 07:00:00', $this->day($this->w, self::D)['time_out']);
    }

    #[TestDox('S-009.7 a 01:11 time out asked by HR: the preview shows 1:11 AM (next day), 8 h, no "Time out is before time in"; approve saves it as the next day')]
    public function test_s_009_7_time_out_request_preview_and_approve(): void
    {
        $this->liveDayPunches($this->w, '');
        $this->recompute(self::D, self::D);
        $this->saveAll($this->h, $this->w, self::D, ['time_out' => '01:11'])->assertStatus(200)->assertJson(['pending' => true]);

        $html = $this->page();
        $shown = $this->shown($html, $this->w, self::D);

        $this->assertSame(['none', '1:11 AM (next day)'], $shown['Time out']);
        $this->assertSame(['4 h', '8 h'], $shown['Worked']);
        $this->assertSame('0 m', $shown['Undertime'][1]);
        $this->assertSame('0 m', $shown['Late'][1]);
        $this->assertSame('4 h 11 m', $shown['Overtime worked'][1]);
        $this->assertSame(['4 h 11 m of overtime is not covered by approved overtime.'], $this->warnings($html, $this->w, self::D));

        $this->approve($this->c, $this->w, self::D)->assertStatus(200);

        $this->assertSame($this->liveDayFixed(), $this->metrics($this->w, self::D));
    }

    #[TestDox('S-009.7 an overtime request on the live day: Now / If approved show the next-day time out, 4 h 11 m worked over the shift, and the approved overtime')]
    public function test_s_009_7_overtime_request_preview(): void
    {
        $this->liveDayPunches($this->w);
        $this->recompute(self::D, self::D1);
        $this->saveAll($this->h, $this->w, self::D, ['approved_ot_hours' => '4'])->assertStatus(200)->assertJson(['pending' => true]);

        $html = $this->page();
        $shown = $this->shown($html, $this->w, self::D);

        $this->assertSame(['1:11 AM (next day)', '1:11 AM (next day)'], $shown['Time out']);
        $this->assertSame(['8 h', '8 h'], $shown['Worked']);
        $this->assertSame(['0 m', '0 m'], $shown['Undertime']);
        $this->assertSame(['4 h 11 m', '4 h 11 m'], $shown['Overtime worked']);
        $this->assertSame(['not set', '4 h'], $shown['Overtime approved']);
        $this->assertSame(['11 m of overtime is not covered by approved overtime.'], $this->warnings($html, $this->w, self::D));
        $this->assertStringNotContainsString('Time out is before time in', $html);
    }
}
