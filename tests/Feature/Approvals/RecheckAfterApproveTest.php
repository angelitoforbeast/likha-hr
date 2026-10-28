<?php

namespace Tests\Feature\Approvals;

use App\Models\AttendanceDay;
use App\Services\AttendanceComputeService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Slice 004: approving a change rechecks the day, so "needs review" and the note
 * come from the day as it is after the approval, not from the import before it.
 */
class RecheckAfterApproveTest extends ApprovalsTestCase
{
    /** The note the import writes for a day with a time in and one lunch punch only. */
    private const STALE_NOTE = 'Lunch punches could not be inferred. No Time Out recorded (missed timeout).';

    private const MISSING_TIME_OUT = 'No Time Out recorded (missed timeout).';
    private const MISSING_LUNCH = 'Lunch punches could not be inferred.';

    /** How often a day was rechecked, counted at the compute service. */
    private int $rechecks = 0;

    protected function setUp(): void
    {
        parent::setUp();
        // The command's printed table is read back with Artisan::output().
        $this->withoutMockingConsoleOutput();

        $test = $this;
        $this->app->instance(AttendanceComputeService::class, new class ($test) extends AttendanceComputeService {
            public function __construct(private $test)
            {
            }

            public function recheckDay(AttendanceDay $day): AttendanceDay
            {
                $this->test->countRecheck();

                return parent::recheckDay($day);
            }
        });
    }

    /** Runs the one-time command and returns its exit code and what it printed. */
    private function recheckCommand(bool $apply = false): array
    {
        $code = Artisan::call('attendance:recheck-approved', $apply ? ['--apply' => true] : []);

        return [$code, Artisan::output()];
    }

    public function countRecheck(): void
    {
        $this->rechecks++;
    }

    /** A day the import flagged: a time in and one punch near lunch, nothing after. */
    private function flaggedDay($employee, string $date = self::DX): array
    {
        $this->punchedDay($employee, $date, ['07:58:00', '12:02:00']);
        $day = $this->day($employee, $date);
        $this->assertEquals(1, $day['needs_review'], 'the import flags the day');
        $this->assertSame(self::STALE_NOTE, $day['notes']);

        return $day;
    }

    private function asCompletedDay($employee, string $date = self::DX): void
    {
        $this->saveAll($this->h, $employee, $date, ['lunch_out' => '12:00', 'lunch_in' => '13:00', 'time_out' => '17:00'])
            ->assertStatus(200)->assertJson(['pending' => true]);
    }

    private function entry($employee, string $date): array
    {
        return ['id' => $this->anchorId($employee, $date), 'seen' => $this->seen($employee, $date)];
    }

    /* ── S-004 P1 ── */

    #[TestDox('S-004.1 a flagged day that gets an approved time out and lunch pair is no longer flagged')]
    public function test_s_004_1_completed_day_is_no_longer_flagged(): void
    {
        $this->flaggedDay($this->e1);
        $this->asCompletedDay($this->e1);

        $this->approve($this->c, $this->e1, self::DX)->assertStatus(200)->assertJson(['success' => true]);

        $day = $this->day($this->e1, self::DX);
        $this->assertEquals(0, $day['needs_review']);
        $this->assertStringNotContainsString(self::MISSING_TIME_OUT, (string) $day['notes']);
        $this->assertStringNotContainsString(self::MISSING_LUNCH, (string) $day['notes']);
        $this->assertSame(480, (int) $day['computed_work_minutes']);
        $this->assertSame(self::DX . ' 07:58:00', $day['time_in'], 'the punched time in stays');
        $this->assertSame(self::DX . ' 17:00:00', $day['time_out']);
        $this->assertSame(1, $this->rechecks);
    }

    #[TestDox('S-004.1 a day the approval creates from nothing is checked like any other')]
    public function test_s_004_1_day_created_by_the_approval_is_checked(): void
    {
        $this->askFourTimes();
        $this->approve($this->c, $this->e1, self::DN)->assertStatus(200);

        $day = $this->day($this->e1, self::DN);
        $this->assertEquals(0, $day['needs_review'], 'four times on a working day are a complete day');
        $this->assertStringNotContainsString('Auto-created', (string) $day['notes']);
        $this->assertSame(480, (int) $day['computed_work_minutes']);

        // Only a time in on a new day: still incomplete, and the note says what is missing.
        $this->saveAll($this->h, $this->e2, self::DN, ['time_in' => '08:00']);
        $this->approve($this->c, $this->e2, self::DN)->assertStatus(200);
        $day = $this->day($this->e2, self::DN);
        $this->assertEquals(1, $day['needs_review']);
        $this->assertStringContainsString(self::MISSING_TIME_OUT, $day['notes']);
        $this->assertStringContainsString(self::MISSING_LUNCH, $day['notes']);
    }

