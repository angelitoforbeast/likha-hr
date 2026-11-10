<?php

namespace Tests\Feature\Biometrics;

use App\Models\AttendanceDay;
use App\Models\Employee;
use App\Models\Shift;
use App\Services\AttendanceComputeService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * S-012.8 A day fed by the agent follows every rule of a manual upload.
 */
class SameAsManualTest extends BiometricsTestCase
{
    private function fullDayPost(string $zk, string $date): array
    {
        return array_map(fn ($t) => $this->punch($zk, $date, $t), self::FULL_DAY);
    }

    #[TestDox('S-012.8 a locked D1: punches saved, not computed, locked skipped 1')]
    public function test_s_012_8_locked_date(): void
    {
        $this->lock(self::DAY);

        $response = $this->postPunches($this->fullDayPost('1', self::DAY))->assertOk();

        $this->assertSame(4, $this->punchCount($this->a, self::DAY));
        $this->assertNull($this->day($this->a, self::DAY));
        $this->assertSame(1, $response->json('locked_skipped'));
        $this->assertSame(0, $response->json('days_computed'));
    }

    #[TestDox('S-012.8 a manual override on time out stays')]
    public function test_s_012_8_override_stays(): void
    {
        $dayId = $this->dayRow($this->a, self::DAY, $this->earlierRun(), ['time_out' => self::DAY . ' 19:00:00']);
        $this->override($dayId, $this->a, self::DAY, 'time_out', '19:00');

        $this->postPunches($this->fullDayPost('1', self::DAY))->assertOk();

        $day = $this->day($this->a, self::DAY);
        $this->assertSame(self::DAY . ' 19:00:00', $day['time_out']);
        $this->assertSame($dayId, (int) $day['id']);
    }

    #[TestDox('S-012.8 approved overtime stays')]
    public function test_s_012_8_approved_fields_stay(): void
    {
        $this->dayRow($this->a, self::DAY, $this->earlierRun(), ['approved_overtime_minutes' => 90]);

        $this->postPunches($this->fullDayPost('1', self::DAY))->assertOk();

        $this->assertSame(90, (int) $this->day($this->a, self::DAY)['approved_overtime_minutes']);
    }

    #[TestDox('S-012.8 a pending request stays pending')]
    public function test_s_012_8_pending_request_stays_pending(): void
    {
        $id = DB::table('attendance_change_requests')->insertGetId([
            'employee_id' => $this->a->id, 'work_date' => self::DAY, 'field' => 'time_in',
            'new_value' => '07:55', 'old_value' => null, 'reason' => 'forgot to punch',
            'origin' => 'calendar', 'requested_by' => $this->uploader->id, 'status' => 'pending',
            'created_at' => '2026-11-02 09:00:00', 'updated_at' => '2026-11-02 09:00:00',
        ]);
        $before = (array) DB::table('attendance_change_requests')->find($id);

        $this->postPunches($this->fullDayPost('1', self::DAY))->assertOk();

        $this->assertEquals($before, (array) DB::table('attendance_change_requests')->find($id));
    }

    #[TestDox('S-012.8 an overnight time out at 02:10 joins the previous day (specs 009 and 011)')]
    public function test_s_012_8_overnight_time_out_joins_the_previous_day(): void
    {
        $this->postPunches([
            $this->punch('1', self::DAY, '08:00:00'),
            $this->punch('1', self::DAY, '12:00:00'),
            $this->punch('1', self::DAY, '13:00:00'),
        ])->assertOk();
        $this->assertNull($this->day($this->a, self::DAY)['time_out']);

        // The next minute's post brings the 02:10 punch of the next date.
        $this->postPunches($this->punch('1', '2026-11-03', '02:10:00'))->assertOk();

        $this->assertSame('2026-11-03 02:10:00', $this->day($this->a, self::DAY)['time_out']);
        $this->assertNull($this->day($this->a, '2026-11-03'));
    }

    #[TestDox('S-012.8 a compute error keeps the punch saved and the run done, with errors 1')]
    public function test_s_012_8_compute_error_keeps_the_punch(): void
    {
        $this->app->bind(AttendanceComputeService::class, fn () => new class extends AttendanceComputeService {
            public function __construct()
            {
            }

            public function computeDay(Employee $employee, string $workDate, Collection $punches, ?Shift $shift, ?int $sourceRunId = null): AttendanceDay
            {
                throw new \RuntimeException('this day cannot be computed');
            }
        });

        $response = $this->postPunches($this->punch('1', self::DAY, '08:00:12'))->assertOk();

        $this->assertSame(1, DB::table('attendance_logs')->count());
        $this->assertSame(1, $response->json('errors'));
        $this->assertSame('done', \App\Models\AttendanceImportRun::find($response->json('run_id'))->status);
    }

    #[TestDox('S-012.8 an inactive employee: punches saved, no day, as for a file')]
    public function test_s_012_8_inactive_employee(): void
    {
        $response = $this->postPunches($this->fullDayPost('3', self::DAY))->assertOk();

        $this->assertSame(4, $this->punchCount($this->c, self::DAY));
        $this->assertNull($this->day($this->c, self::DAY));
        $this->assertSame(0, $response->json('held'));
    }
}
