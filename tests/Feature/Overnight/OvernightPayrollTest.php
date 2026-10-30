<?php

namespace Tests\Feature\Overnight;

use App\Models\PayrollItem;
use App\Services\PayrollService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * S-009.6: the payroll of a cutoff with the live day pays it as a full day; the overtime
 * past the shift end is paid only once approved (1.25x); rice allowance counts a full day.
 */
class OvernightPayrollTest extends OvernightTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $rice = DB::table('benefit_types')->where('name', 'Rice Allowance')->value('id');
        DB::table('employee_benefits')->insert([
            'employee_id'     => $this->w->id,
            'benefit_type_id' => $rice,
            'is_eligible'     => true,
            'amount'          => 20.00,
            'effective_from'  => '2026-01-01',
            'created_at'      => '2026-01-01 09:00:00',
            'updated_at'      => '2026-01-01 09:00:00',
        ]);
    }

    private function item(): PayrollItem
    {
        app(PayrollService::class)->computePayroll($this->run);

        return PayrollItem::where('payroll_run_id', $this->run->id)->where('employee_id', $this->w->id)->firstOrFail();
    }

    private function breakdownOf(PayrollItem $item, string $date): array
    {
        $entry = collect($item->daily_breakdown)->firstWhere('date', $date);
        $this->assertNotNull($entry, "no breakdown entry for {$date}");

        return $entry;
    }

    #[TestDox('S-009.6 the daily breakdown shows the live day as 8 h and 718.00, and the totals count it as a full day with rice allowance')]
    public function test_s_009_6_breakdown_and_totals(): void
    {
        $this->liveDayPunches($this->w);
        $this->recompute(self::D, self::D1);

        $item = $this->item();
        $entry = $this->breakdownOf($item, self::D);

        $this->assertSame('11:12', $entry['time_in']);
        $this->assertSame('01:11', $entry['time_out']);
        $this->assertSame(480, $entry['work_mins']);
        $this->assertEquals(8, $entry['hours']);
        $this->assertEquals(718.00, $entry['amount']);
        $this->assertSame(0, $entry['late']);
        $this->assertSame(0, $entry['undertime']);
        $this->assertSame(0, $entry['ot'], 'overtime is not paid until approved');

        $this->assertSame(480, (int) $item->total_work_minutes);
        $this->assertSame(0, (int) $item->total_late_minutes);
        $this->assertSame(0, (int) $item->total_overtime_minutes);
        $this->assertEquals(0, (float) $item->ot_pay);
        $rice = collect($item->earnings_breakdown)->firstWhere('name', 'Rice Allowance');
        $this->assertNotNull($rice);
        $this->assertEquals(1.0, $rice['days'], 'rice allowance counts the start date as a full day');
        $this->assertEquals(20.00, $rice['amount']);
    }

    #[TestDox('S-009.6 once the overtime is approved, its 4 h 11 m are paid at 1.25x')]
    public function test_s_009_6_approved_overtime_is_paid(): void
    {
        $this->liveDayPunches($this->w);
        $this->recompute(self::D, self::D1);
        $this->saveAll($this->c, $this->w, self::D, ['approved_ot_hours' => '4.1833'])->assertStatus(200);

        $item = $this->item();
        $entry = $this->breakdownOf($item, self::D);

        $this->assertSame(251, $entry['ot']);
        // 251 / 60 / 8 x 718 x 1.25
        $this->assertEquals(469.32, $entry['ot_amount']);
        $this->assertSame(251, (int) $item->total_overtime_minutes);
        $this->assertEquals(469.32, (float) $item->ot_pay);
        $this->assertEquals(718.00, $entry['amount']);
    }
}
