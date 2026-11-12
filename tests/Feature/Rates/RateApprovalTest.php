<?php

namespace Tests\Feature\Rates;

use App\Models\Employee;
use App\Models\EmployeeRate;
use App\Models\PayrollItem;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Spec 013 stories S2–S5: a rate saved by anyone but the CEO waits for the CEO. It counts
 * nowhere until approved; approving it applies the S1 rule at that moment.
 */
class RateApprovalTest extends RatesTestCase
{
    #[TestDox('S-013.5 a rate added by HR is pending: the old rate is not shortened and the current rate stays 520.00')]
    public function test_s_013_5_hr_rate_is_pending(): void
    {
        $this->travelTo('2026-11-20 10:00:00');

        $this->addRate($this->h, $this->e1)->assertSessionHas('success');

        $row = $this->lastRate($this->e1);
        $this->assertSame('pending', $row['status']);
        $this->assertSame($this->h->id, (int) $row['requested_by']);
        $this->assertNull($row['decided_by']);
        $this->assertSame([['2026-01-01', '2027-06-30', '520.00']], $this->approvedRanges($this->e1));
        $this->assertStringContainsString('approval', session('success'));

        $employee = Employee::find($this->e1->id);
        $this->assertSame(520.0, $employee->getCurrentRate());
        $this->assertSame(520.0, $employee->getRateForDate('2026-12-01'));
        $this->assertSame(520.0, EmployeeRate::getActiveRate($this->e1->id, '2026-12-01'));
        $this->assertCount(1, $employee->employeeRates);
        $this->actingAs($this->h)->get('/employees?search=Employee+One')->assertSee('520.00')->assertDontSee('600.00');
    }

    #[TestDox('S-013.5 an admin\'s rate waits too; only the CEO saves directly')]
    public function test_s_013_5_admin_rate_is_pending(): void
    {
        $this->addRate($this->m, $this->e1)->assertSessionHas('success');
        $this->assertSame('pending', $this->lastRate($this->e1)['status']);

        $this->addRate($this->c, $this->e2)->assertSessionHas('success');
        $ceo = $this->lastRate($this->e2);
        $this->assertSame('approved', $ceo['status']);
        $this->assertSame($this->c->id, (int) $ceo['requested_by']);
    }

    #[TestDox('S-013.6 pending, rejected and pending-edit rates never reach PayrollService; the approved rate does')]
    public function test_s_013_6_pending_rates_never_reach_payroll(): void
    {
        $baseline = $this->payroll();
        $this->assertSame('520.00', number_format((float) $baseline['daily_rate'], 2, '.', ''));

        // A new rate inside the payroll period, a change of the rate that counts, and a rejected one.
        $this->addRate($this->h, $this->e1, ['daily_rate' => '999', 'effective_date' => '2026-10-01'])->assertSessionHas('success');
        $pendingNew = $this->lastRate($this->e1)['id'];
        $this->editRate($this->h, $this->e1, $this->probiRateId(), ['daily_rate' => '888', 'effective_date' => '2026-01-01', 'effective_until' => self::PROBI_UNTIL])->assertSessionHas('success');
        $this->addRate($this->h2, $this->e1, ['daily_rate' => '777', 'effective_date' => '2026-10-05'])->assertSessionHas('success');
        $this->rejectRate($this->c, $this->lastRate($this->e1)['id'], ['note' => self::NOTE])->assertSessionHas('success');
        $this->assertSame(3, DB::table('employee_rates')->where('employee_id', $this->e1->id)->where('status', '!=', 'approved')->count());

        $this->assertSame($baseline, $this->payroll());
        $item = PayrollItem::where('payroll_run_id', $this->run->id)->where('employee_id', $this->e1->id)->first();
        $this->assertStringNotContainsString('999', json_encode($item->daily_breakdown ?? []));
        $this->assertStringNotContainsString('888', json_encode($item->daily_breakdown ?? []));

        // Approved, it counts.
        $this->approveRate($this->c, $pendingNew)->assertSessionHas('success');
        $after = $this->payroll();
        $this->assertSame('999.00', number_format((float) $after['daily_rate'], 2, '.', ''));
    }

