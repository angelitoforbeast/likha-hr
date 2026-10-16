<?php

namespace Tests\Feature\ScheduleFeed;

use Illuminate\Support\Facades\DB;
use Tests\Feature\Approvals\ApprovalsTestCase;

/**
 * Pins what the Attendance Calendar shows for a sample week, written against the page
 * before the schedule rules moved into ScheduleResolver: the move must not change it.
 */
class CalendarScheduleUnchangedTest extends ApprovalsTestCase
{
    public function test_calendar_shows_the_same_schedule_for_a_sample_week(): void
    {
        $e = $this->e1;
        $stamp = ['created_at' => '2026-10-01 09:00:00', 'updated_at' => '2026-10-01 09:00:00'];

        // Weekly rest days: Saturday and Sunday.
        foreach ([0, 6] as $dow) {
            DB::table('rest_day_patterns')->insert(['employee_id' => $e->id, 'day_of_week' => $dow, 'effective_from' => '2026-01-01'] + $stamp);
        }
        // An extra day off on Wednesday, a cancelled rest day on Saturday.
        DB::table('day_offs')->insert(['employee_id' => $e->id, 'off_date' => '2026-10-07', 'type' => 'day_off'] + $stamp);
        DB::table('day_offs')->insert(['employee_id' => $e->id, 'off_date' => '2026-10-10', 'type' => 'cancel_day_off'] + $stamp);
        // SIL on Thursday.
        DB::table('sil_applications')->insert(['employee_id' => $e->id, 'sil_date' => '2026-10-08', 'reason' => 'Leave', 'applied_by' => $this->c->id] + $stamp);
        // The late shift from Friday on; the day shift again for Sunday only.
        DB::table('employee_shift_assignments')->insert(['employee_id' => $e->id, 'shift_id' => $this->otherShift->id, 'effective_date' => '2026-10-09'] + $stamp);
        DB::table('employee_shift_assignments')->insert(['employee_id' => $e->id, 'shift_id' => $this->shift->id, 'effective_date' => '2026-10-11', 'effective_until' => '2026-10-11'] + $stamp);

        $html = $this->calendar($this->c);

        $expected = [
            '2026-10-05' => ['undertime', 'Day shift'],  // punched, 3 minutes late
            '2026-10-06' => ['absent', 'Day shift'],
            '2026-10-07' => ['day_off', 'Day shift'],    // explicit day off
            '2026-10-08' => ['sil', 'Day shift'],
            '2026-10-09' => ['absent', 'Late shift'],
            '2026-10-10' => ['absent', 'Late shift'],    // rest day cancelled
            '2026-10-11' => ['day_off', 'Day shift'],    // rest day, one-day assignment wins
            '2026-10-12' => ['absent', 'Late shift'],
        ];
        $seen = [];
        foreach (array_keys($expected) as $date) {
            $cell = $this->cell($html, $e, $date);
            preg_match('/data-status="([^"]*)"/', $cell, $status);
            preg_match('/data-shift-name="([^"]*)"/', $cell, $shift);
            $seen[$date] = [$status[1] ?? null, $shift[1] ?? null];
        }

        $this->assertSame($expected, $seen);
    }
}
