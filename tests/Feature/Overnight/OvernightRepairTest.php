<?php

namespace Tests\Feature\Overnight;

use App\Models\Employee;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * S-009.8: the repair command recomputes past days left wrong by the old compute. Dry run by
 * default, --apply to write; locked dates are skipped and named.
 *
 * Seeded as the old code left them:
 *  - W on Oct 12/13: punches of the live day; Oct 12 without a time out, Oct 13 a day of its own
 *    with time in 01:11.
 *  - T on Oct 12: the same three punches and a 01:11 time out typed by hand, stored on Oct 12.
 *  - L on Oct 07 (a locked date) and Oct 08: like W.
 *  - E1 on Dr: a normal day, never listed.
 */
class OvernightRepairTest extends OvernightTestCase
{
    private Employee $t;
    private Employee $l;

    protected function setUp(): void
    {
        parent::setUp();
        // The command's lines are read back with Artisan::output().
        $this->withoutMockingConsoleOutput();

        $this->liveDayPunches($this->w);
        $this->oldStartDate($this->w, self::D);
        $this->oldNextDate($this->w, self::D1);

        $this->t = $this->warehouseEmployee('906', 'Typed One');
        $this->liveDayPunches($this->t, '');
        $dayId = $this->oldStartDate($this->t, self::D, [
            'time_out'               => self::D . ' 01:11:00',
            'computed_early_minutes' => 1129,
            'needs_review'           => true,
            'notes'                  => 'Lunch In is after Time Out (order issue). Preserved overrides: time_out.',
        ]);
        DB::table('attendance_overrides')->insert([
            'attendance_day_id' => $dayId, 'employee_id' => $this->t->id, 'work_date' => self::D,
            'field' => 'time_out', 'old_value' => null, 'new_value' => '01:11:00', 'reason' => 'nakalimutan',
            'updated_by' => $this->c->id, 'created_at' => '2026-10-14 09:00:00', 'updated_at' => '2026-10-14 09:00:00',
        ]);

        $this->l = $this->warehouseEmployee('907', 'Locked One');
        $this->punches($this->l, [self::DL . ' 11:12:00', self::DL . ' 16:00:00', self::DL . ' 16:59:00', self::DX . ' 01:11:00']);
        $this->oldStartDate($this->l, self::DL);
        $this->oldNextDate($this->l, self::DX);
    }

    /** The start date as the old compute left it: no time out, 4 h, needs review. */
    private function oldStartDate(Employee $employee, string $date, array $values = []): int
    {
        return DB::table('attendance_days')->insertGetId(array_merge([
            'employee_id' => $employee->id, 'work_date' => $date, 'shift_id' => $this->warehouse->id,
            'time_in' => "{$date} 11:12:00", 'lunch_out' => "{$date} 16:00:00", 'lunch_in' => "{$date} 16:59:00", 'time_out' => null,
            'computed_work_minutes' => 240, 'computed_late_minutes' => 0, 'computed_early_minutes' => 0,
            'computed_overtime_minutes' => 0, 'payable_work_minutes' => 240, 'needs_review' => true,
            'notes' => 'No Time Out recorded (missed timeout).', 'source_run_id' => $this->importRunId(),
            'created_at' => '2026-10-14 09:00:00', 'updated_at' => '2026-10-14 09:00:00',
        ], $values));
    }

    /** The next date as the old compute left it: a day of its own from the 01:11 punch. */
    private function oldNextDate(Employee $employee, string $date): int
    {
        return DB::table('attendance_days')->insertGetId([
            'employee_id' => $employee->id, 'work_date' => $date, 'shift_id' => $this->warehouse->id,
            'time_in' => "{$date} 01:11:00", 'computed_work_minutes' => 0, 'computed_late_minutes' => 0,
            'computed_early_minutes' => 0, 'computed_overtime_minutes' => 0, 'payable_work_minutes' => 0,
            'needs_review' => true, 'notes' => 'Only 1 punch recorded. No Time Out recorded (missed timeout).',
            'source_run_id' => $this->importRunId(), 'created_at' => '2026-10-14 09:00:00', 'updated_at' => '2026-10-14 09:00:00',
        ]);
    }

