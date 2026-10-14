<?php

namespace Tests\Feature\Approvals;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * S-22 and S-07.9 (bulk actions by HR staff and admins), S-12 (the CEO decides
 * several entries or a whole batch at once).
 */
class BulkAndBatchTest extends ApprovalsTestCase
{
    protected Employee $e4;

    protected function setUp(): void
    {
        parent::setUp();
        $this->e4 = $this->employee('904', 'Employee Four', 'active');
    }

    private function bulk(User $user, string $date, array $employees, string $action, array $extra = []): TestResponse
    {
        return $this->actingAs($user)->postJson('/attendance-calendar/bulk-action', array_merge([
            'date'         => $date,
            'employee_ids' => array_map(fn ($e) => $e instanceof Employee ? $e->id : $e, $employees),
            'action'       => $action,
            'reason'       => self::REASON,
        ], $extra));
    }

    /** Pending entries as [employee id, date], from the request table. */
    private function pendingEntries(): array
    {
        return DB::table('attendance_change_requests')->where('status', 'pending')
            ->select('employee_id', 'work_date')->distinct()->orderBy('employee_id')->get()
            ->map(fn ($r) => [(int) $r->employee_id, $r->work_date])->all();
    }

    /** The entries the Approvals page offers for "selected" actions: [id, seen] per entry. */
    private function entriesOnPage(string $query = ''): array
    {
        $html = $this->actingAs($this->c)->get('/approvals' . $query)->assertStatus(200)->getContent();
        // The inputs of the "selected" form only; a batch heading repeats its entries in a form of its own.
        preg_match_all('/form="severalForm"\s+name="entries\[(\d+)\]\[id\]" value="(\d+)"/', $html, $ids, PREG_SET_ORDER);
        preg_match_all('/form="severalForm"\s+name="entries\[(\d+)\]\[seen\]" value="([^"]*)"/', $html, $seen, PREG_SET_ORDER);
        $seenByIndex = array_column($seen, 2, 1);

        return array_map(fn ($m) => ['id' => (int) $m[2], 'seen' => $seenByIndex[$m[1]]], $ids);
    }

    /* ── S-22 Bulk actions by HR or admin ── */

    #[TestDox('S-22.1 bulk "set times" by HR staff sends one request per employee, grouped as a batch')]
    public function test_s_22_1_bulk_set_times_sends_requests(): void
    {
        $response = $this->bulk($this->h, self::DN, [$this->e1, $this->e2, $this->e4], 'set_times', self::FOUR_TIMES);

        $response->assertStatus(200)->assertJson(['success' => true]);
        $this->assertStringContainsString('3 requests sent for approval', $response->json('message'));
        $this->assertSame([[$this->e1->id, self::DN], [$this->e2->id, self::DN], [$this->e4->id, self::DN]], $this->pendingEntries());
        $batches = DB::table('attendance_change_requests')->distinct()->pluck('batch_id');
        $this->assertCount(1, $batches);
        $this->assertNotNull($batches[0]);
        $this->assertSame('08:00:00', $this->pending($this->e4, self::DN)['time_in']['new_value']);
        $this->assertSame(0, DB::table('attendance_days')->whereDate('work_date', self::DN)->count());
        $this->assertSame(0, DB::table('attendance_overrides')->count());
    }

    #[TestDox('S-22.2 bulk "fill from shift" sends the shift times; an employee with no shift is a failure')]
    public function test_s_22_2_bulk_fill_from_shift(): void
    {
        $noShift = Employee::create(['zkteco_id' => '905', 'full_name' => 'Employee NoShift', 'status' => 'active']);

        $response = $this->bulk($this->h, self::DN, [$this->e1, $this->e2, $noShift], 'fill_from_shift');

        $response->assertStatus(200);
        $this->assertSame([[$this->e1->id, self::DN], [$this->e2->id, self::DN]], $this->pendingEntries());
        $this->assertSame(
            ['time_in' => '08:00:00', 'lunch_out' => '12:00:00', 'lunch_in' => '13:00:00', 'time_out' => '17:00:00'],
            array_map(fn ($r) => $r['new_value'], $this->pending($this->e2, self::DN))
        );
        $failures = $response->json('failures');
        $this->assertCount(1, $failures);
        $this->assertSame($noShift->id, $failures[0]['id']);
        $this->assertStringContainsString('No shift assigned', $failures[0]['error']);
        $this->assertSame(0, DB::table('attendance_days')->whereDate('work_date', self::DN)->count());
    }

