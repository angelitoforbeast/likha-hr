<?php

namespace Tests\Feature\Rates;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Spec 013 story S1: a new rate that starts inside a rate with a future end date shortens
 * that rate to the day before, like an open-ended rate always was. The CEO saves directly.
 */
class RateOverlapTest extends RatesTestCase
{
    #[TestDox('S-013.1 a raise from Nov 16 on a "For Probi" rate ending Jun 30, 2027 ends that rate on Nov 15 and is saved')]
    public function test_s_013_1_new_rate_shortens_a_rate_with_a_future_end(): void
    {
        $this->addRate($this->c, $this->e1)->assertRedirect()->assertSessionHas('success')->assertSessionMissing('error');

        $this->assertSame([
            ['2026-01-01', '2026-11-15', '520.00'],
            ['2026-11-16', null, '600.00'],
        ], $this->approvedRanges($this->e1));
        $this->assertStringContainsString('Nov 15, 2026', session('success'));
    }

    #[TestDox('S-013.1 the audit log records the shortened rate\'s end date, old and new')]
    public function test_s_013_1_audit_records_the_shortened_end(): void
    {
        $this->addRate($this->c, $this->e1)->assertSessionHas('success');

        $updates = $this->auditRows('updated', $this->probiRateId());
        $this->assertCount(1, $updates);
        $old = json_decode($updates[0]['old_values'], true);
        $new = json_decode($updates[0]['new_values'], true);
        $this->assertStringStartsWith('2027-06-30', substr((string) $old['effective_until'], 0, 10));
        $this->assertStringStartsWith('2026-11-15', (string) $new['effective_until']);
        $this->assertSame($this->c->id, (int) $updates[0]['user_id']);
    }

    #[TestDox('S-013.2 an open-ended rate is still ended the day before the new one (as before)')]
    public function test_s_013_2_open_ended_rate_is_still_shortened(): void
    {
        $this->addRate($this->c, $this->e2, ['effective_date' => '2026-12-01'])->assertSessionHas('success');

        $this->assertSame([
            ['2026-01-01', '2026-11-30', '520.00'],
            ['2026-12-01', null, '600.00'],
        ], $this->approvedRanges($this->e2));
    }

    #[TestDox('S-013.3 a rate that starts before an existing rate and runs into it is refused with a clear message; nothing changes')]
    public function test_s_013_3_starting_before_and_overlapping_is_refused(): void
    {
        $before = $this->rates($this->e1);

        $this->addRate($this->c, $this->e1, ['effective_date' => '2025-12-01', 'effective_until' => '2026-02-28'])
            ->assertSessionHas('error');

        $this->assertStringContainsString('Rate overlaps with existing rate (effective Jan 01, 2026', session('error'));
        $this->assertSame($before, $this->rates($this->e1));
    }

    #[TestDox('S-013.3 a rate on the same start date as an existing rate is refused; nothing changes')]
    public function test_s_013_3_same_start_is_refused(): void
    {
        $before = $this->rates($this->e1);

        $this->addRate($this->c, $this->e1, ['effective_date' => '2026-01-01'])->assertSessionHas('error');

        $this->assertStringContainsString('Rate overlaps with existing rate (effective Jan 01, 2026', session('error'));
        $this->assertSame($before, $this->rates($this->e1));
    }

    #[TestDox('S-013.3 a rate that sits inside a closed rate and ends before it is refused (it would cut the rest of that rate off); nothing changes')]
    public function test_s_013_3_rate_inside_a_closed_rate_is_refused(): void
    {
        $before = $this->rates($this->e1);

        $this->addRate($this->c, $this->e1, ['effective_until' => '2026-11-30'])->assertSessionHas('error');

        $error = session('error');
        $this->assertStringContainsString('Jan 01, 2026', $error);
        $this->assertStringContainsString('Jun 30, 2027', $error);
        $this->assertSame($before, $this->rates($this->e1));
    }

    #[TestDox('S-013.3 a rate that ends on or after the closed rate it starts in is saved, and that rate is shortened')]
    public function test_s_013_3_rate_reaching_past_the_closed_end_is_saved(): void
    {
        $this->addRate($this->c, $this->e1, ['effective_until' => '2027-12-31'])->assertSessionHas('success');

        $this->assertSame([
            ['2026-01-01', '2026-11-15', '520.00'],
            ['2026-11-16', '2027-12-31', '600.00'],
        ], $this->approvedRanges($this->e1));
    }

    #[TestDox('S-013.4 editing a rate\'s start into the rate before it shortens that rate; editing a rate into a later one is refused')]
    public function test_s_013_4_edit_follows_the_same_rule(): void
    {
        DB::table('employee_rates')->where('id', $this->probiRateId())->update(['effective_until' => '2026-10-31']);
        $this->addRate($this->c, $this->e1, ['effective_date' => '2026-11-01', 'effective_until' => self::PROBI_UNTIL])->assertSessionHas('success');
        $later = $this->lastRate($this->e1);

        $this->editRate($this->c, $this->e1, $later['id'], ['daily_rate' => '600', 'effective_date' => '2026-10-16', 'effective_until' => self::PROBI_UNTIL])
            ->assertSessionHas('success');
        $this->assertSame([
            ['2026-01-01', '2026-10-15', '520.00'],
            ['2026-10-16', '2027-06-30', '600.00'],
        ], $this->approvedRanges($this->e1));

        $before = $this->rates($this->e1);
        $this->editRate($this->c, $this->e1, $this->probiRateId(), ['daily_rate' => '520', 'effective_date' => '2026-01-01', 'effective_until' => '2026-12-31'])
            ->assertSessionHas('error');
        $this->assertStringContainsString('Rate overlaps with existing rate (effective Oct 16, 2026', session('error'));
        $this->assertSame($before, $this->rates($this->e1));
    }

    #[TestDox('S-013.4 editing a rate\'s amount only (same dates) is saved and shortens nothing')]
    public function test_s_013_4_edit_amount_only(): void
    {
        $this->editRate($this->c, $this->e1, $this->probiRateId(), ['daily_rate' => '530', 'effective_date' => '2026-01-01', 'effective_until' => self::PROBI_UNTIL, 'remarks' => 'For Probi'])
            ->assertSessionHas('success');

        $this->assertSame([['2026-01-01', '2027-06-30', '530.00']], $this->approvedRanges($this->e1));
    }
}