    #[TestDox('S-013.7 the CEO sees each pending rate change once: employee, old → new rate, dates, what will be shortened, who asked and when')]
    public function test_s_013_7_ceo_sees_the_list(): void
    {
        $this->travelTo('2026-11-13 15:10:00');
        $this->addRate($this->h, $this->e1)->assertSessionHas('success');
        $this->editRate($this->m, $this->e2, $this->lastRate($this->e2)['id'], ['daily_rate' => '540', 'effective_date' => '2026-01-01', 'remarks' => 'Typo'])
            ->assertSessionHas('success');

        $html = $this->actingAs($this->c)->get('/approvals/rates')->assertStatus(200)->getContent();

        $this->assertSame(2, substr_count($html, 'class="rate-request '));
        $this->assertStringContainsString('Employee One', $html);
        $this->assertStringContainsString('Employee Two', $html);
        $this->assertMatchesRegularExpression('/520\.00.*?→.*?600\.00/s', $html);
        $this->assertMatchesRegularExpression('/520\.00.*?→.*?540\.00/s', $html);
        $this->assertStringContainsString('Nov 16, 2026', $html);
        $this->assertStringContainsString('ends Nov 15, 2026', $html);
        $this->assertStringContainsString('Hr One', $html);
        $this->assertStringContainsString('Admin Tester', $html);
        $this->assertStringContainsString('Nov 13, 2026 3:10 PM', $html);
        $this->assertStringContainsString('id="navRateApprovalsCount">2<', $html);
    }

    #[TestDox('S-013.7 only the CEO opens the list or decides, whatever the feature permissions say')]
    public function test_s_013_7_only_the_ceo(): void
    {
        $this->addRate($this->h, $this->e1);
        $id = $this->lastRate($this->e1)['id'];

        foreach ([$this->h, $this->h2, $this->m] as $user) {
            $this->actingAs($user)->get('/approvals/rates')->assertStatus(403);
            $this->approveRate($user, $id)->assertStatus(403);
            $this->rejectRate($user, $id)->assertStatus(403);
        }
        $this->assertSame('pending', $this->lastRate($this->e1)['status']);
        $this->assertSame([['2026-01-01', '2027-06-30', '520.00']], $this->approvedRanges($this->e1));
    }

    #[TestDox('S-013.8 approving makes the rate count and shortens the "For Probi" rate at that moment')]
    public function test_s_013_8_approve_applies_the_rate(): void
    {
        $this->addRate($this->h, $this->e1);
        $id = $this->lastRate($this->e1)['id'];

        $this->approveRate($this->c, $id, ['note' => 'OK'])->assertRedirect()->assertSessionHas('success');

        $row = $this->lastRate($this->e1);
        $this->assertSame('approved', $row['status']);
        $this->assertSame($this->c->id, (int) $row['decided_by']);
        $this->assertNotNull($row['decided_at']);
        $this->assertSame('OK', $row['decision_note']);
        $this->assertSame($this->h->id, (int) $row['requested_by']);
        $this->assertSame([
            ['2026-01-01', '2026-11-15', '520.00'],
            ['2026-11-16', null, '600.00'],
        ], $this->approvedRanges($this->e1));

        // Decided once: a second approval or a rejection is refused.
        $this->approveRate($this->c, $id)->assertSessionHas('error');
        $this->rejectRate($this->c, $id)->assertSessionHas('error');
    }

    #[TestDox('S-013.8 approval checks the rates as they are then: a rate saved since on the same start date makes it refused, and it stays pending')]
    public function test_s_013_8_approve_revalidates(): void
    {
        $this->addRate($this->h, $this->e1);
        $id = $this->lastRate($this->e1)['id'];
        $this->addRate($this->c, $this->e1, ['daily_rate' => '610'])->assertSessionHas('success');
        $before = $this->rates($this->e1);

        $this->approveRate($this->c, $id)->assertSessionHas('error');

        $this->assertStringContainsString('Rate overlaps with existing rate (effective Nov 16, 2026', session('error'));
        $this->assertSame($before, $this->rates($this->e1));
        $this->assertSame('pending', DB::table('employee_rates')->where('id', $id)->value('status'));
    }

    #[TestDox('S-013.8 a request that cannot be saved is refused when asked, with the same message as for the CEO')]
    public function test_s_013_8_refused_when_asked(): void
    {
        $before = $this->rates($this->e1);

        $this->addRate($this->h, $this->e1, ['effective_until' => '2026-11-30'])->assertSessionHas('error');

        $this->assertSame($before, $this->rates($this->e1));
    }

