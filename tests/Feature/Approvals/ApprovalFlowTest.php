<?php

namespace Tests\Feature\Approvals;

use App\Models\AttendanceDay;
use App\Models\User;
use App\Services\AttendanceComputeService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * S-11 (times), S-13, S-14, S-16, S-17.1 to S-17.3, S-17.6 to S-17.8:
 * approving, rejecting, changing and withdrawing a request for times.
 */
class ApprovalFlowTest extends ApprovalsTestCase
{
    /* ── S-11 The CEO approves ── */

    #[TestDox('S-11.1 an approved request becomes the day the CEO would have entered')]
    public function test_s_11_1_approval_equals_a_direct_entry(): void
    {
        $this->askFourTimes();
        // The control: the CEO enters the same times directly for another employee.
        $this->askFourTimes($this->c, $this->e2);

        $this->approve($this->c, $this->e1, self::DN)->assertStatus(200)->assertJson(['success' => true]);

        $day = $this->day($this->e1, self::DN);
        $control = $this->day($this->e2, self::DN);
        $this->assertNotNull($day);
        foreach (['time_in', 'lunch_out', 'lunch_in', 'time_out', 'computed_work_minutes', 'computed_late_minutes',
                     'computed_early_minutes', 'computed_overtime_minutes', 'payable_work_minutes', 'shift_id'] as $column) {
            $this->assertSame($control[$column], $day[$column], $column);
        }
        $this->assertSame(self::DN . ' 08:00:00', $day['time_in']);
        $this->assertSame(480, (int) $day['computed_work_minutes']);

        $overrides = DB::table('attendance_overrides')->where('attendance_day_id', $day['id'])->orderBy('id')->get();
        $this->assertSame(['time_in', 'lunch_out', 'lunch_in', 'time_out'], $overrides->pluck('field')->all());
        foreach ($overrides as $override) {
            $this->assertSame(self::REASON, $override->reason);
            $this->assertEquals($this->c->id, $override->updated_by);
        }

        $requests = DB::table('attendance_change_requests')->where('employee_id', $this->e1->id)->get();
        $this->assertCount(4, $requests);
        foreach ($requests as $request) {
            $this->assertSame('approved', $request->status);
            $this->assertEquals($this->c->id, $request->decided_by);
            $this->assertNotNull($request->decided_at);
            $this->assertEquals($this->h->id, $request->requested_by);
            $this->assertNull($request->pending_marker);
            $this->assertSame('attendance_override', $request->applied_type);
        }

        $cell = $this->cell($this->calendar($this->h), $this->e1, self::DN);
        $this->assertStringNotContainsString('data-pending-count', $cell);
        $this->assertStringContainsString('data-time-in="08:00:00"', $cell);

        $this->assertEquals(2, $this->payroll()['days_worked'], 'Dr and the approved Dn');
    }

    #[TestDox('S-11.2 an approved time_in request changes only time_in')]
    public function test_s_11_2_only_the_requested_field_changes(): void
    {
        $before = $this->day($this->e1, self::DR);
        $this->saveAll($this->h, $this->e1, self::DR, ['time_in' => '08:00']);

        $this->approve($this->c, $this->e1, self::DR)->assertStatus(200);

        $after = $this->day($this->e1, self::DR);
        $this->assertSame(self::DR . ' 08:00:00', $after['time_in']);
        foreach (['lunch_out', 'lunch_in', 'time_out', 'shift_id', 'approved_overtime_minutes'] as $column) {
            $this->assertSame($before[$column], $after[$column], $column);
        }
        $overrides = DB::table('attendance_overrides')->get();
        $this->assertCount(1, $overrides);
        $this->assertSame('time_in', $overrides[0]->field);
        $this->assertSame('08:03:00', $overrides[0]->old_value);
        $this->assertSame('08:00:00', $overrides[0]->new_value);
    }

    #[TestDox('S-11.4 the history of the day shows who asked and who approved, with the reason')]
    public function test_s_11_4_history_names_requester_and_approver(): void
    {
        $this->saveAll($this->h, $this->e1, self::DR, ['time_in' => '08:00']);
        $this->approve($this->c, $this->e1, self::DR)->assertStatus(200);

        $cell = $this->cell($this->calendar($this->h), $this->e1, self::DR);
        $this->assertStringContainsString('"requested_by":"Hr One"', $cell);
        $this->assertStringContainsString('"approved_by":"Ceo Tester"', $cell);
        $this->assertStringContainsString(self::REASON, $cell);

        $decided = $this->actingAs($this->c)->get('/approvals?status=approved')->assertStatus(200);
        $decided->assertSee('Hr One')->assertSee('Ceo Tester')->assertSee(self::REASON);
    }

    #[TestDox('S-11.5 an approval on a date that is locked by then is refused and writes nothing')]
    public function test_s_11_5_locked_at_approval_time(): void
    {
        $this->askFourTimes();
        $this->lock(self::DN);
        $officialBefore = $this->official();

        $response = $this->approve($this->c, $this->e1, self::DN);

        $response->assertStatus(423);
        $this->assertStringContainsString('locked', $response->json('message'));
        $this->assertCount(4, $this->pending($this->e1, self::DN));
        $this->assertSame($officialBefore, $this->official());
    }

