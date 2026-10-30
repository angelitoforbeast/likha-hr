<?php

namespace Tests\Feature\Overnight;

use App\Jobs\ParseZktecoImport;
use App\Models\AttendanceImportRun;
use App\Services\AttendanceComputeService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * S-009.1, .2, .3, .5: a punch after midnight is the start date's time out, in the import
 * compute and in Recompute; the next date keeps its own punches; normal days are unchanged.
 */
class OvernightComputeTest extends OvernightTestCase
{
    /** Runs the real import job on a file of "zktecoId => [stamps]" lines. */
    private function import(array $lines): AttendanceImportRun
    {
        Storage::fake('local');
        $run = AttendanceImportRun::create(['uploaded_by' => $this->c->id, 'status' => 'queued']);
        $body = [];
        foreach ($lines as [$zk, $stamp]) {
            $body[] = "{$zk}\t{$stamp}\t0\t1\t\t0\t0";
        }
        Storage::disk('local')->put("imports/{$run->id}/attlog.dat", implode("\r\n", $body) . "\r\n");
        ParseZktecoImport::dispatchSync($run->id);

        return $run->fresh();
    }

    #[TestDox('S-009.1 the live day from punches: time out next-day 01:11, regular full day, late 0, undertime 0, overtime 4 h 11 m recorded and not approved')]
    public function test_s_009_1_recompute_makes_the_live_day_one_full_day(): void
    {
        $this->liveDayPunches($this->w);

        $this->recompute(self::D, self::D1);

        $this->assertSame($this->liveDayFixed(), $this->metrics($this->w, self::D));
        $this->assertNull($this->day($this->w, self::D)['approved_overtime_minutes'], 'overtime waits for approval');
        // Worked about 13 hours: 11:12 to 01:11 is 13 h 59 m, less the 59 m lunch.
        $day = $this->day($this->w, self::D);
        $span = Carbon::parse($day['time_in'])->diffInMinutes(Carbon::parse($day['time_out']))
            - Carbon::parse($day['lunch_out'])->diffInMinutes(Carbon::parse($day['lunch_in']));
        $this->assertSame(780, (int) $span);
        $this->assertNull($this->day($this->w, self::D1), 'the 01:11 punch makes no day of its own');
    }

    #[TestDox('S-009.1 the same day through the import, one file with all four punches')]
    public function test_s_009_1_import_of_the_whole_day(): void
    {
        $run = $this->import([
            ['905', self::D . ' 11:12:00'], ['905', self::D . ' 16:00:00'],
            ['905', self::D . ' 16:59:00'], ['905', self::D1 . ' 01:11:00'],
        ]);

        $this->assertSame('done', $run->status);
        $this->assertSame($this->liveDayFixed(), $this->metrics($this->w, self::D));
        $this->assertNull($this->day($this->w, self::D1));
    }

    #[TestDox('S-009.1 a later file that brings only the 01:11 punch recomputes the start date')]
    public function test_s_009_1_import_of_only_the_next_day_punch_fixes_the_start_date(): void
    {
        $this->import([['905', self::D . ' 11:12:00'], ['905', self::D . ' 16:00:00'], ['905', self::D . ' 16:59:00']]);
        $this->assertNull($this->day($this->w, self::D)['time_out'], 'before the 01:11 punch arrives the day has no time out');

        $run = $this->import([['905', self::D1 . ' 01:11:00']]);

        $this->assertSame($this->liveDayFixed(), $this->metrics($this->w, self::D));
        $this->assertNull($this->day($this->w, self::D1));
        $this->assertSame(1, $run->stats_json['compute']['days_computed']);
    }

    #[TestDox('S-009.1 a locked start date is not recomputed by an import that brings the 01:11 punch')]
    public function test_s_009_1_import_leaves_a_locked_start_date(): void
    {
        $this->import([['905', self::D . ' 11:12:00'], ['905', self::D . ' 16:00:00'], ['905', self::D . ' 16:59:00']]);
        $before = $this->day($this->w, self::D);
        $this->lock(self::D);

        $run = $this->import([['905', self::D1 . ' 01:11:00']]);

        $this->assertEquals($before, $this->day($this->w, self::D));
        $this->assertSame(1, $run->stats_json['compute']['locked_skipped']);
    }