    #[TestDox('S-22.3 a bulk rest-day action is direct without a day row and a request with one')]
    public function test_s_22_3_bulk_rest_day_follows_the_rule(): void
    {
        foreach (['add_day_off', 'cancel_day_off', 'remove_override'] as $action) {
            $this->bulk($this->h, self::DR, [$this->e1, $this->e2], $action)->assertStatus(200);

            // E2 has no day row on Dr: direct. E1 has one: nothing official, a request.
            $this->assertSame(0, DB::table('day_offs')->where('employee_id', $this->e1->id)->count(), $action);
            $this->assertSame(0, DB::table('day_off_logs')->where('employee_id', $this->e1->id)->count(), $action);
            $this->assertSame($action, $this->pending($this->e1, self::DR)['rest_day']['new_value']);
            $this->assertSame(1, DB::table('day_off_logs')->where('employee_id', $this->e2->id)->where('action', $action)->count(), $action);
        }

        $this->assertSame(0, DB::table('day_offs')->where('employee_id', $this->e2->id)->count(), 'added, cancelled, then removed');
        $this->assertSame(1, $this->requestCount(), 'the same person asking again updates the one request');
        $this->assertSame(0, DB::table('attendance_change_requests')->where('employee_id', $this->e2->id)->count());
    }

    #[TestDox('S-22.4 a bulk action on a locked date is refused whole')]
    public function test_s_22_4_bulk_on_a_locked_date(): void
    {
        $officialBefore = $this->official();

        $this->bulk($this->h, self::DL, [$this->e1, $this->e2], 'set_times', self::FOUR_TIMES)->assertStatus(423);
        $this->bulk($this->h, self::DL, [$this->e1, $this->e2], 'add_day_off')->assertStatus(423);

        $this->assertSame(0, $this->requestCount());
        $this->assertSame($officialBefore, $this->official());
    }

    #[TestDox('S-22.5 bulk "set times" on days that have a row changes no row and sends requests')]
    public function test_s_22_5_bulk_set_times_on_existing_rows(): void
    {
        $dayBefore = $this->day($this->e1, self::DR);

        $this->bulk($this->h, self::DR, [$this->e1], 'set_times', ['time_in' => '07:30', 'time_out' => '18:00'])->assertStatus(200);

        $this->assertSame($dayBefore, $this->day($this->e1, self::DR));
        $this->assertSame(0, DB::table('attendance_overrides')->count());
        $pending = $this->pending($this->e1, self::DR);
        $this->assertSame(['time_in', 'time_out'], array_keys($pending));
        $this->assertSame('07:30:00', $pending['time_in']['new_value']);
        $this->assertSame('08:03:00', $pending['time_in']['old_value']);
    }

    #[TestDox('S-22.6 an existing request of the same person is updated, of another person is a failure naming them')]
    public function test_s_22_6_bulk_meets_existing_requests(): void
    {
        $this->saveAll($this->h, $this->e1, self::DN, ['time_in' => '09:00']);
        $ownId = $this->pending($this->e1, self::DN)['time_in']['id'];
        $this->saveAll($this->h2, $this->e2, self::DN, ['time_in' => '09:00']);
        $otherBefore = $this->pending($this->e2, self::DN);

        $response = $this->bulk($this->h, self::DN, [$this->e1, $this->e2, $this->e4], 'set_times', ['time_in' => '08:00']);

        $response->assertStatus(200);
        $own = $this->pending($this->e1, self::DN)['time_in'];
        $this->assertEquals($ownId, $own['id']);
        $this->assertSame('08:00:00', $own['new_value']);
        $this->assertSame($otherBefore, $this->pending($this->e2, self::DN));
        $this->assertSame('08:00:00', $this->pending($this->e4, self::DN)['time_in']['new_value']);
        $failures = $response->json('failures');
        $this->assertCount(1, $failures);
        $this->assertSame($this->e2->id, $failures[0]['id']);
        $this->assertStringContainsString('Hr Two', $failures[0]['error']);
        $this->assertStringContainsString('2 requests sent for approval', $response->json('message'));
        $this->assertStringContainsString('1 failed', $response->json('message'));
    }

