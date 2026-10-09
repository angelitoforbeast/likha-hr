<?php

namespace Tests\Feature\Import;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * S-01 Importing a file fills in the attendance days.
 */
class ImportComputesDaysTest extends ImportTestCase
{
    #[TestDox('S-01.1 a file with A on D1 and B on D1 and D2 gives three day rows linked to the run')]
    public function test_s_01_1_import_creates_the_day_rows_of_the_file(): void
    {
        $run = $this->makeRun(array_merge(
            $this->fullDay('1', self::D1),
            $this->fullDay('2', self::D1),
            $this->fullDay('2', self::D2),
        ));

        $run = $this->process($run);

        $this->assertSame(
            [$this->key($this->a, self::D1), $this->key($this->b, self::D1), $this->key($this->b, self::D2)],
            $this->dayKeys(),
            'the import must create the day rows of the employee-days in the file'
        );
        foreach ([[$this->a, self::D1], [$this->b, self::D1], [$this->b, self::D2]] as [$employee, $date]) {
            $this->assertSame($run->id, (int) $this->day($employee, $date)['source_run_id']);
        }
        $this->assertSame('done', $run->status);
        $this->assertSummary(3, 0, 0, $run);
    }

    #[TestDox('S-01.2 importing the same file again stores no punch twice and computes the days again')]
    public function test_s_01_2_same_file_twice_inserts_nothing_again(): void
    {
        $lines = array_merge(
            $this->fullDay('1', self::D1),
            $this->fullDay('2', self::D1),
            $this->fullDay('2', self::D2),
        );
        $this->process($this->makeRun($lines));
        DB::table('attendance_days')->delete();

        $second = $this->process($this->makeRun($lines));

        $this->assertSame(
            12,
            $this->punchCount($this->a, self::D1) + $this->punchCount($this->b, self::D1) + $this->punchCount($this->b, self::D2)
        );
        $this->assertSame(12, DB::table('attendance_logs')->count());
        $this->assertSame(12, $second->stats_json['total_duplicates_skipped']);
        $this->assertSame(0, $second->stats_json['total_logs_inserted']);
        $this->assertSame(
            [$this->key($this->a, self::D1), $this->key($this->b, self::D1), $this->key($this->b, self::D2)],
            $this->dayKeys()
        );
        $this->assertSame(3, $this->summary($second)['days_computed']);
    }

    #[TestDox('S-01.3 lines within the duplicate window store one punch and still compute the day')]
    public function test_s_01_3_duplicates_inside_the_file_still_compute_the_day(): void
    {
        $run = $this->process($this->makeRun([
            $this->line('1', self::D1, '08:00:00'),
            $this->line('1', self::D1, '08:01:00'),
            $this->line('1', self::D1, '08:02:00'),
        ]));

        $this->assertSame(1, DB::table('attendance_logs')->count());
        $this->assertNotNull($this->day($this->a, self::D1));
        $this->assertSame(1, $this->summary($run)['days_computed']);
    }

    #[TestDox('S-01.4 only the dates in the file are computed, not other dates with stored punches')]
    public function test_s_01_4_dates_outside_the_file_are_not_computed(): void
    {
        $earlier = $this->earlierRun();
        $this->storedPunch($this->a, self::D1, '08:00:00', $earlier);
        $this->storedPunch($this->a, self::D3, '08:00:00', $earlier);
        $this->storedPunch($this->a, self::D3, '17:00:00', $earlier);

        $run = $this->process($this->makeRun(array_merge(
            $this->fullDay('1', self::D1),
            $this->fullDay('1', self::D2),
        )));

        $this->assertSame([$this->key($this->a, self::D1), $this->key($this->a, self::D2)], $this->dayKeys());
        $this->assertNull($this->day($this->a, self::D3));
        $this->assertSame(2, $this->summary($run)['days_computed']);
    }

    #[TestDox('S-01.5 the day row of an employee who is not in the file is left untouched')]
    public function test_s_01_5_employee_not_in_the_file_is_untouched(): void
    {
        $earlier = $this->earlierRun();
        $this->storedPunch($this->d, self::D1, '08:00:00', $earlier);
        $this->storedPunch($this->d, self::D1, '17:00:00', $earlier);
        $this->dayRow($this->d, self::D1, $earlier);
        $before = $this->day($this->d, self::D1);

        $this->process($this->makeRun(array_merge(
            $this->fullDay('1', self::D1),
            $this->fullDay('2', self::D1),
        )));

        $after = $this->day($this->d, self::D1);
        $this->assertSame($before, $after);
        $this->assertSame('2026-10-01 09:00:00', $after['updated_at']);
        $this->assertSame($earlier->id, (int) $after['source_run_id']);
        // Without this the case would also hold when nothing is computed at all.
        $this->assertNotNull($this->day($this->a, self::D1));
    }