    #[TestDox('S-11.6 an approval that fails halfway writes nothing and leaves the request pending')]
    public function test_s_11_6_failed_approval_writes_nothing(): void
    {
        $this->askFourTimes();
        $officialBefore = $this->official();

        $this->app->bind(AttendanceComputeService::class, fn () => new class extends AttendanceComputeService {
            public function recomputeDay(AttendanceDay $day): AttendanceDay
            {
                throw new \RuntimeException('the recompute broke');
            }
        });

        $response = $this->approve($this->c, $this->e1, self::DN);

        $response->assertStatus(500)->assertJson(['success' => false]);
        $this->assertNotEmpty($response->json('message'));
        $this->assertNull($this->day($this->e1, self::DN));
        $this->assertSame($officialBefore, $this->official());
        $this->assertCount(4, $this->pending($this->e1, self::DN));
    }

    #[TestDox('S-11.7 an approved clear makes the field empty and logs the old value')]
    public function test_s_11_7_approved_clear(): void
    {
        $this->saveAll($this->h, $this->e1, self::DR, ['clear_fields' => ['time_out']]);

        $this->approve($this->c, $this->e1, self::DR)->assertStatus(200);

        $this->assertNull($this->day($this->e1, self::DR)['time_out']);
        $override = DB::table('attendance_overrides')->first();
        $this->assertSame('time_out', $override->field);
        $this->assertSame('17:00:00', $override->old_value);
        $this->assertNull($override->new_value);
    }

    #[TestDox('S-11.8 a later import keeps the approved fields as overrides')]
    public function test_s_11_8_later_import_keeps_approved_fields(): void
    {
        $this->askFourTimes();
        $this->approve($this->c, $this->e1, self::DN)->assertStatus(200);

        // Punches arrive afterwards and the day is computed again, as an import does.
        $this->punchedDay($this->e1, self::DN, ['08:20:00', '12:10:00', '12:50:00', '16:40:00']);

        $day = $this->day($this->e1, self::DN);
        $this->assertSame(self::DN . ' 08:00:00', $day['time_in']);
        $this->assertSame(self::DN . ' 12:00:00', $day['lunch_out']);
        $this->assertSame(self::DN . ' 13:00:00', $day['lunch_in']);
        $this->assertSame(self::DN . ' 17:00:00', $day['time_out']);
        $this->assertSame(1, DB::table('attendance_days')->where('employee_id', $this->e1->id)->whereDate('work_date', self::DN)->count());
    }

    #[TestDox('S-11.9 a request that is already approved, rejected or withdrawn cannot be approved')]
    public function test_s_11_9_decided_request_cannot_be_approved(): void
    {
        // Approved.
        $this->saveAll($this->h, $this->e1, self::DR, ['time_in' => '08:00']);
        $id = $this->anchorId($this->e1, self::DR);
        $seen = $this->seen($this->e1, self::DR);
        $this->approve($this->c, $this->e1, self::DR)->assertStatus(200);
        $officialBefore = $this->official();
        $again = $this->actingAs($this->c)->postJson("/approvals/{$id}/approve", ['seen' => $seen]);
        $again->assertStatus(409);
        $this->assertStringContainsString('lready approved', $again->json('message'));
        $this->assertSame($officialBefore, $this->official());

        // Rejected.
        $this->askFourTimes();
        $id = $this->anchorId($this->e1, self::DN);
        $seen = $this->seen($this->e1, self::DN);
        $this->reject($this->c, $this->e1, self::DN)->assertStatus(200);
        $this->actingAs($this->c)->postJson("/approvals/{$id}/approve", ['seen' => $seen])->assertStatus(409);

        // Withdrawn.
        $this->saveAll($this->h, $this->e2, self::DN, ['time_in' => '08:00']);
        $id = $this->anchorId($this->e2, self::DN);
        $seen = $this->seen($this->e2, self::DN);
        $this->actingAs($this->h)->postJson("/attendance-requests/{$id}/withdraw")->assertStatus(200);
        $this->actingAs($this->c)->postJson("/approvals/{$id}/approve", ['seen' => $seen])->assertStatus(409);

        $this->assertSame($officialBefore, $this->official());
    }

    #[TestDox('S-11.10 an entry with three fields is applied as a whole or not at all')]
    public function test_s_11_10_entry_is_applied_together(): void
    {
        $this->saveAll($this->h, $this->e1, self::DN, ['time_in' => '08:00', 'lunch_out' => '12:00', 'time_out' => '17:00']);

        // Approving with one of the three unseen is refused whole.
        $partial = collect(explode(',', $this->seen($this->e1, self::DN)))->take(2)->implode(',');
        $this->approve($this->c, $this->e1, self::DN, ['seen' => $partial])->assertStatus(409);
        $this->assertNull($this->day($this->e1, self::DN));
        $this->assertCount(3, $this->pending($this->e1, self::DN));

        $this->approve($this->c, $this->e1, self::DN)->assertStatus(200);
        $day = $this->day($this->e1, self::DN);
        $this->assertSame(self::DN . ' 08:00:00', $day['time_in']);
        $this->assertSame(self::DN . ' 12:00:00', $day['lunch_out']);
        $this->assertSame(self::DN . ' 17:00:00', $day['time_out']);
        $this->assertSame(3, DB::table('attendance_overrides')->count());
        $this->assertSame(3, $this->requestCount('approved'));
    }

