<?php

namespace Tests\Feature\HalfSil;

use App\Models\Employee;
use App\Models\PayrollItem;
use PHPUnit\Framework\Attributes\TestDox;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Approvals\ApprovalsTestCase;

/**
 * Spec 015: half-day SIL. A SIL row carries `days` (1 or 0.5); the balance sums it,
 * payroll pays the day's rate times it, and every filing path offers a half day.
 *
 * E2 has no attendance in the period, so the pay of one date is easy to read:
 * without SIL every one of the 15 required days is absent and the base pay is 0.
 */
class HalfDaySilTest extends ApprovalsTestCase
{
    protected function applyHalf($user, Employee $employee, string $date, string $reason = 'Kalahating araw na bakasyon')
    {
        return $this->actingAs($user)->postJson('/attendance-calendar/apply-sil', [
            'employee_id' => $employee->id, 'date' => $date, 'reason' => $reason, 'days' => 0.5,
        ]);
    }

    protected function breakdownOf(Employee $employee, string $date): array
    {
        $item = PayrollItem::where('payroll_run_id', $this->run->id)->where('employee_id', $employee->id)->firstOrFail();
        $entry = collect($item->daily_breakdown)->firstWhere('date', $date);
        $this->assertNotNull($entry, "no breakdown entry on {$date}");

        return $entry;
    }

    protected function silDays(Employee $employee, string $date): float
    {
        return (float) DB::table('sil_applications')->where('employee_id', $employee->id)->whereDate('sil_date', $date)->value('days');
    }

    /* ── Data and balance ── */

    #[TestDox('S-015.1 the migration gives a SIL row without days 1.0, and a new SIL is a whole day unless asked otherwise')]
    public function test_s_015_1_existing_rows_stay_whole_days(): void
    {
        $migration = require database_path('migrations/2026_11_14_000001_add_days_to_sil_applications.php');
        $migration->down();
        DB::table('sil_applications')->insert([
            'employee_id' => $this->e1->id, 'sil_date' => '2026-03-02', 'reason' => 'Before the migration',
            'created_at' => '2026-03-01 09:00:00', 'updated_at' => '2026-03-01 09:00:00',
        ]);
        $migration->up();

        $this->assertSame(1.0, $this->silDays($this->e1, '2026-03-02'));

        $this->silSetup($this->e2, 5);
        $this->applySil($this->c, $this->e2, self::DN)->assertStatus(200);
        $this->assertSame(1.0, $this->silDays($this->e2, self::DN));
        $this->assertSame(4.0, $this->silRemaining($this->e2));
    }

    #[TestDox('S-015.2 the balance sums halves: used 1.5 shows remaining 3.5, and used days is a float')]
    public function test_s_015_2_balance_sums_halves(): void
    {
        $this->silSetup($this->e2, 5);
        $this->applyHalf($this->c, $this->e2, self::DN)->assertStatus(200)->assertJson(['success' => true, 'remaining' => 4.5]);
        $this->applySil($this->c, $this->e2, self::DX)->assertStatus(200);

        $this->assertSame(0.5, $this->silDays($this->e2, self::DN));
        $this->assertSame(1.5, Employee::find($this->e2->id)->getSilUsedDays(2026));
        $this->assertSame(3.5, $this->silRemaining($this->e2));
    }