    #[TestDox('S-004.2 approving only lunch in on a day still missing its time out keeps it flagged for the time out only')]
    public function test_s_004_2_still_incomplete_day_names_only_what_is_left(): void
    {
        $this->flaggedDay($this->e1);
        $this->saveAll($this->h, $this->e1, self::DX, ['lunch_in' => '13:00'])->assertJson(['pending' => true]);

        $this->approve($this->c, $this->e1, self::DX)->assertStatus(200);

        $day = $this->day($this->e1, self::DX);
        $this->assertEquals(1, $day['needs_review']);
        $this->assertStringContainsString(self::MISSING_TIME_OUT, $day['notes']);
        $this->assertStringNotContainsString(self::MISSING_LUNCH, $day['notes']);
    }

    #[TestDox('S-004.3 approve several rechecks every affected day once')]
    public function test_s_004_3_approve_several_rechecks_each_day_once(): void
    {
        $this->flaggedDay($this->e1);
        $this->flaggedDay($this->e2);
        $this->asCompletedDay($this->e1);
        $this->asCompletedDay($this->e2);

        $this->actingAs($this->c)->postJson('/approvals/approve-several', [
            'entries' => [$this->entry($this->e1, self::DX), $this->entry($this->e2, self::DX)],
        ])->assertStatus(200);

        $this->assertSame(6, $this->requestCount('approved'));
        foreach ([$this->e1, $this->e2] as $employee) {
            $this->assertEquals(0, $this->day($employee, self::DX)['needs_review'], 'employee ' . $employee->id);
        }
        $this->assertSame(2, $this->rechecks, 'one recheck per day, not per field');
    }

    #[TestDox('S-004.3 batch approve rechecks every affected day once')]
    public function test_s_004_3_batch_approve_rechecks_each_day_once(): void
    {
        $this->flaggedDay($this->e1);
        $this->flaggedDay($this->e2);
        $this->actingAs($this->h)->postJson('/attendance-calendar/bulk-action', [
            'date'         => self::DX,
            'employee_ids' => [$this->e1->id, $this->e2->id],
            'action'       => 'set_times',
            'reason'       => self::REASON,
            'lunch_out'    => '12:00',
            'lunch_in'     => '13:00',
            'time_out'     => '17:00',
        ])->assertStatus(200);
        $batch = DB::table('attendance_change_requests')->value('batch_id');
        $this->assertNotNull($batch);

        $this->actingAs($this->c)->postJson("/approvals/batch/{$batch}/approve", [
            'entries' => [$this->entry($this->e1, self::DX), $this->entry($this->e2, self::DX)],
        ])->assertStatus(200)->assertJson(['message' => '2 approved']);

        foreach ([$this->e1, $this->e2] as $employee) {
            $day = $this->day($employee, self::DX);
            $this->assertEquals(0, $day['needs_review'], 'employee ' . $employee->id);
            $this->assertStringNotContainsString(self::MISSING_TIME_OUT, (string) $day['notes']);
        }
        $this->assertSame(2, $this->rechecks);
    }

    #[TestDox('S-004.4 the recheck keeps approved overtime, other overrides, SIL and rest days as they were')]
    public function test_s_004_4_recheck_keeps_what_was_decided_before(): void
    {
        $this->silSetup($this->e1);
        $this->applySil($this->c, $this->e1, self::DN)->assertStatus(200);
        // A rest day on the date, set while the day had no row, then punches on it.
        $this->toggleDayOff($this->c, $this->e1, self::DX, 'add_day_off')->assertStatus(200);
        $this->flaggedDay($this->e1);
        // The CEO's own edits of the day: a time in and approved overtime.
        $this->saveAll($this->c, $this->e1, self::DX, ['time_in' => '08:05', 'approved_ot_hours' => '1.5'])->assertStatus(200);
        $before = $this->day($this->e1, self::DX);
        $this->assertEquals(90, $before['approved_overtime_minutes']);
        $overridesBefore = DB::table('attendance_overrides')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $officialBefore = $this->official();

        $this->saveAll($this->h, $this->e1, self::DX, ['lunch_out' => '12:00', 'lunch_in' => '13:00', 'time_out' => '18:30'])
            ->assertJson(['pending' => true]);
        $this->approve($this->c, $this->e1, self::DX)->assertStatus(200);

        $day = $this->day($this->e1, self::DX);
        $this->assertEquals(0, $day['needs_review']);
        $this->assertEquals(90, $day['approved_overtime_minutes'], 'approved overtime stays');
        $this->assertSame(self::DX . ' 08:05:00', $day['time_in'], 'the CEO\'s own time in stays');
        $this->assertSame(self::DX . ' 18:30:00', $day['time_out']);
        $this->assertSame($before['source_run_id'], $day['source_run_id'], 'the import that made the day is still named');
        $this->assertSame(90, (int) $day['computed_overtime_minutes']);

        // The earlier override rows are untouched; the approval only added its own.
        $overridesAfter = DB::table('attendance_overrides')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $this->assertSame($overridesBefore, array_slice($overridesAfter, 0, count($overridesBefore)));
        $this->assertCount(count($overridesBefore) + 3, $overridesAfter);

        foreach (['day_offs', 'day_off_logs', 'sil_applications', 'employee_shift_assignments'] as $table) {
            $this->assertSame($officialBefore[$table], $this->official()[$table], $table);
        }
    }