    #[TestDox('S-22.7 a bulk action by the CEO is direct')]
    public function test_s_22_7_ceo_bulk_is_direct(): void
    {
        $response = $this->bulk($this->c, self::DN, [$this->e1, $this->e2], 'set_times', self::FOUR_TIMES);

        $response->assertStatus(200);
        $this->assertSame('2 succeeded, 0 failed.', $response->json('message'));
        $this->assertSame(0, $this->requestCount());
        $this->assertSame(self::DN . ' 08:00:00', $this->day($this->e1, self::DN)['time_in']);
        $this->assertSame(self::DN . ' 17:00:00', $this->day($this->e2, self::DN)['time_out']);
        $this->assertSame(8, DB::table('attendance_overrides')->where('updated_by', $this->c->id)->count());
    }

    #[TestDox('S-22.8 an empty employee list or "set times" without times creates nothing')]
    public function test_s_22_8_empty_bulk(): void
    {
        $officialBefore = $this->official();

        $this->bulk($this->h, self::DN, [], 'set_times', self::FOUR_TIMES)->assertStatus(422);
        $this->bulk($this->h, self::DN, [$this->e1, $this->e2], 'set_times')->assertStatus(422);
        $this->bulk($this->c, self::DN, [$this->e1, $this->e2], 'set_times')->assertStatus(422);

        $this->assertSame(0, $this->requestCount());
        $this->assertSame($officialBefore, $this->official());
    }

    #[TestDox('S-22.9 a bulk action over 100 employees completes and one failure does not drop the rest')]
    public function test_s_22_9_hundred_employees(): void
    {
        $employees = [];
        for ($i = 0; $i < 99; $i++) {
            $employees[] = $this->employee((string) (2000 + $i), 'Bulk ' . $i, 'active');
        }
        $noShift = Employee::create(['zkteco_id' => '2999', 'full_name' => 'Bulk NoShift', 'status' => 'active']);
        array_splice($employees, 40, 0, [$noShift]);

        $started = microtime(true);
        $response = $this->bulk($this->h, self::DN, $employees, 'fill_from_shift');
        $seconds = microtime(true) - $started;

        $response->assertStatus(200);
        $this->assertCount(99, $this->pendingEntries());
        $this->assertCount(1, $response->json('failures'));
        $this->assertStringContainsString('99 requests sent for approval', $response->json('message'));
        $this->assertLessThan(30, $seconds, 'well inside a request time limit');
    }

    #[TestDox('S-22.10 the CEO approves a whole batch with one action, or its requests one by one')]
    public function test_s_22_10_batch_is_approved_in_one_action(): void
    {
        $this->bulk($this->h, self::DN, [$this->e1, $this->e2, $this->e4], 'set_times', self::FOUR_TIMES);
        $batch = DB::table('attendance_change_requests')->value('batch_id');

        // One of them separately first.
        $this->approve($this->c, $this->e4, self::DN)->assertStatus(200);
        $html = $this->actingAs($this->c)->get('/approvals')->getContent();
        $this->assertStringContainsString('/approvals/batch/' . $batch . '/approve', $html);

        $response = $this->actingAs($this->c)->postJson("/approvals/batch/{$batch}/approve", ['entries' => $this->entriesOnPage()]);

        $response->assertStatus(200);
        $this->assertSame('2 approved', $response->json('message'));
        $this->assertSame(12, $this->requestCount('approved'));
        foreach ([$this->e1, $this->e2, $this->e4] as $employee) {
            $this->assertSame(self::DN . ' 08:00:00', $this->day($employee, self::DN)['time_in']);
        }
    }

    #[TestDox('S-22.11 the CEO rejects a whole batch with one reason and no day changes')]
    public function test_s_22_11_batch_is_rejected_with_one_reason(): void
    {
        $this->bulk($this->h, self::DN, [$this->e1, $this->e2, $this->e4], 'set_times', self::FOUR_TIMES);
        // An entry that is not part of the batch must not be touched by it.
        $this->saveAll($this->h2, $this->e1, self::DX, ['time_in' => '08:00']);
        $batch = DB::table('attendance_change_requests')->whereNotNull('batch_id')->value('batch_id');
        $officialBefore = $this->official();

        $this->actingAs($this->c)->postJson("/approvals/batch/{$batch}/reject", ['entries' => $this->entriesOnPage()])->assertStatus(422);
        $response = $this->actingAs($this->c)->postJson("/approvals/batch/{$batch}/reject", [
            'entries' => $this->entriesOnPage(), 'reason' => 'Walang katibayan',
        ]);

        $response->assertStatus(200);
        $this->assertSame('3 rejected', $response->json('message'));
        $rejected = DB::table('attendance_change_requests')->where('status', 'rejected')->get();
        $this->assertCount(12, $rejected);
        $this->assertSame(['Walang katibayan'], $rejected->pluck('decision_reason')->unique()->values()->all());
        $this->assertCount(1, $this->pending($this->e1, self::DX));
        $this->assertSame($officialBefore, $this->official());
    }