    #[TestDox('S-015.3 a half is refused when 0 remain and allowed when 0.5 remain; a whole day is refused when 0.5 remain')]
    public function test_s_015_3_refuses_what_the_balance_cannot_cover(): void
    {
        $this->silSetup($this->e2, 1);
        $this->applyHalf($this->c, $this->e2, self::DN)->assertStatus(200);
        $this->assertSame(0.5, $this->silRemaining($this->e2));

        $whole = $this->applySil($this->c, $this->e2, self::DX);
        $this->assertFalse($whole->json('success'));
        $this->assertStringContainsString('No remaining SIL days for 2026', $whole->json('message'));

        $this->applyHalf($this->c, $this->e2, self::DX)->assertStatus(200)->assertJson(['success' => true]);
        $this->assertSame(0.0, $this->silRemaining($this->e2));

        $none = $this->applyHalf($this->c, $this->e2, '2026-10-09');
        $this->assertFalse($none->json('success'));
        $this->assertStringContainsString('No remaining SIL days for 2026', $none->json('message'));
        $this->assertSame(2, DB::table('sil_applications')->count());

        // Staff are refused the same way, before any request is stored.
        $asked = $this->applyHalf($this->h, $this->e2, '2026-10-09');
        $this->assertStringContainsString('No remaining SIL days for 2026', $asked->json('message'));
        $this->assertSame(0, $this->requestCount());
    }

    #[TestDox('S-015.4 only a whole or a half day can be filed')]
    public function test_s_015_4_days_must_be_whole_or_half(): void
    {
        $this->silSetup($this->e2, 5);
        $this->actingAs($this->c)->postJson('/attendance-calendar/apply-sil', [
            'employee_id' => $this->e2->id, 'date' => self::DN, 'reason' => 'Kalahati', 'days' => 0.25,
        ])->assertStatus(422);
        $this->assertSame(0, DB::table('sil_applications')->count());
    }

    /* ── Pay ── */

    #[TestDox('S-015.5 half SIL on a required day without punches: half paid, half absent and deducted, balance -0.5')]
    public function test_s_015_5_half_sil_on_required_day_without_punches(): void
    {
        $this->silSetup($this->e2, 5);
        $this->applyHalf($this->c, $this->e2, self::DN)->assertStatus(200);

        $pay = $this->payroll($this->e2);
        $this->assertEquals(14.5, $pay['absent_days']);
        $this->assertEquals(7540.00, $pay['absence_deduction'], '14.5 days at 520.00');
        $this->assertEquals(260.00, $pay['base_pay'], 'the SIL half, 520.00 x 0.5');
        $this->assertEquals(0, $pay['days_worked']);
        $this->assertSame(4.5, $this->silRemaining($this->e2));

        $entry = $this->breakdownOf($this->e2, self::DN);
        $this->assertSame('sil', $entry['type']);
        $this->assertSame('SIL ½', $entry['status']);
        $this->assertEquals(0.5, $entry['sil_days']);
        $this->assertEquals(260.00, $entry['amount']);
    }

    #[TestDox('S-015.6 Xander: half SIL on a special non-working holiday not worked pays rate x 0.5, no absence, no deduction')]
    public function test_s_015_6_half_sil_on_special_holiday(): void
    {
        DB::table('employee_rates')->where('employee_id', $this->e2->id)->update(['daily_rate' => 645.00]);
        DB::table('holidays')->insert([
            'date' => '2026-10-09', 'name' => 'Special Day', 'type' => 'special', 'recurring' => false,
            'created_at' => '2026-01-01 09:00:00', 'updated_at' => '2026-01-01 09:00:00',
        ]);
        $baseline = $this->payroll($this->e2);
        $this->assertEquals(14, $baseline['absent_days']);

        $this->silSetup($this->e2, 5);
        $this->applyHalf($this->c, $this->e2, '2026-10-09')->assertStatus(200);

        $pay = $this->payroll($this->e2);
        $this->assertEquals(14, $pay['absent_days'], 'the holiday is not a required day: no absence');
        $this->assertEquals($baseline['absence_deduction'], $pay['absence_deduction']);
        $this->assertEquals(322.50, $pay['base_pay'], '645.00 x 0.5, not a full 645.00');
        $this->assertSame(4.5, $this->silRemaining($this->e2));

        $entry = $this->breakdownOf($this->e2, '2026-10-09');
        $this->assertSame('SIL ½', $entry['status']);
        $this->assertEquals(322.50, $entry['amount']);
    }

