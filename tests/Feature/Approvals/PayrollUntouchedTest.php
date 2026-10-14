<?php

namespace Tests\Feature\Approvals;

use App\Models\PayrollItem;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * S-21.3 to S-21.8: nothing pending, rejected or withdrawn reaches payroll, a
 * total or an export, and an approved change pays exactly like a direct one.
 */
class PayrollUntouchedTest extends ApprovalsTestCase
{
    #[TestDox('S-21.3 pending overtime, SIL, shift and rest-day requests leave payroll and the SIL balance at the baseline')]
    public function test_s_21_3_other_kinds_pending_do_not_reach_payroll(): void
    {
        $this->silSetup($this->e1, 5);
        $baseline = $this->payroll();

        $this->saveAll($this->h, $this->e1, self::DR, ['approved_ot_hours' => '3', 'shift_id' => $this->otherShift->id])->assertJson(['pending' => true]);
        $this->toggleDayOff($this->h, $this->e1, self::DR, 'add_day_off')->assertJson(['pending' => true]);
        $this->applySil($this->h, $this->e1, self::DN)->assertJson(['pending' => true]);
        $this->assertSame(4, $this->requestCount('pending'));

        $after = $this->payroll();
        $this->assertSame($baseline, $after);
        foreach (['ot_pay', 'days_worked', 'total_late_minutes', 'total_overtime_minutes', 'absent_days', 'final_pay'] as $column) {
            $this->assertSame($baseline[$column], $after[$column], $column);
        }
        $this->assertSame(5.0, $this->silRemaining($this->e1));
    }

    #[TestDox('S-21.4 a rejected or withdrawn request leaves payroll at the baseline')]
    public function test_s_21_4_rejected_or_withdrawn_does_not_reach_payroll(): void
    {
        $baseline = $this->payroll();

        $this->askFourTimes();
        $this->reject($this->c, $this->e1, self::DN)->assertStatus(200);
        $this->assertSame($baseline, $this->payroll());

        $this->saveAll($this->h, $this->e1, self::DR, ['time_out' => '20:00', 'approved_ot_hours' => '3']);
        foreach ($this->pending($this->e1, self::DR) as $row) {
            $this->actingAs($this->h)->postJson("/attendance-requests/{$row['id']}/withdraw")->assertStatus(200);
        }
        $this->assertSame($baseline, $this->payroll());
        $this->assertSame(0, $this->requestCount('pending'));
    }

    #[TestDox('S-21.5 an approved request pays to the centavo what the same direct entry pays')]
    public function test_s_21_5_approved_equals_direct_in_payroll(): void
    {
        // The control employee has the same official days as E1, entered by the CEO.
        $this->punchedDay($this->e2, self::DR, ['08:03:00', '12:00:00', '13:00:00', '17:00:00']);
        $this->saveAll($this->c, $this->e2, self::DN, ['time_in' => '08:10', 'lunch_out' => '12:00', 'lunch_in' => '13:00', 'time_out' => '18:30']);
        $this->saveAll($this->c, $this->e2, self::DN, ['approved_ot_hours' => '1.5']);
        $before = $this->payrollOf($this->e1);

        $this->saveAll($this->h, $this->e1, self::DN, ['time_in' => '08:10', 'lunch_out' => '12:00', 'lunch_in' => '13:00', 'time_out' => '18:30', 'approved_ot_hours' => '1.5']);
        $this->approve($this->c, $this->e1, self::DN)->assertStatus(200);

        $approved = $this->payrollOf($this->e1);
        $this->assertSame($this->payrollOf($this->e2), $approved);
        $this->assertNotSame($before, $approved, 'the approved day must count');
        $this->assertEquals(2, $approved['days_worked']);
    }

