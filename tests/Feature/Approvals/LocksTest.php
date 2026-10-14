<?php

namespace Tests\Feature\Approvals;

use App\Models\PayrollItem;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * S-18: a locked date takes no request and no approval.
 */
class LocksTest extends ApprovalsTestCase
{
    #[TestDox('S-18.1 on a locked date every entry point refuses HR staff and admins with 423 and writes nothing')]
    public function test_s_18_1_every_entry_point_refuses_a_locked_date(): void
    {
        $this->silSetup($this->e1, 5);
        $lockedDayId = DB::table('attendance_days')->insertGetId([
            'employee_id' => $this->e1->id, 'work_date' => self::DL, 'shift_id' => $this->shift->id,
            'time_in' => self::DL . ' 08:10:00',
            'created_at' => '2026-10-01 09:00:00', 'updated_at' => '2026-10-01 09:00:00',
        ]);
        DB::table('sil_applications')->insert([
            'employee_id' => $this->e2->id, 'sil_date' => self::DL, 'reason' => 'Naunang SIL',
            'created_at' => '2026-10-01 09:00:00', 'updated_at' => '2026-10-01 09:00:00',
        ]);
        $officialBefore = $this->official();
        $e1 = $this->e1->id;
        $e2 = $this->e2->id;
        $reason = self::REASON;

        $posts = [
            'saveAll'            => ['/attendance-calendar/save-all', ['employee_id' => $e1, 'date' => self::DL, 'reason' => $reason, 'time_in' => '08:00']],
            'overrideTime by id' => ['/attendance-calendar/override-time', ['attendance_day_id' => $lockedDayId, 'field' => 'time_in', 'new_value' => '08:00', 'reason' => $reason]],
            'overrideTime'       => ['/attendance-calendar/override-time', ['employee_id' => $e2, 'date' => self::DL, 'field' => 'time_in', 'new_value' => '08:00', 'reason' => $reason]],
            'createDay'          => ['/attendance-calendar/create-day', ['employee_id' => $e2, 'work_date' => self::DL]],
            'fillFromShift'      => ['/attendance-calendar/fill-from-shift', ['employee_id' => $e2, 'date' => self::DL, 'reason' => $reason]],
            'assignShiftForDate' => ['/attendance-calendar/assign-shift', ['employee_id' => $e1, 'date' => self::DL, 'shift_id' => $this->otherShift->id, 'reason' => $reason]],
            'applySil'           => ['/attendance-calendar/apply-sil', ['employee_id' => $e1, 'date' => self::DL, 'reason' => $reason]],
            'removeSil'          => ['/attendance-calendar/remove-sil', ['employee_id' => $e2, 'date' => self::DL, 'reason' => $reason]],
            'toggleDayOff'       => ['/attendance-calendar/toggle-day-off', ['employee_id' => $e1, 'date' => self::DL, 'action' => 'add_day_off', 'reason' => $reason]],
            'toggleDayOff no row' => ['/attendance-calendar/toggle-day-off', ['employee_id' => $e2, 'date' => self::DL, 'action' => 'add_day_off', 'reason' => $reason]],
            'bulk times'         => ['/attendance-calendar/bulk-action', ['date' => self::DL, 'employee_ids' => [$e1, $e2], 'action' => 'set_times', 'reason' => $reason, 'time_in' => '08:00']],
            'bulk day off'       => ['/attendance-calendar/bulk-action', ['date' => self::DL, 'employee_ids' => [$e1, $e2], 'action' => 'add_day_off', 'reason' => $reason]],
            'old override'       => ['/attendance/override', ['attendance_day_id' => $lockedDayId, 'field' => 'time_in', 'new_value' => '08:00', 'reason' => $reason]],
            'old override no row' => ['/attendance/override', ['employee_id' => $e2, 'work_date' => self::DL, 'field' => 'time_in', 'new_value' => '08:00', 'reason' => $reason]],
            'old create-day'     => ['/attendance/create-day', ['employee_id' => $e2, 'work_date' => self::DL]],
        ];

        foreach ([$this->h, $this->m] as $user) {
            foreach ($posts as $name => [$url, $body]) {
                $status = $this->actingAs($user)->postJson($url, $body)->getStatusCode();
                $this->assertSame(423, $status, "{$name} as {$user->role}");
            }
        }

        $this->assertSame(0, $this->requestCount());
        $this->assertSame($officialBefore, $this->official());
    }

    #[TestDox('S-18.2 a request whose date got locked cannot be approved and is flagged in the list and on the cell')]
    public function test_s_18_2_locked_after_the_request(): void
    {
        $this->askFourTimes();
        $this->askFourTimes($this->h, $this->e2, self::DX);
        // A single lock, and a range lock, as the CEO sets them.
        $this->actingAs($this->c)->postJson('/attendance-calendar/lock-date', ['date' => self::DN, 'reason' => 'Sarado na'])->assertStatus(200);
        $this->actingAs($this->c)->postJson('/attendance-calendar/lock-range', ['start_date' => self::DX, 'end_date' => '2026-10-09', 'reason' => 'Sarado na'])->assertStatus(200);
        $officialBefore = $this->official();

        foreach ([[$this->e1, self::DN], [$this->e2, self::DX]] as [$employee, $date]) {
            $response = $this->approve($this->c, $employee, $date);
            $response->assertStatus(423);
            $this->assertStringContainsString('This date is locked', $response->json('message'));
            $this->assertCount(4, $this->pending($employee, $date));
        }
        $this->assertSame($officialBefore, $this->official());

        $list = $this->actingAs($this->c)->get('/approvals')->getContent();
        $this->assertSame(2, substr_count($list, 'Date locked'));
        $this->assertSame(2, substr_count($list, 'class="btn btn-success approval-approve" disabled'));

        $html = $this->calendar($this->h);
        $this->assertStringContainsString('data-pending-count="4"', $this->cell($html, $this->e1, self::DN));
        preg_match('/<td class="[^"]*"\s+data-date="' . self::DN . '"[^>]*data-employee-id="' . $this->e1->id . '"/', $html, $m, PREG_OFFSET_CAPTURE);
        $inside = substr($html, $m[0][1], strpos($html, '</td>', $m[0][1]) - $m[0][1]);
        $this->assertStringContainsString('Pending (date locked)', $inside);
    }

