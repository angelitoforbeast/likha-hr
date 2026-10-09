<?php

namespace Tests\Feature\Import;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * S-02 The automatic compute respects overrides, locks and inactive staff.
 */
class ImportRespectsLocksAndOverridesTest extends ImportTestCase
{
    #[TestDox('S-02.1 a time corrected by hand keeps its value when the day is imported again')]
    public function test_s_02_1_overridden_field_is_kept(): void
    {
        $dayId = $this->dayRow($this->a, self::D1, $this->earlierRun(), ['time_in' => self::D1 . ' 08:30:00']);
        $this->override($dayId, $this->a, self::D1, 'time_in', '08:30');

        $run = $this->process($this->makeRun($this->fullDay('1', self::D1)));

        $day = $this->day($this->a, self::D1);
        $this->assertSame(self::D1 . ' 08:30:00', $day['time_in']);
        $this->assertSame($dayId, (int) $day['id']);
        $this->assertSame(1, DB::table('attendance_days')->count());
        $this->assertSame(1, $this->summary($run)['days_computed']);
    }

    #[TestDox('S-02.2 a locked date gets no day row, the other date is computed, the punches are saved')]
    public function test_s_02_2_locked_date_is_skipped(): void
    {
        $this->lock(self::D1);

        $run = $this->process($this->makeRun(array_merge(
            $this->fullDay('1', self::D1),
            $this->fullDay('1', self::D2),
        )));

        $this->assertNotNull($this->day($this->a, self::D2));
        $this->assertNull($this->day($this->a, self::D1));
        $this->assertSame(1, $this->summary($run)['days_computed']);
        $this->assertSame(1, $this->summary($run)['locked_skipped']);
        $this->assertSame(4, $this->punchCount($this->a, self::D1));
    }

    #[TestDox('S-02.3 an existing day row on a locked date is not changed')]
    public function test_s_02_3_existing_row_on_a_locked_date_is_untouched(): void
    {
        $this->lock(self::D1);
        $this->dayRow($this->a, self::D1, $this->earlierRun());
        $before = $this->day($this->a, self::D1);

        $run = $this->process($this->makeRun($this->fullDay('1', self::D1)));

        $after = $this->day($this->a, self::D1);
        $this->assertSame($before, $after);
        $this->assertSame('2026-10-01 09:00:00', $after['updated_at']);
        $this->assertSummary(0, 1, 0, $run);
    }

    #[TestDox('S-02.4 an inactive employee is not computed and not counted, the punches are saved')]
    public function test_s_02_4_inactive_employee_is_not_computed(): void
    {
        $run = $this->process($this->makeRun(array_merge(
            $this->fullDay('3', self::D1),
            $this->fullDay('1', self::D1),
        )));

        $this->assertNotNull($this->day($this->a, self::D1));
        $this->assertNull($this->day($this->c, self::D1));
        $this->assertSame('done', $run->status);
        $this->assertSame(4, $this->punchCount($this->c, self::D1));
        $this->assertSummary(1, 0, 0, $run);
    }

    #[TestDox('S-02.5 locked days are counted per employee and day')]
    public function test_s_02_5_locked_days_are_counted_per_employee_day(): void
    {
        $this->lock(self::D1);

        $run = $this->process($this->makeRun(array_merge(
            $this->fullDay('1', self::D1),
            $this->fullDay('2', self::D1),
            $this->fullDay('1', self::D2),
        )));

        $this->assertSame(2, $this->summary($run)['locked_skipped']);
        $this->assertSame(1, $this->summary($run)['days_computed']);
        $this->assertSame([$this->key($this->a, self::D2)], $this->dayKeys());
    }

    #[TestDox('a locked day is counted as locked skipped even when it has a row and no new punch')]
    public function test_lock_is_checked_before_the_new_punch_rule(): void
    {
        $earlier = $this->earlierRun();
        foreach (self::FULL_DAY as $time) {
            $this->storedPunch($this->a, self::D1, $time, $earlier);
        }
        $this->dayRow($this->a, self::D1, $earlier);
        $this->lock(self::D1);
        $before = $this->day($this->a, self::D1);

        $run = $this->process($this->makeRun($this->fullDay('1', self::D1)));

        $this->assertSame($before, $this->day($this->a, self::D1));
        $this->assertSummary(0, 1, 0, $run);
    }

    #[TestDox('S-02.6 an overridden day on a locked date counts as locked skipped and is not changed')]
    public function test_s_02_6_override_on_a_locked_date_is_untouched(): void
    {
        $this->lock(self::D1);
        $dayId = $this->dayRow($this->a, self::D1, $this->earlierRun(), ['time_in' => self::D1 . ' 08:30:00']);
        $overrideId = $this->override($dayId, $this->a, self::D1, 'time_in', '08:30');
        $dayBefore = $this->day($this->a, self::D1);
        $overrideBefore = (array) DB::table('attendance_overrides')->find($overrideId);

        $run = $this->process($this->makeRun($this->fullDay('1', self::D1)));

        $this->assertSame($dayBefore, $this->day($this->a, self::D1));
        $this->assertSame($overrideBefore, (array) DB::table('attendance_overrides')->find($overrideId));
        $this->assertSame(1, DB::table('attendance_overrides')->count());
        $this->assertSummary(0, 1, 0, $run);
    }
}