    #[TestDox('S-004.5 the one-time command: dry run lists the day and writes nothing, --apply writes, a second --apply changes nothing')]
    public function test_s_004_5_command_fixes_days_approved_before(): void
    {
        $this->flaggedDay($this->e1);
        $this->asCompletedDay($this->e1);
        $this->approve($this->c, $this->e1, self::DX)->assertStatus(200);
        // As the day was left by an approval before this change: complete, but still flagged.
        DB::table('attendance_days')->where('employee_id', $this->e1->id)->whereDate('work_date', self::DX)
            ->update(['needs_review' => true, 'notes' => self::STALE_NOTE]);
        $stale = $this->day($this->e1, self::DX);
        $officialBefore = $this->official();

        [$code, $output] = $this->recheckCommand();
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Employee One', $output);
        $this->assertStringContainsString(self::DX, $output);
        $this->assertStringContainsString('1 → 0', $output);
        $this->assertStringContainsString(self::STALE_NOTE, $output);
        $this->assertStringContainsString('Dry run', $output);
        $this->assertSame($officialBefore, $this->official(), 'a dry run writes nothing');

        [$code, $output] = $this->recheckCommand(true);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('1 → 0', $output);
        $fixed = $this->day($this->e1, self::DX);
        $this->assertEquals(0, $fixed['needs_review']);
        $this->assertStringNotContainsString(self::MISSING_TIME_OUT, (string) $fixed['notes']);
        foreach (['time_in', 'lunch_out', 'lunch_in', 'time_out', 'computed_work_minutes', 'approved_overtime_minutes', 'source_run_id'] as $column) {
            $this->assertSame($stale[$column], $fixed[$column], $column);
        }

        $afterFirst = $this->official();
        [$code, $output] = $this->recheckCommand(true);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('0 changed', $output);
        $this->assertSame($afterFirst, $this->official(), 'a second run changes nothing');
    }

    #[TestDox('S-004.5 the command leaves a locked date as it is and says so')]
    public function test_s_004_5_command_skips_locked_dates(): void
    {
        $this->flaggedDay($this->e1);
        $this->asCompletedDay($this->e1);
        $this->approve($this->c, $this->e1, self::DX)->assertStatus(200);
        DB::table('attendance_days')->where('employee_id', $this->e1->id)->whereDate('work_date', self::DX)
            ->update(['needs_review' => true, 'notes' => self::STALE_NOTE]);
        $this->lock(self::DX);
        $officialBefore = $this->official();

        [$code, $output] = $this->recheckCommand(true);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('date locked', $output);
        $this->assertSame($officialBefore, $this->official());
    }

    #[TestDox('S-004.6 a rejection does not recheck the day')]
    public function test_s_004_6_reject_does_not_recheck(): void
    {
        $this->flaggedDay($this->e1);
        $this->asCompletedDay($this->e1);
        $officialBefore = $this->official();

        $this->reject($this->c, $this->e1, self::DX)->assertStatus(200);

        $this->assertSame(0, $this->rechecks);
        $this->assertSame($officialBefore, $this->official());
        $this->assertEquals(1, $this->day($this->e1, self::DX)['needs_review']);
    }

    #[TestDox('S-004 a recheck that fails rolls the whole approval back')]
    public function test_failed_recheck_leaves_the_request_pending(): void
    {
        $this->flaggedDay($this->e1);
        $this->asCompletedDay($this->e1);
        $officialBefore = $this->official();
        $this->app->instance(AttendanceComputeService::class, new class extends AttendanceComputeService {
            public function recheckDay(AttendanceDay $day): AttendanceDay
            {
                throw new \RuntimeException('recheck failed');
            }
        });

        $this->approve($this->c, $this->e1, self::DX)->assertStatus(500);

        $this->assertSame($officialBefore, $this->official());
        $this->assertCount(3, $this->pending($this->e1, self::DX));
    }
}