    #[TestDox('S-01.6 an unmatched device id adds no day row and no compute error')]
    public function test_s_01_6_unmatched_id_adds_no_day_and_no_error(): void
    {
        $run = $this->process($this->makeRun(array_merge(
            [$this->line('99', self::D1, '08:00:00')],
            $this->fullDay('1', self::D1),
        )));

        $this->assertSame([$this->key($this->a, self::D1)], $this->dayKeys());
        $this->assertSame(1, $run->stats_json['unmatched_zkteco_ids']);
        $this->assertSame(0, $this->summary($run)['errors']);
    }

    #[TestDox('S-01.7 a file with no valid line finishes done with nothing computed')]
    public function test_s_01_7_file_without_valid_lines(): void
    {
        $run = $this->process($this->makeRun(['', '   ', "garbage\tnot-a-date", '']));

        $this->assertSame('done', $run->status);
        $this->assertSame(0, DB::table('attendance_days')->count());
        $this->assertSummary(0, 0, 0, $run);
    }

    #[TestDox('S-01.8 an existing day row that receives new punches is recomputed in place, not duplicated')]
    public function test_s_01_8_existing_day_row_with_new_punches_is_recomputed_in_place(): void
    {
        $earlier = $this->earlierRun();
        $this->storedPunch($this->a, self::D1, '08:00:00', $earlier);
        $this->storedPunch($this->a, self::D1, '12:00:00', $earlier);
        $this->dayRow($this->a, self::D1, $earlier);

        $run = $this->process($this->makeRun($this->fullDay('1', self::D1)));

        $this->assertSame(2, $run->stats_json['total_logs_inserted']);
        $this->assertSame(2, $run->stats_json['total_duplicates_skipped']);
        $this->assertSame(1, DB::table('attendance_days')
            ->where('employee_id', $this->a->id)->whereDate('work_date', self::D1)->count());
        $this->assertSame($run->id, (int) $this->day($this->a, self::D1)['source_run_id']);
        $this->assertSame(1, $this->summary($run)['days_computed']);
    }

    #[TestDox('S-01.12 a day that has a row and receives no new punch is left exactly as it is')]
    public function test_s_01_12_day_with_a_row_and_no_new_punch_is_untouched(): void
    {
        $earlier = $this->earlierRun();
        foreach (self::FULL_DAY as $time) {
            $this->storedPunch($this->a, self::D1, $time, $earlier);
        }
        $this->dayRow($this->a, self::D1, $earlier);
        $before = $this->day($this->a, self::D1);

        $run = $this->process($this->makeRun($this->fullDay('1', self::D1)));

        $this->assertSame(0, $run->stats_json['total_logs_inserted']);
        $this->assertSame(4, $run->stats_json['total_duplicates_skipped']);
        $after = $this->day($this->a, self::D1);
        $this->assertSame($before, $after);
        $this->assertSame('2026-10-01 09:00:00', $after['updated_at']);
        $this->assertSame($earlier->id, (int) $after['source_run_id']);
        $this->assertSummary(0, 0, 0, $run);
    }

    #[TestDox('S-01.13 a day whose punches are all stored but that has no row yet is computed')]
    public function test_s_01_13_day_without_a_row_is_computed_even_without_new_punches(): void
    {
        $earlier = $this->earlierRun();
        foreach (self::FULL_DAY as $time) {
            $this->storedPunch($this->a, self::D1, $time, $earlier);
            $this->storedPunch($this->b, self::D1, $time, $earlier);
        }
        $this->dayRow($this->a, self::D1, $earlier);
        $before = $this->day($this->a, self::D1);

        $run = $this->process($this->makeRun(array_merge(
            $this->fullDay('1', self::D1),
            $this->fullDay('2', self::D1),
        )));

        $this->assertSame(0, $run->stats_json['total_logs_inserted']);
        $this->assertSame($run->id, (int) $this->day($this->b, self::D1)['source_run_id']);
        $this->assertSame($before, $this->day($this->a, self::D1));
        $this->assertSame(1, $this->summary($run)['days_computed']);
        $this->assertSame(2, DB::table('attendance_days')->count());
    }

    #[TestDox('S-01.9 a file punch within three minutes of a stored punch is skipped as a duplicate')]
    public function test_s_01_9_stored_punch_makes_a_file_punch_a_duplicate(): void
    {
        $this->storedPunch($this->a, self::D1, '08:00:00', $this->earlierRun());

        $run = $this->process($this->makeRun([
            $this->line('1', self::D1, '08:01:30'),
            $this->line('1', self::D1, '12:00:00'),
            $this->line('1', self::D1, '13:00:00'),
            $this->line('1', self::D1, '17:00:00'),
        ]));

        $this->assertSame(1, $run->stats_json['total_duplicates_skipped']);
        $this->assertSame(3, $run->stats_json['total_logs_inserted']);
        $this->assertSame(0, DB::table('attendance_logs')->where('punched_at', self::D1 . ' 08:01:30')->count());
        $this->assertSame(4, $this->punchCount($this->a, self::D1));
        $this->assertNotNull($this->day($this->a, self::D1));
    }

