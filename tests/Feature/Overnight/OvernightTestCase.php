<?php

namespace Tests\Feature\Overnight;

use App\Models\Employee;
use App\Models\Shift;
use App\Services\AttendanceComputeService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Approvals\ApprovalsTestCase;

/**
 * Slice 009 fixtures, on top of the ones of slice 002: the warehouse shift (12:00–21:00,
 * lunch 16:00–17:00) and helpers that store device punches with their full date and time.
 * "The live day" is In 11:12 · Lunch out 16:00 · Lunch in 16:59 on Oct 12 and Out 01:11 on Oct 13.
 */
abstract class OvernightTestCase extends ApprovalsTestCase
{
    /** The start date and the next date of the live day: an open Monday and Tuesday. */
    protected const D  = '2026-10-12';
    protected const D1 = '2026-10-13';

    protected const LIVE_DAY = ['11:12:00', '16:00:00', '16:59:00'];

    protected Shift $warehouse;
    protected Employee $w;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = Shift::create([
            'name'                  => 'Warehouse',
            'start_time'            => '12:00:00',
            'end_time'              => '21:00:00',
            'lunch_start'           => '16:00:00',
            'lunch_end'             => '17:00:00',
            'required_work_minutes' => 480,
        ]);
        $this->w = $this->warehouseEmployee('905', 'Warehouse One');
    }

    /** An active employee on the warehouse shift with a daily rate of 718. */
    protected function warehouseEmployee(string $zktecoId, string $name): Employee
    {
        $employee = $this->employee($zktecoId, $name, 'active');
        $employee->update(['default_shift_id' => $this->warehouse->id]);
        DB::table('employee_rates')->where('employee_id', $employee->id)->update(['daily_rate' => 718.00]);

        return $employee->fresh();
    }

    /** Stores device punches, each "Y-m-d H:i:s", without computing anything. */
    protected function punches(Employee $employee, array $stamps): void
    {
        foreach ($stamps as $stamp) {
            DB::table('attendance_logs')->insert([
                'employee_id'   => $employee->id,
                'punched_at'    => $stamp,
                'source_run_id' => $this->importRunId(),
                'created_at'    => '2026-10-01 09:00:00',
                'updated_at'    => '2026-10-01 09:00:00',
            ]);
        }
    }

    /** The punches of the live day: three on D and the 01:11 on D1. */
    protected function liveDayPunches(Employee $employee, string $out = '01:11:00'): void
    {
        $this->punches($employee, array_merge(
            array_map(fn ($t) => self::D . ' ' . $t, self::LIVE_DAY),
            $out === '' ? [] : [self::D1 . ' ' . $out]
        ));
    }

    /** The Recompute button of the calendar and of the attendance page (overrides respected). */
    protected function recompute(string $from, string $to): array
    {
        return app(AttendanceComputeService::class)->computeForDateRange($from, $to, null, false);
    }

    /** A single-date shift for an employee, as the calendar's shift control stores it. */
    protected function shiftOn(Employee $employee, string $date, Shift $shift): void
    {
        DB::table('employee_shift_assignments')->insert([
            'employee_id'     => $employee->id,
            'shift_id'        => $shift->id,
            'effective_date'  => $date,
            'effective_until' => $date,
            'remarks'         => 'one day',
            'created_at'      => '2026-10-01 09:00:00',
            'updated_at'      => '2026-10-01 09:00:00',
        ]);
    }

    /** The minutes and times of a day row that the cases compare. */
    protected function metrics(Employee $employee, string $date): ?array
    {
        $day = $this->day($employee, $date);
        if (!$day) {
            return null;
        }

        return [
            'time_in'   => $day['time_in'],
            'lunch_out' => $day['lunch_out'],
            'lunch_in'  => $day['lunch_in'],
            'time_out'  => $day['time_out'],
            'work'      => (int) $day['computed_work_minutes'],
            'late'      => (int) $day['computed_late_minutes'],
            'undertime' => (int) $day['computed_early_minutes'],
            'overtime'  => (int) $day['computed_overtime_minutes'],
            'payable'   => (int) $day['payable_work_minutes'],
            'review'    => (bool) $day['needs_review'],
        ];
    }

    /** The live day as it must read after the fix. */
    protected function liveDayFixed(): array
    {
        return [
            'time_in'   => self::D . ' 11:12:00',
            'lunch_out' => self::D . ' 16:00:00',
            'lunch_in'  => self::D . ' 16:59:00',
            'time_out'  => self::D1 . ' 01:11:00',
            'work'      => 480,
            'late'      => 0,
            'undertime' => 0,
            'overtime'  => 251,
            'payable'   => 480,
            'review'    => false,
        ];
    }
}