    #[TestDox('S-009.2 the 01:11 punch is not the next date\'s time in; the next date\'s first punch after the window is')]
    public function test_s_009_2_next_date_keeps_its_own_punches(): void
    {
        $this->liveDayPunches($this->w);
        $this->punches($this->w, [self::D1 . ' 11:05:00', self::D1 . ' 16:00:00', self::D1 . ' 17:00:00', self::D1 . ' 21:00:00']);

        $this->recompute(self::D, self::D1);

        $this->assertSame($this->liveDayFixed(), $this->metrics($this->w, self::D));
        $next = $this->metrics($this->w, self::D1);
        $this->assertSame(self::D1 . ' 11:05:00', $next['time_in']);
        $this->assertSame(self::D1 . ' 21:00:00', $next['time_out']);
        $this->assertSame([480, 0, 0, 0], [$next['work'], $next['late'], $next['undertime'], $next['overtime']]);

        // Each date on its own (the per-day rebuild after a calendar edit) gives the same rows.
        $both = [$this->day($this->w, self::D), $this->day($this->w, self::D1)];
        $stats = ['processed' => 0, 'skipped' => 0, 'errors' => 0];
        foreach ([self::D1, self::D] as $date) {
            app(AttendanceComputeService::class)->computeForEmployee(
                $this->w, Carbon::parse($date)->startOfDay(), Carbon::parse($date)->endOfDay(), null, $stats, false
            );
        }
        $again = [$this->day($this->w, self::D), $this->day($this->w, self::D1)];
        foreach ([0, 1] as $i) {
            unset($both[$i]['updated_at'], $again[$i]['updated_at']);
        }
        $this->assertEquals($both, $again);
    }

    #[TestDox('S-009.2 six nights in a row give six full days whether computed as one range, one day at a time, or by an import of each day')]
    public function test_s_009_2_a_run_of_nights_is_the_same_however_it_is_computed(): void
    {
        $dates = ['2026-10-08', '2026-10-09', '2026-10-10', '2026-10-11', '2026-10-12', '2026-10-13'];
        $byRange = $this->w;
        $byDay = $this->warehouseEmployee('930', 'Day By Day');
        $byImport = $this->warehouseEmployee('931', 'By Import');
        foreach ([$byRange, $byDay] as $employee) {
            foreach ($dates as $date) {
                $next = Carbon::parse($date)->addDay()->format('Y-m-d');
                $this->punches($employee, ["{$date} 11:12:00", "{$date} 16:00:00", "{$date} 16:59:00", "{$next} 01:11:00"]);
            }
        }

        $this->recompute($dates[0], '2026-10-14');
        // Day by day, last date first: no date may lean on a neighbour computed before it.
        DB::table('attendance_days')->where('employee_id', $byDay->id)->delete();
        $stats = ['processed' => 0, 'skipped' => 0, 'errors' => 0];
        foreach (array_reverse(array_merge($dates, ['2026-10-14'])) as $date) {
            app(AttendanceComputeService::class)->computeForEmployee(
                $byDay, Carbon::parse($date)->startOfDay(), Carbon::parse($date)->endOfDay(), null, $stats, false
            );
        }
        foreach ($dates as $date) {
            $next = Carbon::parse($date)->addDay()->format('Y-m-d');
            $this->import([['931', "{$date} 11:12:00"], ['931', "{$date} 16:00:00"], ['931', "{$date} 16:59:00"], ['931', "{$next} 01:11:00"]]);
        }

        foreach ([$byRange, $byDay, $byImport] as $employee) {
            foreach ($dates as $date) {
                $next = Carbon::parse($date)->addDay()->format('Y-m-d');
                $m = $this->metrics($employee, $date);
                $this->assertNotNull($m, "{$employee->full_name} {$date}: no day");
                $this->assertSame("{$next} 01:11:00", $m['time_out'], "{$employee->full_name} {$date}");
                $this->assertSame([480, 0, 251], [$m['work'], $m['undertime'], $m['overtime']], "{$employee->full_name} {$date}");
            }
            $this->assertNull($this->day($employee, '2026-10-14'), "{$employee->full_name}: the last 01:11 makes no day of its own");
        }
    }