    #[TestDox('the duplicate window is per employee and ends at exactly three minutes')]
    public function test_duplicate_window_edges_against_stored_punches(): void
    {
        $earlier = $this->earlierRun();
        $this->storedPunch($this->a, self::D1, '08:00:00', $earlier);
        $this->storedPunch($this->b, self::D1, '12:00:00', $earlier);

        $run = $this->process($this->makeRun([
            $this->line('1', self::D1, '08:02:59'), // 179 s after A's stored punch: duplicate
            $this->line('1', self::D1, '12:00:00'), // same time as B's stored punch, other employee: kept
            $this->line('2', self::D1, '12:03:00'), // exactly 180 s after B's stored punch: kept
        ]));

        $this->assertSame(1, $run->stats_json['total_duplicates_skipped']);
        $this->assertSame(2, $run->stats_json['total_logs_inserted']);
        $this->assertSame(2, $this->punchCount($this->a, self::D1));
        $this->assertSame(2, $this->punchCount($this->b, self::D1));
    }

    #[TestDox('an overlapping second import recomputes only the rows that received a new punch')]
    public function test_second_import_recomputes_only_rows_with_a_new_punch(): void
    {
        $lines = array_merge($this->fullDay('1', self::D1), $this->fullDay('2', self::D1));
        $first = $this->process($this->makeRun($lines));
        $ids = DB::table('attendance_days')->orderBy('id')->pluck('id')->all();
        // The production DATE column holds the date alone; sqlite keeps the time part
        // the model writes, which the existing lookup by date would then not find.
        DB::table('attendance_days')->update(['work_date' => self::D1]);
        $rowOfB = $this->day($this->b, self::D1);

        // The same file again, with one late punch more for A on D1 and a new day for A.
        $second = $this->process($this->makeRun(array_merge(
            $lines,
            [$this->line('1', self::D1, '18:30:00')],
            $this->fullDay('1', self::D2),
        )));

        $this->assertSummary(2, 0, 0, $first);
        $this->assertSummary(2, 0, 0, $second);
        $this->assertSame(8, $second->stats_json['total_duplicates_skipped']);
        $this->assertSame(5, $second->stats_json['total_logs_inserted']);
        $this->assertSame(3, DB::table('attendance_days')->count());
        $this->assertSame($ids, DB::table('attendance_days')->whereDate('work_date', self::D1)->orderBy('id')->pluck('id')->all());
        $this->assertSame($second->id, (int) $this->day($this->a, self::D1)['source_run_id']);
        $this->assertSame($rowOfB, $this->day($this->b, self::D1));
    }

    #[TestDox('a punch dated at the very end of year 9999 does not break the stored-punch lookup')]
    public function test_far_future_timestamp_does_not_fail_the_run(): void
    {
        $run = $this->process($this->makeRun([
            $this->line('1', '9999-12-31', '23:59:59'),
            $this->line('1', '0999-01-01', '08:00:00'),
        ]));

        $this->assertSame('done', $run->status);
        $this->assertSame(1, $run->stats_json['total_logs_inserted']);
    }

    #[TestDox('S-01.10 a line whose timestamp is not a real time is skipped and the run goes on')]
    public function test_s_01_10_impossible_timestamp_is_an_invalid_line(): void
    {
        $run = $this->process($this->makeRun(array_merge(
            [
                $this->line('1', '2026-13-45', '08:00:00'),
                $this->line('1', '2026-02-30', '08:00:00'),
                $this->line('1', self::D1, '25:61:61'),
            ],
            $this->fullDay('1', self::D1),
        )));

        $this->assertSame('done', $run->status);
        $this->assertSame(7, $run->stats_json['total_logs_parsed']);
        $this->assertSame(4, $run->stats_json['total_logs_inserted']);
        $this->assertSame(4, DB::table('attendance_logs')->count());
        $this->assertSame([$this->key($this->a, self::D1)], $this->dayKeys());
        $this->assertSummary(1, 0, 0, $run);
    }

    #[TestDox('S-01.11 a date on which no punch is stored gets no row and is counted nowhere')]
    public function test_s_01_11_date_without_a_stored_punch_is_not_counted(): void
    {
        $this->storedPunch($this->a, self::D1, '23:59:30', $this->earlierRun());

        $run = $this->process($this->makeRun([$this->line('1', self::D2, '00:01:00')]));

        $this->assertSame('done', $run->status);
        $this->assertSame(1, $run->stats_json['total_duplicates_skipped']);
        $this->assertSame(0, $run->stats_json['total_logs_inserted']);
        $this->assertSame(0, DB::table('attendance_days')->count());
        $this->assertSummary(0, 0, 0, $run);
    }
}