    #[TestDox('S-015.7 half SIL plus 4 hours of punches is a full day: no undertime, no deduction')]
    public function test_s_015_7_half_sil_plus_four_hours(): void
    {
        $this->punchedDay($this->e2, self::DN, ['08:00:00', '12:00:00']);
        $this->silSetup($this->e2, 5);
        $this->applyHalf($this->c, $this->e2, self::DN)->assertStatus(200);

        $pay = $this->payroll($this->e2);
        $this->assertEquals(14, $pay['absent_days']);
        $this->assertEquals(1, $pay['days_worked']);
        $this->assertEquals(0, $pay['total_late_minutes'], 'late + undertime');
        $this->assertEquals(0, $pay['late_deduction']);
        $this->assertEquals(0, $pay['early_deduction']);
        $this->assertEquals(520.00, $pay['base_pay']);

        $entry = $this->breakdownOf($this->e2, self::DN);
        $this->assertSame('SIL ½', $entry['status']);
        $this->assertSame('08:00', $entry['time_in']);
        $this->assertEquals(0, $entry['undertime']);
        $this->assertEquals(520.00, $entry['amount']);
    }

    #[TestDox('S-015.8 half SIL plus 3 hours of punches: 1 hour of undertime only')]
    public function test_s_015_8_half_sil_plus_three_hours(): void
    {
        $this->punchedDay($this->e2, self::DN, ['08:00:00', '11:00:00']);
        $this->silSetup($this->e2, 5);
        $this->applyHalf($this->c, $this->e2, self::DN)->assertStatus(200);

        $pay = $this->payroll($this->e2);
        $this->assertEquals(14, $pay['absent_days']);
        $this->assertEquals(60, $pay['total_late_minutes'], 'late + undertime: the 1 hour short of 4');
        $this->assertEquals(0, $pay['total_early_minutes']);
        $this->assertEquals(65.00, $pay['late_deduction'], '60 min at 520.00 / 8 h');
        $this->assertEquals(0, $pay['early_deduction']);
        $this->assertEquals(455.00, $pay['base_pay']);

        $entry = $this->breakdownOf($this->e2, self::DN);
        $this->assertEquals(60, $entry['undertime']);
        $this->assertEquals(0, $entry['late']);
        $this->assertEquals(455.00, $entry['amount']);
    }

    #[TestDox('S-015.9 half SIL with 30 minutes late on a 4-hour morning counts 30 minutes late')]
    public function test_s_015_9_half_sil_with_late(): void
    {
        $this->punchedDay($this->e2, self::DN, ['08:30:00', '12:00:00']);
        $this->silSetup($this->e2, 5);
        $this->applyHalf($this->c, $this->e2, self::DN)->assertStatus(200);

        $pay = $this->payroll($this->e2);
        $this->assertEquals(30, $pay['total_late_minutes']);
        $this->assertEquals(32.50, $pay['late_deduction']);
        $this->assertEquals(487.50, $pay['base_pay']);
        $entry = $this->breakdownOf($this->e2, self::DN);
        $this->assertEquals(30, $entry['late']);
        $this->assertEquals(0, $entry['undertime']);
    }

    #[TestDox('S-015.10 half SIL on a fully worked day is never paid above one day')]
    public function test_s_015_10_never_above_one_day(): void
    {
        $this->punchedDay($this->e2, self::DN, ['08:00:00', '12:00:00', '13:00:00', '17:00:00']);
        $this->silSetup($this->e2, 5);
        $this->applyHalf($this->c, $this->e2, self::DN)->assertStatus(200);

        $pay = $this->payroll($this->e2);
        $this->assertEquals(520.00, $pay['base_pay']);
        $this->assertEquals(14, $pay['absent_days']);
        $this->assertEquals(520.00, $this->breakdownOf($this->e2, self::DN)['amount']);
    }

