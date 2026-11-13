<?php

namespace Tests\Feature\Rates;

use App\Models\Employee;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * S-014.1: an employee whose first rate starts after the middle of the cutoff is still paid
 * for the days worked in that cutoff (Dale, 2026-11-14: rate from Nov 4, cutoff Oct 26 – Nov 10,
 * left out of the payroll because no rate was found on Oct 26 or on the midpoint Nov 2).
 *
 * The cutoff of the fixtures is Oct 1–15, 2026; its midpoint is Oct 8.
 */
class NewHireRateTest extends RatesTestCase
{
    private const HIRED = '2026-10-10';

    private Employee $hire;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hire = $this->employee('914', 'New Hire', 'active');
        DB::table('employee_rates')->where('employee_id', $this->hire->id)
            ->update(['daily_rate' => 440.00, 'effective_date' => self::HIRED]);
    }

    #[TestDox('S-014.1 a rate that starts after the cutoff midpoint still puts the employee in the payroll, paid for the days worked')]
    public function test_s_014_1_rate_after_midpoint_is_paid(): void
    {
        $this->punchedDay($this->hire, '2026-10-12', ['08:00:00', '12:00:00', '13:00:00', '17:00:00']);
        $this->punchedDay($this->hire, '2026-10-13', ['08:00:00', '12:00:00', '13:00:00', '17:00:00']);

        $item = $this->payroll($this->hire);

        $this->assertNotNull($item, 'the new hire is left out of the payroll');
        $this->assertEquals(440.00, (float) $item['daily_rate']);
        $this->assertEquals(880.00, (float) $item['base_pay'], 'two full days at 440.00');
    }

    #[TestDox('S-014.1 an employee whose only rate starts after the cutoff stays out of it')]
    public function test_s_014_1_rate_after_cutoff_stays_out(): void
    {
        DB::table('employee_rates')->where('employee_id', $this->hire->id)->update(['effective_date' => '2026-10-16']);

        $this->assertNull($this->payroll($this->hire));
    }

    #[TestDox('S-014.1 a pending rate inside the cutoff does not put the employee in the payroll')]
    public function test_s_014_1_pending_rate_stays_out(): void
    {
        DB::table('employee_rates')->where('employee_id', $this->hire->id)
            ->update(['status' => 'pending', 'approved_marker' => null]);
        $this->punchedDay($this->hire, '2026-10-12', ['08:00:00', '12:00:00', '13:00:00', '17:00:00']);

        $this->assertNull($this->payroll($this->hire));
    }
}