    #[TestDox('S-11.11 each field of a shared entry keeps its own requester and reason')]
    public function test_s_11_11_shared_entry_names_each_requester(): void
    {
        $this->saveAll($this->h, $this->e1, self::DN, ['time_in' => '08:00'], 'Dahilan ni una');
        $this->saveAll($this->h2, $this->e1, self::DN, ['time_out' => '17:00'], 'Dahilan ni pangalawa');

        $this->approve($this->c, $this->e1, self::DN)->assertStatus(200);

        $overrides = DB::table('attendance_overrides')->get()->keyBy('field');
        $this->assertSame('Dahilan ni una', $overrides['time_in']->reason);
        $this->assertSame('Dahilan ni pangalawa', $overrides['time_out']->reason);
        $this->assertEquals($this->c->id, $overrides['time_in']->updated_by);

        $requests = DB::table('attendance_change_requests')->get()->keyBy('field');
        $this->assertEquals($overrides['time_in']->id, $requests['time_in']->applied_id);
        $this->assertEquals($this->h->id, $requests['time_in']->requested_by);
        $this->assertEquals($overrides['time_out']->id, $requests['time_out']->applied_id);
        $this->assertEquals($this->h2->id, $requests['time_out']->requested_by);

        $cell = $this->cell($this->calendar($this->c), $this->e1, self::DN);
        $this->assertMatchesRegularExpression('/"field":"time_in"[^}]*"requested_by":"Hr One"[^}]*"approved_by":"Ceo Tester"/', $cell);
        $this->assertMatchesRegularExpression('/"field":"time_out"[^}]*"requested_by":"Hr Two"[^}]*"approved_by":"Ceo Tester"/', $cell);
    }

    /* ── S-13 The CEO rejects ── */

    #[TestDox('S-13.1 a rejection stores the reason and changes nothing official')]
    public function test_s_13_1_rejection_changes_nothing_official(): void
    {
        $this->saveAll($this->h, $this->e1, self::DR, ['time_in' => '08:00']);
        $officialBefore = $this->official();
        $payrollBefore = $this->payroll();

        $this->reject($this->c, $this->e1, self::DR, 'Walang katibayan')->assertStatus(200)->assertJson(['success' => true]);

        $request = DB::table('attendance_change_requests')->first();
        $this->assertSame('rejected', $request->status);
        $this->assertSame('Walang katibayan', $request->decision_reason);
        $this->assertEquals($this->c->id, $request->decided_by);
        $this->assertNotNull($request->decided_at);
        $this->assertNull($request->pending_marker);
        $this->assertSame($officialBefore, $this->official());
        $this->assertSame($payrollBefore, $this->payroll());
    }

    #[TestDox('S-13.2 the requester reads "Rejected: reason" with the values asked for, and no mail is sent')]
    public function test_s_13_2_requester_sees_the_rejection(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        \Illuminate\Support\Facades\Notification::fake();
        $this->saveAll($this->h, $this->e1, self::DR, ['time_in' => '08:00']);
        $this->reject($this->c, $this->e1, self::DR, 'Walang katibayan');

        $html = $this->calendar($this->h);
        $cell = $this->cell($html, $this->e1, self::DR);

        $this->assertStringContainsString('"status":"rejected"', $cell);
        $this->assertStringContainsString('Walang katibayan', $cell);
        $this->assertStringContainsString('8:00 AM', $cell);
        $this->assertStringNotContainsString('data-pending-count', $cell);
        $this->assertStringContainsString("'Rejected: '", $html, 'the modal script writes the notice');
        \Illuminate\Support\Facades\Mail::assertNothingSent();
        \Illuminate\Support\Facades\Notification::assertNothingSent();
    }

    #[TestDox('S-13.3 a rejection without a reason of at least three characters is refused')]
    public function test_s_13_3_rejection_needs_a_reason(): void
    {
        $this->askFourTimes();

        $this->reject($this->c, $this->e1, self::DN, '')->assertStatus(422);
        $this->reject($this->c, $this->e1, self::DN, 'ab')->assertStatus(422);

        $this->assertCount(4, $this->pending($this->e1, self::DN));
    }

    #[TestDox('S-13.4 a request already decided or withdrawn cannot be rejected')]
    public function test_s_13_4_decided_request_cannot_be_rejected(): void
    {
        $this->saveAll($this->h, $this->e1, self::DR, ['time_in' => '08:00']);
        $id = $this->anchorId($this->e1, self::DR);
        $seen = $this->seen($this->e1, self::DR);
        $this->approve($this->c, $this->e1, self::DR)->assertStatus(200);
        $officialBefore = $this->official();

        $this->actingAs($this->c)->postJson("/approvals/{$id}/reject", ['seen' => $seen, 'reason' => 'Huli na'])->assertStatus(409);

        $this->assertSame('approved', DB::table('attendance_change_requests')->value('status'));
        $this->assertSame($officialBefore, $this->official());

        $this->saveAll($this->h, $this->e2, self::DN, ['time_in' => '08:00']);
        $id = $this->anchorId($this->e2, self::DN);
        $seen = $this->seen($this->e2, self::DN);
        $this->actingAs($this->h)->postJson("/attendance-requests/{$id}/withdraw")->assertStatus(200);
        $this->actingAs($this->c)->postJson("/approvals/{$id}/reject", ['seen' => $seen, 'reason' => 'Huli na'])->assertStatus(409);
        $this->assertSame('withdrawn', DB::table('attendance_change_requests')->where('id', $id)->value('status'));
    }

    #[TestDox('S-13.5 an entry on a locked date can still be rejected')]
    public function test_s_13_5_reject_on_a_locked_date(): void
    {
        $this->askFourTimes();
        $this->lock(self::DN);

        $this->reject($this->c, $this->e1, self::DN)->assertStatus(200);

        $this->assertSame(4, $this->requestCount('rejected'));
    }