    #[TestDox('S-015.11 half SIL with several rates in the period deducts the absent half at that day\'s rate')]
    public function test_s_015_11_multiple_rates(): void
    {
        DB::table('employee_rates')->where('employee_id', $this->e2->id)->update(['effective_until' => '2026-10-05']);
        DB::table('employee_rates')->insert([
            'employee_id' => $this->e2->id, 'daily_rate' => 600.00, 'effective_date' => '2026-10-06',
            'created_at' => '2026-01-01 09:00:00', 'updated_at' => '2026-01-01 09:00:00',
        ]);
        $this->silSetup($this->e2, 5);
        $this->applyHalf($this->c, $this->e2, self::DN)->assertStatus(200);

        $pay = $this->payroll($this->e2);
        $this->assertEquals(14.5, $pay['absent_days']);
        $this->assertEquals(300.00, $pay['base_pay'], '600.00 x 0.5');
        $this->assertEquals(5 * 520 + 9 * 600 + 300, $pay['absence_deduction']);
    }

    #[TestDox('S-015.12 a whole SIL keeps today\'s pay exactly')]
    public function test_s_015_12_whole_sil_unchanged(): void
    {
        $this->silSetup($this->e2, 5);
        $this->applySil($this->c, $this->e2, self::DN)->assertStatus(200);

        $pay = $this->payroll($this->e2);
        $this->assertEquals(14, $pay['absent_days']);
        $this->assertEquals(520.00, $pay['base_pay']);
        $entry = $this->breakdownOf($this->e2, self::DN);
        $this->assertSame('SIL', $entry['status']);
        $this->assertEquals(1, $entry['sil_days']);
        $this->assertEquals(520.00, $entry['amount']);
    }

    /* ── Filing through a request ── */

    #[TestDox('S-015.13 HR asks for a half SIL, the card says "Apply SIL (half day)", the CEO approves, the row has 0.5')]
    public function test_s_015_13_request_carries_the_half(): void
    {
        $this->silSetup($this->e2, 5);
        $this->applyHalf($this->h, $this->e2, self::DN)->assertStatus(200)->assertJson(['pending' => true]);

        $this->assertSame('apply_half', $this->pending($this->e2, self::DN)['sil']['new_value']);
        $this->assertSame(0, DB::table('sil_applications')->count());
        $this->assertSame(5.0, $this->silRemaining($this->e2));

        $page = $this->actingAs($this->c)->get('/approvals')->assertStatus(200)->assertSee('Apply SIL (half day)');
        // The preview's SIL line: now No, if approved a half day.
        $this->assertMatchesRegularExpression('/data-line="SIL"[^>]*>.*?<div role="cell">No<\/div>\s*<div role="cell">Yes \(half day\)<\/div>/s', $page->getContent());

        $this->approve($this->c, $this->e2, self::DN)->assertStatus(200);
        $this->assertSame(0.5, $this->silDays($this->e2, self::DN));
        $this->assertSame(4.5, $this->silRemaining($this->e2));
    }

    #[TestDox('S-015.14 a half SIL request is blocked at approval when less than half a day remains')]
    public function test_s_015_14_half_request_blocked_without_balance(): void
    {
        $this->silSetup($this->e2, 5);
        $this->applyHalf($this->h, $this->e2, self::DN)->assertStatus(200);
        $this->silSetup($this->e2, 0);

        $refused = $this->approve($this->c, $this->e2, self::DN);
        $this->assertStringContainsString('No remaining SIL days', $refused->json('message'));
        $this->assertSame(0, DB::table('sil_applications')->count());
    }

    #[TestDox('S-015.15 asking for a whole day where a half is already applied is refused as already applied')]
    public function test_s_015_15_one_row_per_date(): void
    {
        $this->silSetup($this->e2, 5);
        $this->applyHalf($this->c, $this->e2, self::DN)->assertStatus(200);
        $again = $this->applySil($this->c, $this->e2, self::DN);
        $this->assertStringContainsString('SIL is already applied for this date', $again->json('message'));

        // Changing a half to a whole is remove and apply again.
        $this->removeSil($this->c, $this->e2, self::DN, null)->assertStatus(200);
        $this->applySil($this->c, $this->e2, self::DN)->assertStatus(200);
        $this->assertSame(1.0, $this->silDays($this->e2, self::DN));
    }