    #[TestDox('S-013.9 rejecting changes nothing, and the person who asked sees "Rejected: note" on the rate list')]
    public function test_s_013_9_reject(): void
    {
        $this->addRate($this->h, $this->e1);
        $id = $this->lastRate($this->e1)['id'];
        $html = $this->actingAs($this->h)->get('/employees/' . $this->e1->id . '/edit')->assertStatus(200)->getContent();
        $this->assertStringContainsString('Waiting for approval', $html);

        $this->rejectRate($this->c, $id, ['note' => self::NOTE])->assertSessionHas('success');

        $row = $this->lastRate($this->e1);
        $this->assertSame('rejected', $row['status']);
        $this->assertSame($this->c->id, (int) $row['decided_by']);
        $this->assertSame(self::NOTE, $row['decision_note']);
        $this->assertSame([['2026-01-01', '2027-06-30', '520.00']], $this->approvedRanges($this->e1));
        $html = $this->actingAs($this->h)->get('/employees/' . $this->e1->id . '/edit')->getContent();
        $this->assertStringContainsString('Rejected: ' . self::NOTE, $html);
        $this->assertStringNotContainsString('Waiting for approval', $html);
    }

    #[TestDox('S-013.10 HR editing a rate that counts asks for the change: the rate stays as it is until approved, then it changes in place')]
    public function test_s_013_10_edit_by_hr(): void
    {
        $probi = $this->probiRateId();

        $this->editRate($this->h, $this->e1, $probi, ['daily_rate' => '560', 'effective_date' => '2026-01-01', 'effective_until' => self::PROBI_UNTIL, 'remarks' => 'Mali ang rate'])
            ->assertSessionHas('success');
        $this->assertSame([['2026-01-01', '2027-06-30', '520.00']], $this->approvedRanges($this->e1));
        $request = $this->lastRate($this->e1);
        $this->assertSame('pending', $request['status']);
        $this->assertSame($probi, (int) $request['replaces_rate_id']);
        $this->assertStringContainsString('Change waiting for approval', $this->actingAs($this->h)->get('/employees/' . $this->e1->id . '/edit')->getContent());

        // A second change of the same rate while one waits is refused.
        $this->editRate($this->h2, $this->e1, $probi, ['daily_rate' => '570', 'effective_date' => '2026-01-01', 'effective_until' => self::PROBI_UNTIL])
            ->assertSessionHas('error');

        $this->approveRate($this->c, $request['id'])->assertSessionHas('success');

        $this->assertSame([['2026-01-01', '2027-06-30', '560.00']], $this->approvedRanges($this->e1));
        $rows = $this->rates($this->e1);
        $this->assertCount(1, $rows, 'the request becomes the rate it changes; no second row is left');
        $this->assertSame($probi, (int) $rows[0]['id']);
        $this->assertSame('Mali ang rate', $rows[0]['remarks']);
        $this->assertSame($this->c->id, (int) $rows[0]['decided_by']);
    }

    #[TestDox('S-013.11 HR can withdraw a pending or rejected rate of their own, but cannot remove a rate that counts')]
    public function test_s_013_11_removing(): void
    {
        $this->addRate($this->h, $this->e1);
        $pending = $this->lastRate($this->e1)['id'];
        $this->actingAs($this->h)->delete('/employees/' . $this->e1->id . '/rate/' . $pending)->assertSessionHas('success');
        $this->assertNull(DB::table('employee_rates')->find($pending));

        $this->actingAs($this->h)->delete('/employees/' . $this->e1->id . '/rate/' . $this->probiRateId())->assertSessionHas('error');
        $this->assertSame([['2026-01-01', '2027-06-30', '520.00']], $this->approvedRanges($this->e1));

        // The CEO still removes a rate as before.
        $this->actingAs($this->c)->delete('/employees/' . $this->e2->id . '/rate/' . $this->lastRate($this->e2)['id'])->assertSessionHas('success');
        $this->assertSame([], $this->approvedRanges($this->e2));
    }

    #[TestDox('S-013.11 without the daily_rates edit permission nothing can be asked or changed (403, as before)')]
    public function test_s_013_11_permission_still_required(): void
    {
        DB::table('feature_permissions')->where('role', 'hr_staff')->where('feature_key', 'daily_rates')->update(['can_edit' => false]);
        $before = $this->rates($this->e1);

        $this->addRate($this->h, $this->e1)->assertStatus(403);
        $this->editRate($this->h, $this->e1, $this->probiRateId(), ['daily_rate' => '1', 'effective_date' => '2026-01-01'])->assertStatus(403);
        $this->actingAs($this->h)->post('/employees/bulk-action', [
            'employee_ids' => [$this->e1->id], 'action' => 'set_daily_rate', 'daily_rate' => '1', 'effective_date' => self::RAISE_FROM,
        ])->assertStatus(403);

        $this->assertSame($before, $this->rates($this->e1));
    }