    #[TestDox('S-13.6 after a rejection the requester can ask again and the notice is replaced')]
    public function test_s_13_6_ask_again_after_a_rejection(): void
    {
        $this->saveAll($this->h, $this->e1, self::DR, ['time_in' => '08:00']);
        $this->reject($this->c, $this->e1, self::DR, 'Walang katibayan');

        $this->saveAll($this->h, $this->e1, self::DR, ['time_in' => '08:01'])->assertStatus(200);

        $this->assertSame(1, $this->requestCount('rejected'));
        $this->assertSame(1, $this->requestCount('pending'));
        $cell = $this->cell($this->calendar($this->h), $this->e1, self::DR);
        $this->assertStringNotContainsString('Walang katibayan', $cell);
        $this->assertStringContainsString('data-pending-count="1"', $cell);
        $this->actingAs($this->c)->get('/dashboard')->assertSee('id="navApprovalsCount">1<', false);
    }

    #[TestDox('S-13.7 a 501-character rejection reason is refused and a Filipino reason is shown intact')]
    public function test_s_13_7_rejection_reason_length_and_characters(): void
    {
        $this->saveAll($this->h, $this->e1, self::DR, ['time_in' => '08:00']);

        $this->reject($this->c, $this->e1, self::DR, str_repeat('a', 501))->assertStatus(422);
        $this->assertSame(1, $this->requestCount('pending'));

        $reason = 'Hindi tugma sa talaan ng guwardiya, Ñoño';
        $this->reject($this->c, $this->e1, self::DR, $reason)->assertStatus(200);
        $this->assertStringContainsString($reason, $this->cell($this->calendar($this->h), $this->e1, self::DR));
    }