    /* ── Display ── */

    #[TestDox('S-015.16 the calendar cell shows SIL ½ and carries the days for the modal')]
    public function test_s_015_16_calendar_cell(): void
    {
        // E1: the calendar lists employees with attendance in the range.
        $this->silSetup($this->e1, 5);
        $this->applyHalf($this->c, $this->e1, self::DN)->assertStatus(200);
        $this->applySil($this->c, $this->e1, self::DX)->assertStatus(200);

        $html = $this->calendar($this->c);
        $this->assertStringContainsString('data-sil-days="0.5"', $this->cell($html, $this->e1, self::DN));
        $this->assertStringContainsString('data-sil-days="1"', $this->cell($html, $this->e1, self::DX));
        $this->assertStringContainsString('data-sil-remaining="3.50"', $this->cell($html, $this->e1, self::DN));
        $this->assertMatchesRegularExpression('/data-date="' . self::DN . '"[^>]*data-employee-id="' . $this->e1->id . '"[^>]*>\s*SIL ½/s', $html);
        $this->assertMatchesRegularExpression('/data-date="' . self::DX . '"[^>]*data-employee-id="' . $this->e1->id . '"[^>]*>\s*SIL\s*</s', $html);
        // The modal offers the choice.
        $this->assertStringContainsString('id="dSilDays"', $html);
    }

    #[TestDox('S-015.17 the profile adds a half SIL, shows used 0.5 and remaining 4.5, and marks the row ½')]
    public function test_s_015_17_profile(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-11-14 10:00:00'));
        $this->silSetup($this->e2, 5);

        $this->actingAs($this->c)->post('/employees/' . $this->e2->id . '/add-sil', [
            'sil_date' => self::DN, 'reason' => 'Kalahating araw', 'days' => '0.5',
        ])->assertSessionHas('success');
        $this->assertSame(0.5, $this->silDays($this->e2, self::DN));

        $page = $this->actingAs($this->c)->get('/employees/' . $this->e2->id . '/edit')->assertStatus(200);
        $page->assertSee('name="days"', false);
        $html = $page->getContent();
        $this->assertMatchesRegularExpression('/Used[^<]*<\/div>\s*<div[^>]*>0\.5<\/div>/', $html);
        $this->assertMatchesRegularExpression('/Remaining[^<]*<\/div>\s*<div[^>]*>4\.50<\/div>/', $html);
        $this->assertStringContainsString('½ day', $html);

        // Half refused on the profile when nothing remains.
        $this->silSetup($this->e2, 0.5);
        $this->actingAs($this->c)->post('/employees/' . $this->e2->id . '/add-sil', [
            'sil_date' => self::DX, 'reason' => 'Kalahating araw', 'days' => '0.5',
        ])->assertSessionHas('error', 'No remaining SIL days for 2026.');
    }

    #[TestDox('S-015.18 the payslip labels a half SIL day SIL ½ and counts it as 0.5 SIL day and 14.5 absences')]
    public function test_s_015_18_payslip(): void
    {
        $this->silSetup($this->e2, 5);
        $this->applyHalf($this->c, $this->e2, self::DN)->assertStatus(200);
        $this->payroll($this->e2);
        $item = PayrollItem::where('payroll_run_id', $this->run->id)->where('employee_id', $this->e2->id)->firstOrFail();

        $html = $this->actingAs($this->c)->get("/payroll/{$this->run->id}/payslip/{$item->id}")->assertStatus(200)->getContent();
        $this->assertStringContainsString('SIL ½', $html);
        $this->assertStringContainsString('+ 0.5 SIL', $html);
        $this->assertStringContainsString('Less: Absences (14.5 days)', $html);
    }
}