    #[TestDox('S-21.6 pending requests change no day row count, no dashboard count and no export')]
    public function test_s_21_6_counts_and_exports_are_unchanged(): void
    {
        // The print view carries the minute it was generated: the clock stands still for the comparison.
        $this->freezeTime();
        $range = '?start_date=' . self::PERIOD_START . '&end_date=' . self::PERIOD_END;
        $snapshot = function () use ($range) {
            $this->payroll();

            return [
                'day rows'       => $this->dayCount($this->e1),
                'attendance csv' => $this->actingAs($this->c)->get('/attendance/export-csv' . $range)->assertStatus(200)->streamedContent(),
                'attendance print' => $this->actingAs($this->c)->get('/attendance/print' . $range)->assertStatus(200)->getContent(),
                'payroll csv'    => $this->actingAs($this->c)->get("/payroll/{$this->run->id}/export-csv")->assertStatus(200)->streamedContent(),
                // The PDF is rendered from these stored items; compared as data, a PDF's bytes carry its creation time.
                'payroll items'  => PayrollItem::where('payroll_run_id', $this->run->id)->orderBy('employee_id')->get()
                    ->map(fn ($item) => collect($item->getAttributes())->except(['id', 'created_at', 'updated_at'])->all())->all(),
                'dashboard'      => preg_replace('/name="(csrf-token|_token)"[^>]*>/', '', $this->actingAs($this->h)->get('/dashboard')->assertStatus(200)->getContent()),
            ];
        };
        $before = $snapshot();

        $this->silSetup($this->e1, 5);
        $this->askFourTimes();
        $this->saveAll($this->h, $this->e1, self::DR, ['time_in' => '07:00', 'time_out' => '21:00', 'approved_ot_hours' => '4', 'shift_id' => $this->otherShift->id]);
        $this->toggleDayOff($this->h, $this->e1, self::DR, 'add_day_off');
        $this->applySil($this->h, $this->e1, self::DX);
        $this->askFourTimes($this->m, $this->e2);
        $this->assertSame(14, $this->requestCount('pending'));

        $after = $snapshot();
        foreach ($before as $name => $value) {
            $this->assertSame($value, $after[$name], $name);
        }
    }

    #[TestDox('S-21.7 approved overtime of 0.333 and 1.25 hours is 20 and 75 minutes, as on the direct path')]
    public function test_s_21_7_overtime_rounding(): void
    {
        $this->punchedDay($this->e2, self::DR, ['08:03:00', '12:00:00', '13:00:00', '17:00:00']);

        $this->saveAll($this->h, $this->e1, self::DR, ['approved_ot_hours' => '0.333']);
        $this->assertSame('20', $this->pending($this->e1, self::DR)['approved_overtime_minutes']['new_value']);
        $this->approve($this->c, $this->e1, self::DR)->assertStatus(200);
        $this->assertEquals(20, $this->day($this->e1, self::DR)['approved_overtime_minutes']);

        $this->saveAll($this->h, $this->e1, self::DR, ['approved_ot_hours' => '1.25']);
        $this->assertSame('75', $this->pending($this->e1, self::DR)['approved_overtime_minutes']['new_value']);
        $this->approve($this->c, $this->e1, self::DR)->assertStatus(200);
        $this->assertEquals(75, $this->day($this->e1, self::DR)['approved_overtime_minutes']);

        // The direct path, with the same two steps.
        $this->saveAll($this->c, $this->e2, self::DR, ['approved_ot_hours' => '0.333']);
        $this->assertEquals(20, $this->day($this->e2, self::DR)['approved_overtime_minutes']);
        $this->saveAll($this->c, $this->e2, self::DR, ['approved_ot_hours' => '1.25']);
        $this->assertEquals(75, $this->day($this->e2, self::DR)['approved_overtime_minutes']);

        $this->assertSame($this->payrollOf($this->e2), $this->payrollOf($this->e1));
    }

    #[TestDox('S-21.8 a draft payroll run changes only when it is recalculated after an approval')]
    public function test_s_21_8_draft_run_changes_on_recalculation_only(): void
    {
        $computed = $this->payroll();
        $stored = fn () => collect(PayrollItem::where('payroll_run_id', $this->run->id)->where('employee_id', $this->e1->id)->first()->getAttributes())
            ->except(['id', 'created_at', 'updated_at'])->all();
        $this->assertSame($computed, $stored());

        $this->askFourTimes();
        $this->approve($this->c, $this->e1, self::DN)->assertStatus(200);

        $this->assertSame($computed, $stored(), 'the run keeps its numbers until it is recalculated');
        $this->assertEquals(1, $stored()['days_worked']);

        $this->actingAs($this->c)->post("/payroll/{$this->run->id}/recompute")->assertStatus(302);
        $this->assertEquals(2, $stored()['days_worked']);
    }
}