    #[TestDox('S-13.8 rejecting an entry with two fields rejects both with the same reason')]
    public function test_s_13_8_reject_covers_every_field(): void
    {
        $this->saveAll($this->h, $this->e1, self::DR, ['time_in' => '08:00', 'time_out' => '17:30']);

        $this->reject($this->c, $this->e1, self::DR, 'Walang katibayan')->assertStatus(200);

        $rows = DB::table('attendance_change_requests')->get();
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame('rejected', $row->status);
            $this->assertSame('Walang katibayan', $row->decision_reason);
        }
    }

    /* ── S-14 The requester changes or withdraws ── */

    #[TestDox('S-14.1 the requester changes a pending request: same id, new values, changed at set')]
    public function test_s_14_1_requester_changes_the_request(): void
    {
        $this->saveAll($this->h, $this->e1, self::DN, ['time_in' => '08:00']);
        $id = $this->anchorId($this->e1, self::DN);

        $this->actingAs($this->h)->postJson("/attendance-requests/{$id}/change", [
            'new_value' => '08:20', 'reason' => 'Itinama ang oras',
        ])->assertStatus(200)->assertJson(['success' => true]);

        $row = $this->pending($this->e1, self::DN)['time_in'];
        $this->assertEquals($id, $row['id']);
        $this->assertSame('08:20:00', $row['new_value']);
        $this->assertSame('Itinama ang oras', $row['reason']);
        $this->assertNotNull($row['changed_at']);
        $this->assertEquals(2, $row['version']);
        $this->actingAs($this->c)->get('/approvals')->assertSee('8:20 AM')->assertSee('Itinama ang oras');
    }

    #[TestDox('S-14.2 a withdrawn request leaves the list and the count and the cell goes back')]
    public function test_s_14_2_requester_withdraws(): void
    {
        $this->saveAll($this->h, $this->e1, self::DN, ['time_in' => '08:00']);
        $id = $this->anchorId($this->e1, self::DN);

        $this->actingAs($this->h)->postJson("/attendance-requests/{$id}/withdraw")->assertStatus(200)->assertJson(['success' => true]);

        $this->assertSame('withdrawn', DB::table('attendance_change_requests')->value('status'));
        $this->assertNull($this->day($this->e1, self::DN));
        $cell = $this->cell($this->calendar($this->h), $this->e1, self::DN);
        $this->assertStringContainsString('data-status="absent"', $cell);
        $this->assertStringNotContainsString('data-pending-count', $cell);
        $page = $this->actingAs($this->c)->get('/approvals')->assertStatus(200);
        $page->assertDontSee('class="approval-entry', false)->assertDontSee('id="navApprovalsCount"', false);
    }

    #[TestDox('S-14.3 nobody but the requester can change or withdraw a request')]
    public function test_s_14_3_other_users_cannot_change_or_withdraw(): void
    {
        $this->saveAll($this->h, $this->e1, self::DN, ['time_in' => '08:00']);
        $this->saveAll($this->h2, $this->e1, self::DN, ['time_out' => '17:00']);
        $pending = $this->pending($this->e1, self::DN);
        $ofH = $pending['time_in']['id'];
        $ofH2 = $pending['time_out']['id'];

        foreach ([$this->h2, $this->m] as $other) {
            $this->actingAs($other)->postJson("/attendance-requests/{$ofH}/change", ['new_value' => '09:00', 'reason' => 'Hindi akin'])->assertStatus(403);
            $this->actingAs($other)->postJson("/attendance-requests/{$ofH}/withdraw")->assertStatus(403);
        }
        $this->actingAs($this->h)->postJson("/attendance-requests/{$ofH2}/withdraw")->assertStatus(403);
        $this->assertSame($pending, $this->pending($this->e1, self::DN));

        // Each can act on their own field of the shared entry.
        $this->actingAs($this->h2)->postJson("/attendance-requests/{$ofH2}/withdraw")->assertStatus(200);
        $this->assertSame(['time_in'], array_keys($this->pending($this->e1, self::DN)));
    }

    #[TestDox('S-14.4 a decided request can be neither changed nor withdrawn')]
    public function test_s_14_4_decided_request_cannot_be_changed(): void
    {
        $this->saveAll($this->h, $this->e1, self::DR, ['time_in' => '08:00']);
        $approved = $this->anchorId($this->e1, self::DR);
        $this->approve($this->c, $this->e1, self::DR)->assertStatus(200);
        $this->saveAll($this->h, $this->e1, self::DN, ['time_in' => '08:00']);
        $rejected = $this->anchorId($this->e1, self::DN);
        $this->reject($this->c, $this->e1, self::DN);
        $officialBefore = $this->official();

        foreach ([$approved, $rejected] as $id) {
            $change = $this->actingAs($this->h)->postJson("/attendance-requests/{$id}/change", ['new_value' => '09:00', 'reason' => 'Huli na ito']);
            $change->assertStatus(409);
            $this->assertStringContainsString('already decided', $change->json('message'));
            $this->actingAs($this->h)->postJson("/attendance-requests/{$id}/withdraw")->assertStatus(409);
        }

        $this->assertSame('approved', DB::table('attendance_change_requests')->where('id', $approved)->value('status'));
        $this->assertSame('rejected', DB::table('attendance_change_requests')->where('id', $rejected)->value('status'));
        $this->assertSame($officialBefore, $this->official());
    }

    #[TestDox('S-14.5 on a date that got locked a request can be withdrawn but not changed')]
    public function test_s_14_5_locked_date_allows_withdraw_only(): void
    {
        $this->saveAll($this->h, $this->e1, self::DN, ['time_in' => '08:00']);
        $id = $this->anchorId($this->e1, self::DN);
        $this->lock(self::DN);

        $this->actingAs($this->h)->postJson("/attendance-requests/{$id}/change", ['new_value' => '08:20', 'reason' => 'Itinama ang oras'])->assertStatus(423);
        $this->saveAll($this->h, $this->e1, self::DN, ['time_in' => '08:20'])->assertStatus(423);
        $this->assertSame('08:00:00', $this->pending($this->e1, self::DN)['time_in']['new_value']);

        $this->actingAs($this->h)->postJson("/attendance-requests/{$id}/withdraw")->assertStatus(200);
        $this->assertSame('withdrawn', DB::table('attendance_change_requests')->value('status'));
    }

    #[TestDox('S-14.6 a change without a reason or with a bad time is refused and the request is kept')]
    public function test_s_14_6_bad_change_is_refused(): void
    {
        $this->saveAll($this->h, $this->e1, self::DN, ['time_in' => '08:00']);
        $id = $this->anchorId($this->e1, self::DN);
        $before = $this->pending($this->e1, self::DN);

        $this->actingAs($this->h)->postJson("/attendance-requests/{$id}/change", ['new_value' => '08:20'])->assertStatus(422);
        $this->actingAs($this->h)->postJson("/attendance-requests/{$id}/change", ['new_value' => '25:61', 'reason' => 'Itinama ang oras'])->assertStatus(422);

        $this->assertSame($before, $this->pending($this->e1, self::DN));
    }

    #[TestDox('S-14.7 a change to the official value is refused as "No changes" and the request is kept')]
    public function test_s_14_7_change_to_the_official_value(): void
    {
        $this->saveAll($this->h, $this->e1, self::DR, ['time_in' => '08:00']);
        $id = $this->anchorId($this->e1, self::DR);
        $before = $this->pending($this->e1, self::DR);

        $response = $this->actingAs($this->h)->postJson("/attendance-requests/{$id}/change", ['new_value' => '08:03', 'reason' => 'Ibinalik sa dati']);

        $response->assertStatus(422);
        $this->assertStringContainsString('No changes', $response->json('message'));
        $this->assertSame($before, $this->pending($this->e1, self::DR));
    }

    #[TestDox('S-14.8 a withdrawal sent twice ends withdrawn without an error')]
    public function test_s_14_8_double_withdraw(): void
    {
        $this->saveAll($this->h, $this->e1, self::DN, ['time_in' => '08:00']);
        $id = $this->anchorId($this->e1, self::DN);

        $this->actingAs($this->h)->postJson("/attendance-requests/{$id}/withdraw")->assertStatus(200);
        $this->actingAs($this->h)->postJson("/attendance-requests/{$id}/withdraw")->assertStatus(200);

        $this->assertSame('withdrawn', DB::table('attendance_change_requests')->value('status'));
    }

    #[TestDox('S-14.9 after a withdrawal the requester can ask again as a new request')]
    public function test_s_14_9_ask_again_after_a_withdrawal(): void
    {
        $this->saveAll($this->h, $this->e1, self::DN, ['time_in' => '08:00']);
        $id = $this->anchorId($this->e1, self::DN);
        $this->actingAs($this->h)->postJson("/attendance-requests/{$id}/withdraw");

        $this->saveAll($this->h, $this->e1, self::DN, ['time_in' => '08:00'])->assertStatus(200);

        $new = $this->pending($this->e1, self::DN)['time_in'];
        $this->assertNotEquals($id, $new['id']);
        $this->assertSame(2, $this->requestCount());
    }

    #[TestDox('S-14.10 the CEO cannot withdraw a request, only reject it')]
    public function test_s_14_10_ceo_cannot_withdraw(): void
    {
        $this->saveAll($this->h, $this->e1, self::DN, ['time_in' => '08:00']);
        $id = $this->anchorId($this->e1, self::DN);

        $this->actingAs($this->c)->postJson("/attendance-requests/{$id}/withdraw")->assertStatus(403);
        $this->assertSame(1, $this->requestCount('pending'));

        $this->reject($this->c, $this->e1, self::DN)->assertStatus(200);
    }

    #[TestDox('a SIL or rest-day request cannot be changed, only withdrawn and asked again')]
    public function test_sil_and_rest_day_requests_cannot_be_changed(): void
    {
        $this->silSetup($this->e2, 5);
        $this->toggleDayOff($this->h, $this->e1, self::DR, 'add_day_off');
        $this->applySil($this->h, $this->e2, self::DN);
        $restDay = $this->pending($this->e1, self::DR)['rest_day'];
        $sil = $this->pending($this->e2, self::DN)['sil'];

        foreach ([$restDay, $sil] as $row) {
            // With and without a value: neither may empty or alter what was asked.
            $this->actingAs($this->h)->postJson("/attendance-requests/{$row['id']}/change", ['reason' => 'Binago ko'])->assertStatus(422);
            $this->actingAs($this->h)->postJson("/attendance-requests/{$row['id']}/change", ['new_value' => 'remove', 'reason' => 'Binago ko'])->assertStatus(422);
        }

        $this->assertSame($restDay, $this->pending($this->e1, self::DR)['rest_day']);
        $this->assertSame($sil, $this->pending($this->e2, self::DN)['sil']);
        $this->approve($this->c, $this->e1, self::DR)->assertStatus(200);
        $this->approve($this->c, $this->e2, self::DN)->assertStatus(200);
    }

    /* ── S-16 Two people acting at once ── */

    #[TestDox('S-16.1 a second approval of the same entry gets "already approved" and writes nothing more')]
    public function test_s_16_1_double_approval_applies_once(): void
    {
        $this->askFourTimes();
        $id = $this->anchorId($this->e1, self::DN);
        $seen = $this->seen($this->e1, self::DN);

        $this->actingAs($this->c)->postJson("/approvals/{$id}/approve", ['seen' => $seen])->assertStatus(200);
        $officialAfterFirst = $this->official();
        $second = $this->actingAs($this->c)->postJson("/approvals/{$id}/approve", ['seen' => $seen]);

        $second->assertStatus(409);
        $this->assertStringContainsString('lready approved', $second->json('message'));
        $this->assertSame($officialAfterFirst, $this->official());
        $this->assertSame(1, $this->dayCount($this->e1) - 1, 'Dr and one row for Dn');
        $this->assertSame(4, DB::table('attendance_overrides')->count());
    }

    #[TestDox('S-16.1 the approval reads its rows with a locking read inside its transaction')]
    public function test_s_16_1_approval_locks_its_rows(): void
    {
        $this->askFourTimes();

        // sqlite has no row locks and leaves the lock clause out of its SQL. This grammar
        // writes it as a comment, so the test can see which reads asked for a lock.
        $connection = DB::connection();
        $connection->setQueryGrammar($connection->withTablePrefix(new class extends \Illuminate\Database\Query\Grammars\SQLiteGrammar {
            protected function compileLock(\Illuminate\Database\Query\Builder $query, $value)
            {
                return $value === true ? '/* for update */' : '';
            }
        }));
        $locked = [];
        $inTransaction = [];
        DB::listen(function ($query) use (&$locked, &$inTransaction) {
            if (DB::transactionLevel() > 0) {
                $inTransaction[] = $query->sql;
            }
            if (str_contains($query->sql, '/* for update */')) {
                preg_match('/from "(\w+)"/', $query->sql, $m);
                $locked[] = [$m[1] ?? '?', DB::transactionLevel()];
            }
        });

        $this->approve($this->c, $this->e1, self::DN)->assertStatus(200);

        $tables = array_column($locked, 0);
        $this->assertContains('attendance_change_requests', $tables, 'the entry rows must be read for update');
        $this->assertContains('employees', $tables, 'the employee row must be locked');
        $this->assertContains('attendance_date_locks', $tables, 'the date lock must be read for update');
        // On MySQL the first plain read of a transaction fixes what it sees. The lock has to
        // come before any such read, or the approval would work on data from before its wait.
        $this->assertMatchesRegularExpression(
            '/from "employees".*\/\* for update \*\//s',
            $inTransaction[0],
            'the first statement of the approval must be the locking read of the employee'
        );
        foreach ($locked as [$table, $level]) {
            // The tests open no transaction of their own, so any level above zero is the approval's.
            $this->assertGreaterThanOrEqual(1, $level, "the locking read of {$table} must be inside the approval's transaction");
        }
    }

    #[TestDox('S-16.2 when a rejection lands in the middle of an approval, exactly one wins and the data matches it')]
    public function test_s_16_2_approve_and_reject_at_once(): void
    {
        $this->askFourTimes();
        $officialBefore = $this->official();
        $this->interleave(function () {
            DB::table('attendance_change_requests')->where('status', 'pending')->update([
                'status' => 'rejected', 'pending_marker' => null, 'decision_reason' => 'Sabay na pagtanggi',
            ]);
        });

        $response = $this->approve($this->c, $this->e1, self::DN);

        // The approval lost: nothing of it may stay written. (The test runs on one
        // connection, so the rollback also undoes the competing rejection; what is
        // proven here is that the approval notices it and keeps nothing.)
        $response->assertStatus(409);
        $this->assertSame($officialBefore, $this->official());
        $this->assertSame(0, $this->requestCount('approved'));
    }

    #[TestDox('S-16.3 a request changed after the CEO opened it is not applied unseen')]
    public function test_s_16_3_changed_request_needs_a_new_look(): void
    {
        $this->saveAll($this->h, $this->e1, self::DN, ['time_in' => '08:00']);
        $id = $this->anchorId($this->e1, self::DN);
        $seenV1 = $this->seen($this->e1, self::DN);
        $this->saveAll($this->h, $this->e1, self::DN, ['time_in' => '09:45']);

        $response = $this->actingAs($this->c)->postJson("/approvals/{$id}/approve", ['seen' => $seenV1]);

        $response->assertStatus(409);
        $this->assertStringContainsString('equest changed, review again', $response->json('message'));
        $this->assertNull($this->day($this->e1, self::DN));
        $this->assertSame('09:45:00', $this->pending($this->e1, self::DN)['time_in']['new_value']);
    }

    #[TestDox('S-16.4 when a withdrawal lands in the middle of an approval, no data is written')]
    public function test_s_16_4_withdraw_while_approving(): void
    {
        $this->askFourTimes();
        $officialBefore = $this->official();
        $this->interleave(function () {
            DB::table('attendance_change_requests')->where('status', 'pending')->update(['status' => 'withdrawn', 'pending_marker' => null]);
        });

        $this->approve($this->c, $this->e1, self::DN)->assertStatus(409);

        $this->assertSame($officialBefore, $this->official());
        $this->assertSame(0, $this->requestCount('approved'));
    }

    #[TestDox('S-16.5 a field added to the entry after the CEO opened it refuses the approval')]
    public function test_s_16_5_field_added_while_approving(): void
    {
        $this->saveAll($this->h, $this->e1, self::DN, ['time_in' => '08:00']);
        $id = $this->anchorId($this->e1, self::DN);
        $seen = $this->seen($this->e1, self::DN);
        $this->saveAll($this->h2, $this->e1, self::DN, ['time_out' => '17:00']);

        $response = $this->actingAs($this->c)->postJson("/approvals/{$id}/approve", ['seen' => $seen]);

        $response->assertStatus(409);
        $this->assertStringContainsString('equest changed, review again', $response->json('message'));
        $this->assertNull($this->day($this->e1, self::DN));
        $this->assertSame(['time_in', 'time_out'], array_keys($this->pending($this->e1, self::DN)));
    }

    #[TestDox('S-16.6 an approval that meets a day row created at the same moment ends with one day row')]
    public function test_s_16_6_approval_meets_a_fresh_day_row(): void
    {
        $this->askFourTimes();
        // The row appears between the approval's look for it and its own insert.
        $this->app->bind(\App\Services\AttendanceChangeService::class, fn ($app) => new class($app->make(AttendanceComputeService::class)) extends \App\Services\AttendanceChangeService {
            public function createBlankDay(\App\Models\Employee $employee, string $date, string $note): AttendanceDay
            {
                DB::table('attendance_days')->insert([
                    'employee_id' => $employee->id, 'work_date' => $date,
                    'notes' => 'created directly at the same moment',
                    'created_at' => now(), 'updated_at' => now(),
                ]);

                return parent::createBlankDay($employee, $date, $note);
            }
        });

        $this->approve($this->c, $this->e1, self::DN)->assertStatus(200);

        $this->assertSame(1, DB::table('attendance_days')->where('employee_id', $this->e1->id)->whereDate('work_date', self::DN)->count());
        $this->assertSame(self::DN . ' 08:00:00', $this->day($this->e1, self::DN)['time_in']);
        $this->assertSame(4, DB::table('attendance_overrides')->count());
        $this->assertSame(4, $this->requestCount('approved'));
    }

    /* ── S-17 The day changes between request and approval ── */

    #[TestDox('S-17.1 punches imported after the request show "was, now" and need a confirm')]
    public function test_s_17_1_import_after_the_request(): void
    {
        $this->askFourTimes();
        $this->punchedDay($this->e1, self::DN, ['08:20:00', '12:00:00', '13:00:00', '16:40:00']);

        $page = $this->actingAs($this->c)->get('/approvals')->assertStatus(200);
        $page->assertSee('was none, now 8:20 AM')->assertSee('was none, now 4:40 PM');
        // Spec 007: the imported lunch punches equal the lunch asked for, so those two requests
        // closed themselves as no longer needed when the page was loaded; the other two wait.
        $this->assertSame(['time_in', 'time_out'], array_keys($this->pending($this->e1, self::DN)));
        $this->assertSame(2, $this->requestCount('no_longer_needed'));

        $refused = $this->approve($this->c, $this->e1, self::DN);
        $refused->assertStatus(409)->assertJson(['needs_confirm' => true]);
        $this->assertSame(self::DN . ' 08:20:00', $this->day($this->e1, self::DN)['time_in']);

        $this->approve($this->c, $this->e1, self::DN, ['confirmed' => true])->assertStatus(200);
        $day = $this->day($this->e1, self::DN);
        $this->assertSame(self::DN . ' 08:00:00', $day['time_in']);
        $this->assertSame(self::DN . ' 17:00:00', $day['time_out']);
        $this->assertSame(1, DB::table('attendance_days')->where('employee_id', $this->e1->id)->whereDate('work_date', self::DN)->count());
    }

    #[TestDox('S-17.2 a direct CEO edit of the same field after the request needs a confirm and is logged first')]
    public function test_s_17_2_ceo_edit_of_the_same_field(): void
    {
        $this->saveAll($this->h, $this->e1, self::DR, ['time_in' => '08:00']);
        $this->saveAll($this->c, $this->e1, self::DR, ['time_in' => '08:10'], 'Itinama ng CEO');
        $this->assertSame(1, $this->requestCount('pending'), 'the direct edit leaves the request pending');

        $this->actingAs($this->c)->get('/approvals')->assertSee('was 8:03 AM, now 8:10 AM');
        $this->approve($this->c, $this->e1, self::DR)->assertStatus(409)->assertJson(['needs_confirm' => true]);
        $this->approve($this->c, $this->e1, self::DR, ['confirmed' => true])->assertStatus(200);

        $log = DB::table('attendance_overrides')->orderBy('id')->get();
        $this->assertSame(['08:10:00', '08:00:00'], $log->pluck('new_value')->all());
        $this->assertSame(['Itinama ng CEO', self::REASON], $log->pluck('reason')->all());
        $this->assertSame(self::DR . ' 08:00:00', $this->day($this->e1, self::DR)['time_in']);
    }

    #[TestDox('S-17.3 a CEO edit of another field asks for no confirm and the approval applies its own field only')]
    public function test_s_17_3_ceo_edit_of_another_field(): void
    {
        $this->saveAll($this->h, $this->e1, self::DR, ['time_in' => '08:00']);
        $this->saveAll($this->c, $this->e1, self::DR, ['time_out' => '17:30'], 'Itinama ng CEO');

        $this->actingAs($this->c)->get('/approvals')->assertDontSee('was 8:03 AM');
        $this->approve($this->c, $this->e1, self::DR)->assertStatus(200);

        $day = $this->day($this->e1, self::DR);
        $this->assertSame(self::DR . ' 08:00:00', $day['time_in']);
        $this->assertSame(self::DR . ' 17:30:00', $day['time_out']);
    }

    #[TestDox('S-17.6 a request for an employee who was deactivated stays visible, is flagged, and can be approved or rejected')]
    public function test_s_17_6_deactivated_employee_is_flagged(): void
    {
        $this->askFourTimes();
        $this->askFourTimes($this->h, $this->e2);
        DB::table('employees')->whereIn('id', [$this->e1->id, $this->e2->id])->update(['status' => 'inactive']);

        $page = $this->actingAs($this->c)->get('/approvals')->assertStatus(200);
        $page->assertSee('Employee One')->assertSee('Inactive employee');

        // As with a direct edit by the CEO, the day of an inactive employee can be corrected.
        $this->approve($this->c, $this->e1, self::DN)->assertStatus(200);
        $this->assertSame(self::DN . ' 08:00:00', $this->day($this->e1, self::DN)['time_in']);
        $this->reject($this->c, $this->e2, self::DN)->assertStatus(200);

        $this->assertNull($this->payroll(), 'payroll leaves an inactive employee out');
    }

    #[TestDox('S-17.7 an approval after the shift changed writes the asked times and uses the current shift')]
    public function test_s_17_7_shift_changed_meanwhile(): void
    {
        $this->askFourTimes();
        foreach ([$this->e1, $this->e2] as $employee) {
            DB::table('employee_shift_assignments')->insert([
                'employee_id' => $employee->id, 'shift_id' => $this->otherShift->id,
                'effective_date' => self::DN, 'effective_until' => self::DN,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        // The control: the CEO enters the same times directly under the same new shift.
        $this->askFourTimes($this->c, $this->e2);

        $this->approve($this->c, $this->e1, self::DN)->assertStatus(200);

        $day = $this->day($this->e1, self::DN);
        $control = $this->day($this->e2, self::DN);
        $this->assertSame(self::DN . ' 08:00:00', $day['time_in']);
        $this->assertSame(self::DN . ' 17:00:00', $day['time_out']);
        $this->assertEquals($this->otherShift->id, $day['shift_id']);
        foreach (['computed_work_minutes', 'computed_late_minutes', 'computed_early_minutes', 'computed_overtime_minutes', 'payable_work_minutes'] as $column) {
            $this->assertSame($control[$column], $day[$column], $column);
        }
        // Not the minutes of the 08:00 to 17:00 shift the request was made under.
        $this->assertNotSame(0, (int) $day['computed_early_minutes']);
    }

    #[TestDox('S-17.8 a force recompute neither applies nor deletes pending requests')]
    public function test_s_17_8_force_recompute_leaves_requests_alone(): void
    {
        $this->askFourTimes();
        $before = $this->pending($this->e1, self::DN);

        $this->actingAs($this->c)->post('/attendance/force-compute', [
            'start_date' => self::PERIOD_START, 'end_date' => self::PERIOD_END,
        ])->assertStatus(302);

        $this->assertSame($before, $this->pending($this->e1, self::DN));
        $this->assertNull($this->day($this->e1, self::DN));
    }

    /**
     * Makes $competing run in the middle of the next approval: after the approval
     * has read and checked its rows, at the moment it recomputes the day it wrote.
     */
    private function interleave(callable $competing): void
    {
        $this->app->bind(AttendanceComputeService::class, fn () => new class($competing) extends AttendanceComputeService {
            public function __construct(private $competing)
            {
            }

            public function recomputeDay(AttendanceDay $day): AttendanceDay
            {
                ($this->competing)();

                return parent::recomputeDay($day);
            }
        });
    }
}