    private function repair(array $options = []): string
    {
        $code = Artisan::call('attendance:repair-overnight', array_merge(['--from' => self::PERIOD_START, '--to' => self::PERIOD_END], $options));
        $this->assertSame(0, $code);

        return Artisan::output();
    }

    #[TestDox('S-009.8 the dry run lists every change and writes nothing')]
    public function test_s_009_8_dry_run_lists_and_writes_nothing(): void
    {
        $before = $this->official();

        $out = $this->repair();

        $this->assertEquals($before, $this->official(), 'a dry run writes nothing');
        $this->assertStringContainsString('Dry run: nothing is written. Run again with --apply to write.', $out);
        $this->assertStringContainsString('Warehouse One (#' . $this->w->id . ') ' . self::D, $out);
        $this->assertStringContainsString('time out none -> 2026-10-13 01:11', $out);
        $this->assertStringContainsString('worked 4 h -> 8 h', $out);
        $this->assertStringContainsString('overtime 0 m -> 4 h 11 m', $out);
        $this->assertStringContainsString('next date ' . self::D1 . ': day removed (its only punch is the time out above)', $out);
        $this->assertStringContainsString('Typed One (#' . $this->t->id . ') ' . self::D, $out);
        $this->assertStringContainsString('time out 2026-10-12 01:11 -> 2026-10-13 01:11', $out);
        $this->assertStringContainsString('undertime 18 h 49 m -> 0 m', $out);
        $this->assertStringContainsString('Locked One (#' . $this->l->id . ') ' . self::DL . ': skipped, the date is locked', $out);
        $this->assertStringNotContainsString('Employee One', $out);
        $this->assertStringContainsString('2 days would change, 1 next-date day would be removed, 1 locked date skipped.', $out);
    }

    #[TestDox('S-009.8 --apply writes the same changes; locked dates and normal days stay; a second run finds nothing')]
    public function test_s_009_8_apply_writes_and_is_idempotent(): void
    {
        $locked = [$this->day($this->l, self::DL), $this->day($this->l, self::DX)];
        $normal = $this->day($this->e1, self::DR);

        $out = $this->repair(['--apply' => true]);

        $this->assertStringContainsString('2 days changed, 1 next-date day removed, 1 locked date skipped.', $out);
        $this->assertSame($this->liveDayFixed(), $this->metrics($this->w, self::D));
        $this->assertNull($this->day($this->w, self::D1));
        $t = $this->metrics($this->t, self::D);
        $this->assertSame(self::D1 . ' 01:11:00', $t['time_out']);
        $this->assertSame([480, 0, 0, 251, 480], [$t['work'], $t['late'], $t['undertime'], $t['overtime'], $t['payable']]);
        $this->assertSame('01:11:00', DB::table('attendance_overrides')->where('employee_id', $this->t->id)->value('new_value'));
        $this->assertEquals($locked, [$this->day($this->l, self::DL), $this->day($this->l, self::DX)]);
        $this->assertEquals($normal, $this->day($this->e1, self::DR));

        $again = $this->repair(['--apply' => true]);
        $this->assertStringContainsString('0 days changed, 0 next-date days removed, 1 locked date skipped.', $again);
    }

    #[TestDox('S-009.8 a next-date day with an edit or a request of its own is kept and named for a person to check')]
    public function test_s_009_8_next_date_with_edits_is_kept(): void
    {
        $nextId = (int) $this->day($this->w, self::D1)['id'];
        DB::table('attendance_overrides')->insert([
            'attendance_day_id' => $nextId, 'employee_id' => $this->w->id, 'work_date' => self::D1,
            'field' => 'lunch_out', 'old_value' => null, 'new_value' => '16:00:00', 'reason' => 'x',
            'updated_by' => $this->c->id, 'created_at' => '2026-10-14 09:00:00', 'updated_at' => '2026-10-14 09:00:00',
        ]);

        $out = $this->repair(['--apply' => true]);

        $this->assertStringContainsString('next date ' . self::D1 . ': kept, it has edits or requests of its own; check it by hand', $out);
        $this->assertNotNull($this->day($this->w, self::D1));
        $this->assertSame($this->liveDayFixed(), $this->metrics($this->w, self::D));
    }
}
