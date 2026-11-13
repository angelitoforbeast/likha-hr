<?php

namespace Tests\Feature\HalfSil;

use App\Models\Employee;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Feature\Approvals\ApprovalsTestCase;

/**
 * Spec 016: a whole-day SIL on a date the employee actually worked is not used. The work is
 * paid as on any day, the SIL adds nothing, and the SIL balance is not reduced (owner,
 * 2026-11-14: "sige ifix mo", on the choice "the work wins"). Before, the day was paid twice.
 *
 * E1 worked DR (Oct 5) in the fixtures; E2 has no attendance.
 */
class SilOnWorkedDayTest extends ApprovalsTestCase
{
    private function silOn(Employee $employee, string $date, float $days = 1.0): void
    {
        DB::table('sil_applications')->insert([
            'employee_id' => $employee->id, 'sil_date' => $date, 'days' => $days, 'reason' => 'Naka-file nang maaga',
            'applied_by' => $this->c->id, 'created_at' => '2026-10-01 09:00:00', 'updated_at' => '2026-10-01 09:00:00',
        ]);
    }

    #[TestDox('S-016.1 a whole SIL on a worked day pays the day once, exactly as without the SIL')]
    public function test_s_016_1_worked_day_paid_once(): void
    {
        $without = $this->payrollOf($this->e1);

        $this->silOn($this->e1, self::DR);
        $with = $this->payrollOf($this->e1);

        $this->assertEquals($without['base_pay'], $with['base_pay'], 'the SIL must not add a second day of pay');
        $this->assertEquals($without['final_pay'], $with['final_pay']);
        $this->assertEquals($without['absent_days'], $with['absent_days']);
    }

    #[TestDox('S-016.2 a whole SIL on a worked day does not use the balance; a SIL on a day not worked still does')]
    public function test_s_016_2_balance_not_used_on_worked_day(): void
    {
        $this->silSetup($this->e1, 5);
        $this->silOn($this->e1, self::DR);
        $this->assertSame(5.0, $this->silRemaining($this->e1));

        $this->silOn($this->e1, self::DN);
        $this->assertSame(4.0, $this->silRemaining($this->e1));
    }

    #[TestDox('S-016.3 a whole SIL on a day not worked is still paid as one day (unchanged)')]
    public function test_s_016_3_not_worked_still_paid(): void
    {
        $without = $this->payrollOf($this->e2);
        $this->silOn($this->e2, self::DN);
        $with = $this->payrollOf($this->e2);

        $this->assertEquals(round((float) $without['base_pay'] + 520.00, 2), (float) $with['base_pay']);
    }

    #[TestDox('S-016.4 a half SIL on a worked day still uses 0.5 (spec 015 unchanged)')]
    public function test_s_016_4_half_on_worked_day_still_counts(): void
    {
        $this->silSetup($this->e1, 5);
        $this->silOn($this->e1, self::DR, 0.5);

        $this->assertSame(4.5, $this->silRemaining($this->e1));
    }
}