    #[TestDox('S-07.9 a bulk day off over three employees: two direct, one sent for approval')]
    public function test_s_07_9_bulk_day_off_mixed(): void
    {
        $response = $this->bulk($this->h, self::DR, [$this->e1, $this->e2, $this->e4], 'add_day_off');

        $response->assertStatus(200);
        $this->assertStringContainsString('2 done, 1 sent for approval', $response->json('message'));
        $this->assertSame(1, DB::table('day_off_logs')->where('employee_id', $this->e2->id)->where('updated_by', $this->h->id)->count());
        $this->assertSame(1, DB::table('day_off_logs')->where('employee_id', $this->e4->id)->count());
        $this->assertSame(0, DB::table('day_off_logs')->where('employee_id', $this->e1->id)->count());
        $this->assertSame(2, DB::table('day_offs')->count());
        $this->assertSame([[$this->e1->id, self::DR]], $this->pendingEntries());
    }

    /* ── S-12 The CEO approves several at once ── */

    #[TestDox('S-12.1 three selected entries are approved in one go')]
    public function test_s_12_1_approve_three_selected(): void
    {
        $this->threeEntries();

        $response = $this->actingAs($this->c)->postJson('/approvals/approve-several', ['entries' => $this->entriesOnPage()]);

        $response->assertStatus(200);
        $this->assertSame('3 approved', $response->json('message'));
        $this->assertSame(0, $this->requestCount('pending'));
        $this->assertSame(self::DN . ' 08:00:00', $this->day($this->e1, self::DN)['time_in']);
        $this->assertSame(self::DN . ' 08:00:00', $this->day($this->e2, self::DN)['time_in']);
        $this->assertSame(self::DN . ' 08:00:00', $this->day($this->e4, self::DN)['time_in']);
    }

    #[TestDox('S-12.2 an entry on a locked date stays pending with the reason named and the others are applied')]
    public function test_s_12_2_one_of_three_is_locked(): void
    {
        $this->threeEntries();
        $this->saveAll($this->h, $this->e1, self::DX, ['time_in' => '08:00']);
        $this->actingAs($this->h)->postJson('/attendance-requests/' . $this->anchorId($this->e1, self::DN) . '/withdraw');
        $this->lock(self::DX);

        $response = $this->actingAs($this->c)->postJson('/approvals/approve-several', ['entries' => $this->entriesOnPage()]);

        $response->assertStatus(200);
        $this->assertSame('2 approved, 1 not approved: date locked', $response->json('message'));
        $this->assertCount(1, $this->pending($this->e1, self::DX));
        $this->assertNull($this->day($this->e1, self::DX));
        $this->assertNotNull($this->day($this->e2, self::DN));
        $this->assertNotNull($this->day($this->e4, self::DN));
    }

    #[TestDox('S-12.3 an entry withdrawn after the list loaded is skipped with a message')]
    public function test_s_12_3_one_was_withdrawn_meanwhile(): void
    {
        $this->threeEntries();
        $entries = $this->entriesOnPage();
        $this->actingAs($this->h)->postJson('/attendance-requests/' . $this->anchorId($this->e1, self::DN) . '/withdraw')->assertStatus(200);

        $response = $this->actingAs($this->c)->postJson('/approvals/approve-several', ['entries' => $entries]);

        $response->assertStatus(200);
        $this->assertSame('2 approved, 1 not approved: this request was withdrawn', $response->json('message'));
        $this->assertNull($this->day($this->e1, self::DN));
        $this->assertNotNull($this->day($this->e2, self::DN));
    }