    #[TestDox('S-18.3 on a date that got locked the CEO can reject, the requester can withdraw but not change')]
    public function test_s_18_3_reject_and_withdraw_on_a_locked_date(): void
    {
        $this->saveAll($this->h, $this->e1, self::DN, ['time_in' => '08:00']);
        $this->saveAll($this->h, $this->e2, self::DN, ['time_in' => '08:00']);
        $this->lock(self::DN);
        $ofE2 = $this->anchorId($this->e2, self::DN);

        $this->actingAs($this->h)->postJson("/attendance-requests/{$ofE2}/change", ['new_value' => '08:30', 'reason' => 'Itinama ang oras'])->assertStatus(423);
        $this->assertSame('08:00:00', $this->pending($this->e2, self::DN)['time_in']['new_value']);

        $this->reject($this->c, $this->e1, self::DN)->assertStatus(200);
        $this->actingAs($this->h)->postJson("/attendance-requests/{$ofE2}/withdraw")->assertStatus(200);

        $this->assertSame(1, $this->requestCount('rejected'));
        $this->assertSame(1, $this->requestCount('withdrawn'));
        $this->assertNull($this->day($this->e1, self::DN));
    }

    #[TestDox('S-18.4 after an unlock the request can be approved again')]
    public function test_s_18_4_unlock_makes_it_approvable(): void
    {
        $this->askFourTimes();
        $this->lock(self::DN);
        $this->approve($this->c, $this->e1, self::DN)->assertStatus(423);

        $this->actingAs($this->c)->postJson('/attendance-calendar/unlock-date', ['date' => self::DN])->assertStatus(200);
        $this->approve($this->c, $this->e1, self::DN)->assertStatus(200);

        $this->assertSame(self::DN . ' 08:00:00', $this->day($this->e1, self::DN)['time_in']);
    }

    #[TestDox('S-18.5 finalizing a payroll run locks its dates: the pending request stays, uncounted in the run')]
    public function test_s_18_5_finalized_run_locks_the_request(): void
    {
        $this->unlock(self::DL);
        $this->askFourTimes();
        $computed = $this->payroll();

        $this->actingAs($this->c)->post("/payroll/{$this->run->id}/finalize")->assertStatus(302);

        $this->assertSame('final', DB::table('payroll_runs')->where('id', $this->run->id)->value('status'));
        $this->approve($this->c, $this->e1, self::DN)->assertStatus(423);
        $this->assertNull($this->day($this->e1, self::DN));
        $this->assertCount(4, $this->pending($this->e1, self::DN));

        $stored = collect(PayrollItem::where('payroll_run_id', $this->run->id)->where('employee_id', $this->e1->id)->first()->getAttributes())
            ->except(['id', 'created_at', 'updated_at'])->all();
        $this->assertSame($computed, $stored, 'the finalized payroll has the day as it was');
        $this->assertEquals(1, $stored['days_worked']);

        $list = $this->actingAs($this->c)->get('/approvals')->getContent();
        $this->assertStringContainsString('id="navApprovalsCount">1<', $list);
        $this->assertStringContainsString('Date locked', $list);
    }

    #[TestDox('S-18.6 the payroll run page warns about pending requests in its period and finalizing is not blocked')]
    public function test_s_18_6_finalize_warns_and_does_not_block(): void
    {
        $this->unlock(self::DL);
        $this->payroll();
        $none = $this->actingAs($this->c)->get("/payroll/{$this->run->id}")->assertStatus(200);
        $none->assertDontSee('pending attendance request');

        $this->askFourTimes();
        $this->saveAll($this->h, $this->e2, self::DX, ['time_in' => '08:00']);
        // Outside the period of the run: not counted.
        $this->saveAll($this->h, $this->e2, '2026-10-20', ['time_in' => '08:00']);

        $page = $this->actingAs($this->c)->get("/payroll/{$this->run->id}")->assertStatus(200);
        $page->assertSee('2 pending attendance requests');
        $this->assertStringContainsString('id="pendingRequestsWarning"', $page->getContent());

        $this->actingAs($this->c)->post("/payroll/{$this->run->id}/finalize")->assertStatus(302)->assertSessionHas('success');
        $this->assertSame('final', DB::table('payroll_runs')->where('id', $this->run->id)->value('status'));
        $this->assertSame(6, $this->requestCount('pending'), 'finalizing decides nothing');
    }

    #[TestDox('S-18.7 a lock on another date does not block, and the lock is checked on the work date, not on today')]
    public function test_s_18_7_only_the_work_date_counts(): void
    {
        // Dl is locked (another date). Today is locked too.
        $this->travelTo(\Carbon\Carbon::parse('2026-10-12 10:00:00'));
        $this->lock('2026-10-12');

        $this->askFourTimes()->assertStatus(200)->assertJson(['pending' => true]);
        $this->approve($this->c, $this->e1, self::DN)->assertStatus(200);
        $this->assertNotNull($this->day($this->e1, self::DN));

        // A lock in another period does not block this one either.
        $this->lock('2026-11-03');
        $this->saveAll($this->h, $this->e1, self::DX, ['time_in' => '08:00'])->assertStatus(200);
        $this->approve($this->c, $this->e1, self::DX)->assertStatus(200);
    }
}