    #[TestDox('S-009.2 Recompute removes a next-date day made earlier from the 01:11 punch alone, unless it has edits or is locked')]
    public function test_s_009_2_recompute_removes_the_old_next_date_day(): void
    {
        $this->liveDayPunches($this->w);
        $edited = $this->warehouseEmployee('932', 'Edited');
        $this->liveDayPunches($edited);
        foreach ([$this->w, $edited] as $employee) {
            DB::table('attendance_days')->insert([
                'employee_id' => $employee->id, 'work_date' => self::D1, 'shift_id' => $this->warehouse->id,
                'time_in' => self::D1 . ' 01:11:00', 'computed_work_minutes' => 0, 'payable_work_minutes' => 0,
                'needs_review' => true, 'created_at' => '2026-10-14 09:00:00', 'updated_at' => '2026-10-14 09:00:00',
            ]);
        }
        DB::table('attendance_days')->where('employee_id', $edited->id)->update(['approved_overtime_minutes' => 30]);

        $this->recompute(self::D, self::D1);

        $this->assertNull($this->day($this->w, self::D1));
        $this->assertNotNull($this->day($edited, self::D1), 'a day with an edit of its own is kept');

        // The start date locked: it cannot take the punch, so the next date keeps its day.
        $this->lock(self::D);
        DB::table('attendance_days')->insert([
            'employee_id' => $this->w->id, 'work_date' => self::D1, 'shift_id' => $this->warehouse->id,
            'time_in' => self::D1 . ' 01:11:00', 'computed_work_minutes' => 0, 'payable_work_minutes' => 0,
            'needs_review' => true, 'created_at' => '2026-10-14 09:00:00', 'updated_at' => '2026-10-14 09:00:00',
        ]);
        $this->recompute(self::D1, self::D1);
        $this->assertNotNull($this->day($this->w, self::D1), 'kept while the start date is locked');
        $this->assertSame($this->liveDayFixed(), $this->metrics($this->w, self::D));
    }

    #[TestDox('S-009.3 05:59 is inside the window and 06:00 is not; with an 08:00 shift next, 04:59 is inside and 05:00 is not')]
    public function test_s_009_3_window_edges(): void
    {
        $cases = [
            // [punch on D1, D1 shift starts at 08:00, taken as D's time out]
            ['05:59:00', false, true],
            ['06:00:00', false, false],
            ['04:59:00', true, true],
            ['05:00:00', true, false],
        ];
        foreach ($cases as $i => [$time, $earlyShift, $taken]) {
            $employee = $this->warehouseEmployee('91' . $i, 'Edge ' . $i);
            if ($earlyShift) {
                $this->shiftOn($employee, self::D1, $this->shift);
            }
            $this->liveDayPunches($employee, $time);
        }

        $this->recompute(self::D, self::D1);

        foreach ($cases as $i => [$time, $earlyShift, $taken]) {
            $employee = \App\Models\Employee::where('zkteco_id', '91' . $i)->first();
            $day = $this->day($employee, self::D);
            $next = $this->day($employee, self::D1);
            if ($taken) {
                $this->assertSame(self::D1 . ' ' . $time, $day['time_out'], "{$time}: the start date's time out");
                $this->assertNull($next, "{$time}: no day of its own");
            } else {
                $this->assertNull($day['time_out'], "{$time}: outside the window, the start date has no time out");
                $this->assertEquals(1, $day['needs_review']);
                $this->assertSame(self::D1 . ' ' . $time, $next['time_in'], "{$time}: the next date's time in");
            }
        }
    }

    #[TestDox('S-009.5 a normal day computes exactly as before')]
    public function test_s_009_5_normal_day_is_unchanged(): void
    {
        // E1 on Dr, computed in setUp from 08:03, 12:00, 13:00, 17:00 on the day shift.
        $this->assertSame([
            'time_in'   => self::DR . ' 08:03:00',
            'lunch_out' => self::DR . ' 12:00:00',
            'lunch_in'  => self::DR . ' 13:00:00',
            'time_out'  => self::DR . ' 17:00:00',
            'work'      => 477,
            'late'      => 3,
            'undertime' => 0,
            'overtime'  => 0,
            'payable'   => 477,
            'review'    => false,
        ], $this->metrics($this->e1, self::DR));
    }

    #[TestDox('S-009.5 an early punch on the next date stays the next date\'s when the start date has its own time out, or no punches')]
    public function test_s_009_5_early_punch_stays_with_its_date_when_not_a_time_out(): void
    {
        // A full warehouse day with its own time out, and a 05:30 punch the next morning.
        $this->punches($this->w, [self::D . ' 11:55:00', self::D . ' 16:00:00', self::D . ' 17:00:00', self::D . ' 21:00:00', self::D1 . ' 05:30:00']);
        // Nobody punched on D for this one: a 01:30 punch is the next date's own.
        $other = $this->warehouseEmployee('920', 'Other');
        $this->punches($other, [self::D1 . ' 01:30:00']);

        $this->recompute(self::D, self::D1);

        $this->assertSame(self::D . ' 21:00:00', $this->day($this->w, self::D)['time_out']);
        $this->assertSame(480, (int) $this->day($this->w, self::D)['computed_work_minutes']);
        $this->assertSame(self::D1 . ' 05:30:00', $this->day($this->w, self::D1)['time_in']);
        $this->assertNull($this->day($other, self::D));
        $this->assertSame(self::D1 . ' 01:30:00', $this->day($other, self::D1)['time_in']);
    }
}