    #[TestDox('S-12.4 with a filter on, the selection holds only the filtered entries')]
    public function test_s_12_4_selection_follows_the_filter(): void
    {
        $this->threeEntries();

        $filtered = $this->entriesOnPage('?employee_id=' . $this->e2->id);
        $this->assertCount(1, $filtered);
        $this->actingAs($this->c)->postJson('/approvals/approve-several', ['entries' => $filtered])->assertStatus(200);

        $this->assertNotNull($this->day($this->e2, self::DN));
        $this->assertNull($this->day($this->e1, self::DN));
        $this->assertNull($this->day($this->e4, self::DN));
        $this->assertSame(2, $this->requestCount('pending'));
    }

    #[TestDox('S-12.5 fifty selected with one that cannot be approved: the other forty-nine are applied')]
    public function test_s_12_5_fifty_with_one_failing(): void
    {
        $employees = [];
        for ($i = 0; $i < 49; $i++) {
            $employees[] = $this->employee((string) (3000 + $i), 'Many ' . $i, 'active');
        }
        $this->bulk($this->h, self::DN, $employees, 'fill_from_shift')->assertStatus(200);
        // The fiftieth: a SIL request whose balance is gone by the time of the approval.
        $this->silSetup($this->e1, 1);
        $this->applySil($this->h, $this->e1, self::DN);
        $this->silSetup($this->e1, 0);
        $entries = $this->entriesOnPage();
        $this->assertCount(50, $entries);

        $started = microtime(true);
        $response = $this->actingAs($this->c)->postJson('/approvals/approve-several', ['entries' => $entries]);

        $response->assertStatus(200);
        $this->assertStringStartsWith('49 approved, 1 not approved: no remaining sil days', $response->json('message'));
        $this->assertLessThan(30, microtime(true) - $started);
        $this->assertSame(49, DB::table('attendance_days')->whereDate('work_date', self::DN)->count());
        $this->assertSame(0, DB::table('sil_applications')->count());
        $this->assertCount(1, $this->pending($this->e1, self::DN));
    }

    #[TestDox('S-12.6 rejecting the selected entries needs one reason, stored on each')]
    public function test_s_12_6_reject_selected(): void
    {
        $this->threeEntries();
        $entries = $this->entriesOnPage();
        $officialBefore = $this->official();

        $this->actingAs($this->c)->postJson('/approvals/reject-several', ['entries' => $entries])->assertStatus(422);
        $this->actingAs($this->c)->postJson('/approvals/reject-several', ['entries' => $entries, 'reason' => 'ab'])->assertStatus(422);
        $this->assertSame(3, $this->requestCount('pending'));

        $response = $this->actingAs($this->c)->postJson('/approvals/reject-several', ['entries' => $entries, 'reason' => 'Kulang ang patunay']);

        $response->assertStatus(200);
        $this->assertSame('3 rejected', $response->json('message'));
        $this->assertSame(['Kulang ang patunay'], DB::table('attendance_change_requests')->pluck('decision_reason')->unique()->values()->all());
        $this->assertSame(3, $this->requestCount('rejected'));
        $this->assertSame($officialBefore, $this->official());
    }

    #[TestDox('S-12.7 approving a batch applies what can be applied and names why the rest stays pending')]
    public function test_s_12_7_batch_with_blocked_requests(): void
    {
        $this->bulk($this->h, self::DN, [$this->e1, $this->e2, $this->e4], 'set_times', self::FOUR_TIMES);
        $batch = DB::table('attendance_change_requests')->value('batch_id');
        // Afterwards E2's date became a rest day.
        $this->toggleDayOff($this->c, $this->e2, self::DN, 'add_day_off')->assertStatus(200);

        $response = $this->actingAs($this->c)->postJson("/approvals/batch/{$batch}/approve", ['entries' => $this->entriesOnPage()]);

        $response->assertStatus(200);
        $this->assertSame('2 approved, 1 not approved: now a rest day', $response->json('message'));
        $this->assertNotNull($this->day($this->e1, self::DN));
        $this->assertNotNull($this->day($this->e4, self::DN));
        $this->assertNull($this->day($this->e2, self::DN));
        $this->assertCount(4, $this->pending($this->e2, self::DN));
    }

    /** Three pending entries on Dn, one field each, asked one by one. */
    private function threeEntries(): void
    {
        foreach ([$this->e1, $this->e2, $this->e4] as $employee) {
            $this->saveAll($this->h, $employee, self::DN, ['time_in' => '08:00']);
        }
    }
}