    #[TestDox('S-013.12 the bulk "set daily rate" follows the same rules: HR asks for each employee, the CEO saves with the shortening')]
    public function test_s_013_12_bulk(): void
    {
        $bulk = fn ($user) => $this->actingAs($user)->post('/employees/bulk-action', [
            'employee_ids' => [$this->e1->id, $this->e2->id], 'action' => 'set_daily_rate', 'daily_rate' => '650', 'effective_date' => self::RAISE_FROM,
        ]);

        $bulk($this->h)->assertSessionHas('success');
        $this->assertSame(2, DB::table('employee_rates')->where('status', 'pending')->where('requested_by', $this->h->id)->count());
        $this->assertSame([['2026-01-01', '2027-06-30', '520.00']], $this->approvedRanges($this->e1));
        $this->assertSame([['2026-01-01', null, '520.00']], $this->approvedRanges($this->e2));

        DB::table('employee_rates')->where('status', 'pending')->delete();
        $bulk($this->c)->assertSessionHas('success');
        $this->assertSame([['2026-01-01', '2026-11-15', '520.00'], ['2026-11-16', null, '650.00']], $this->approvedRanges($this->e1));
        $this->assertSame([['2026-01-01', '2026-11-15', '520.00'], ['2026-11-16', null, '650.00']], $this->approvedRanges($this->e2));

        // A second run on the same date cannot be saved for anyone: each is skipped with its reason.
        $bulk($this->c)->assertSessionHas('error');
        $this->assertStringContainsString('Employee One', session('error'));
        $this->assertCount(2, $this->approvedRanges($this->e1));
    }

    #[TestDox('S-013.13 the audit log keeps created/updated and also records submitted, approved and rejected with who and when')]
    public function test_s_013_13_audit(): void
    {
        $this->travelTo('2026-11-13 09:00:00');
        $this->addRate($this->h, $this->e1);
        $approved = $this->lastRate($this->e1)['id'];
        $this->addRate($this->h2, $this->e2);
        $rejected = $this->lastRate($this->e2)['id'];

        $this->travelTo('2026-11-14 08:30:00');
        $this->approveRate($this->c, $approved)->assertSessionHas('success');
        $this->rejectRate($this->c, $rejected, ['note' => self::NOTE])->assertSessionHas('success');

        $this->assertCount(1, $this->auditRows('created', $approved));
        $submitted = $this->auditRows('submitted', $approved);
        $this->assertCount(1, $submitted);
        $this->assertSame($this->h->id, (int) $submitted[0]['user_id']);
        $this->assertStringStartsWith('2026-11-13 09:00', $submitted[0]['created_at']);
        $this->assertCount(1, $this->auditRows('submitted', $rejected));

        $yes = $this->auditRows('approved', $approved);
        $this->assertCount(1, $yes);
        $this->assertSame($this->c->id, (int) $yes[0]['user_id']);
        $this->assertStringStartsWith('2026-11-14 08:30', $yes[0]['created_at']);
        $no = $this->auditRows('rejected', $rejected);
        $this->assertCount(1, $no);
        $this->assertSame($this->c->id, (int) $no[0]['user_id']);
        $this->assertStringContainsString(self::NOTE, $no[0]['new_values']);
        // The shortening at approval is an update of the "For Probi" rate, by the CEO.
        $this->assertCount(1, $this->auditRows('updated', $this->probiRateId()));

        $this->actingAs($this->c)->get('/audit-logs?action=approved')->assertStatus(200)->assertSee('Approved');
    }

    #[TestDox('S-013.14 approving a rate does not recompute a payroll already computed; only a recompute uses it')]
    public function test_s_013_14_payroll_is_not_recomputed(): void
    {
        $this->addRate($this->h, $this->e1, ['daily_rate' => '999', 'effective_date' => '2026-10-01']);
        $id = $this->lastRate($this->e1)['id'];
        $this->payroll();
        $before = DB::table('payroll_items')->where('payroll_run_id', $this->run->id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        $this->travel(5)->minutes();
        $this->approveRate($this->c, $id)->assertSessionHas('success');

        $this->assertSame($before, DB::table('payroll_items')->where('payroll_run_id', $this->run->id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all());
    }
}
