<?php

namespace Tests\Feature\Approvals;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Spec 007: shift requests of one change are one card with one Approve and one Reject.
 */
class ShiftGroupsTest extends ShiftChangeTestCase
{
    /* ── .1 grouping ── */

    #[TestDox('S-007.1 shift requests of one change are one card; another requester, minute, value or a mixed day are not')]
    public function test_s_007_1_grouping_keys(): void
    {
        $this->askChange($this->e1, [self::DR, self::DX, self::D9]);
        // Same minute, another requester: its own card.
        $this->askChange($this->e1, [self::D12], $this->h2);
        // Same requester and value, a minute later: its own card.
        $this->askChange($this->e1, [self::D13], null, '2026-10-26 16:45:01');
        // Same minute, another value: its own card.
        $this->travelTo(\Carbon\Carbon::parse(self::ASKED_AT));
        $this->askShift($this->h, $this->e2, self::DR);
        $this->askShift($this->h, $this->e2, self::DX, $this->makeShift('Night')->id);
        // Same minute, a day with a shift AND a time: a per-day card as today.
        $this->saveAll($this->h, $this->e2, self::D9, ['time_in' => '08:00', 'shift_id' => $this->otherShift->id])->assertJson(['pending' => true]);
        $this->travelBack();

        $html = $this->page();
        $this->assertCount(1, $this->groupKeys($html), 'only E1 DR, DX, D9 form a group');
        $card = $this->groupCard($html, $this->e1);
        $this->assertStringContainsString('Employee One', $card);
        $this->assertStringContainsString('Shift → Late shift', $card);
        $this->assertStringContainsString('Oct 05–Oct 09 (3 days)', $card);
        $this->assertStringContainsString('Oct 05, Oct 08–09', $card);
        $this->assertStringContainsString('Asked by Hr One on Oct 26, 2026 4:44 PM', $card);
        $this->assertStringContainsString('Reason: Lumipat ng shift', $card);
        $this->assertStringContainsString('See each day', $card);
        $this->assertSame(3, substr_count($card, 'class="approval-group-day border'), 'each day under See each day');
        $this->assertSame(3, substr_count($card, 'data-preview="ok"'), 'a preview per day');
        $this->assertStringContainsString('data-total="late"', $card);
        // Five other cards: E1/D12, E1/D13, E2/DR, E2/DX, E2/D9.
        $this->assertSame(5, substr_count($html, 'class="approval-entry card'));
    }

    #[TestDox('S-007.1 requests with the same batch id are one card even when asked in different minutes; different batch ids are not')]
    public function test_s_007_1_batch_id_groups(): void
    {
        $batch = (string) Str::uuid();
        $this->askChange($this->e1, [self::DR], null, '2026-10-26 10:00:00');
        $this->askChange($this->e1, [self::DX], null, '2026-10-26 10:05:00');
        DB::table('attendance_change_requests')->where('employee_id', $this->e1->id)->update(['batch_id' => $batch]);
        // Same minute but each with its own batch: not one change.
        $this->askChange($this->e2, [self::DR, self::DX]);
        DB::table('attendance_change_requests')->where('employee_id', $this->e2->id)->where('work_date', self::DR)->update(['batch_id' => (string) Str::uuid()]);
        DB::table('attendance_change_requests')->where('employee_id', $this->e2->id)->where('work_date', self::DX)->update(['batch_id' => (string) Str::uuid()]);

        $html = $this->page();
        $this->assertCount(1, $this->groupKeys($html));
        $this->assertStringContainsString('Oct 05, Oct 08', $this->groupCard($html, $this->e1));
        $this->assertSame(2, substr_count($html, 'class="approval-entry card'));
    }

    /* ── .2 approve and reject the group ── */

    #[TestDox('S-007.2 one Approve on the card saves exactly what approving each day saves, change log included')]
    public function test_s_007_2_approve_all_equals_one_by_one(): void
    {
        $dates = [self::DR, self::DX, self::D9];
        $this->askChange($this->e1, $dates);
        $this->askChange($this->e2, $dates, $this->h2);

        $keys = $this->groupKeys($this->page());
        $this->assertCount(2, $keys);
        $key = $keys[0];
        $this->groupPost('approve', $key, $this->entriesOf($this->e1, $dates))
            ->assertStatus(200)->assertJson(['success' => true, 'done' => 3]);
        foreach ($dates as $date) {
            $this->approve($this->c, $this->e2, $date)->assertStatus(200);
        }

        $this->assertSame($this->comparable('employee_shift_assignments', $this->e2, 'effective_date'), $this->comparable('employee_shift_assignments', $this->e1, 'effective_date'));
        $this->assertSame($this->comparable('attendance_days', $this->e2), $this->comparable('attendance_days', $this->e1));
        $this->assertSame($this->payrollOf($this->e2), $this->payrollOf($this->e1));
        // The change log: each request approved by the CEO, naming its requester and the row it made.
        $log = fn (Employee $e) => DB::table('attendance_change_requests')->where('employee_id', $e->id)->orderBy('work_date')
            ->get(['work_date', 'status', 'decided_by', 'applied_type', 'requested_by'])->map(fn ($r) => (array) $r)->all();
        foreach ($log($this->e1) as $i => $row) {
            $this->assertSame('approved', $row['status']);
            $this->assertSame($this->c->id, (int) $row['decided_by']);
            $this->assertSame($this->h->id, (int) $row['requested_by']);
            $this->assertSame($log($this->e2)[$i]['applied_type'], $row['applied_type']);
        }
        $this->assertSame(0, substr_count($this->page(), 'class="approval-group card'));
    }

    #[TestDox('S-007.2 one Reject with a reason rejects every day of the card and writes nothing')]
    public function test_s_007_2_reject_all(): void
    {
        $dates = [self::DR, self::DX, self::D9];
        $this->askChange($this->e1, $dates);
        $before = $this->official();
        [$key] = $this->groupKeys($this->page());

        $this->groupPost('reject', $key, $this->entriesOf($this->e1, $dates), ['reason' => 'Hindi pa pinal ang schedule'])
            ->assertStatus(200)->assertJson(['done' => 3]);

        $this->assertSame($before, $this->official());
        $rows = DB::table('attendance_change_requests')->where('employee_id', $this->e1->id)->get();
        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertSame('rejected', $row->status);
            $this->assertSame('Hindi pa pinal ang schedule', $row->decision_reason);
        }
    }

    #[TestDox('S-007.2 a day inside the card can still be approved on its own')]
    public function test_s_007_2_per_day_inside_the_card(): void
    {
        $this->askChange($this->e1, [self::DR, self::DX, self::D9]);
        $card = $this->groupCard($this->page(), $this->e1);
        $this->assertStringContainsString('action="' . url('/approvals/' . $this->anchorId($this->e1, self::DX) . '/approve') . '"', $card);

        $this->approve($this->c, $this->e1, self::DX)->assertStatus(200);
        $this->assertSame('approved', $this->shiftStatus($this->e1, self::DX));
        $this->assertSame('pending', $this->shiftStatus($this->e1, self::DR));
        $this->assertStringContainsString('Oct 05, Oct 09', $this->groupCard($this->page(), $this->e1));
    }

    /* ── .3 a locked day inside the group ── */

    #[TestDox('S-007.3 a locked day inside the card is named and stays pending; the other days are approved together')]
    public function test_s_007_3_locked_day_inside_a_group(): void
    {
        $dates = [self::DR, self::DX, self::D9];
        $this->askChange($this->e1, $dates);
        $this->lock(self::DX);

        $html = $this->page();
        $card = $this->groupCard($html, $this->e1);
        $this->assertStringContainsString('Oct 08 cannot be approved: date locked', $card);
        [$key] = $this->groupKeys($html);

        $this->groupPost('approve', $key, $this->entriesOf($this->e1, $dates))
            ->assertStatus(200)->assertJson(['done' => 2])->assertJsonPath('failed.date locked', 1);
        $this->assertSame('approved', $this->shiftStatus($this->e1, self::DR));
        $this->assertSame('pending', $this->shiftStatus($this->e1, self::DX));
        $this->assertSame('approved', $this->shiftStatus($this->e1, self::D9));
        $this->assertSame(0, DB::table('employee_shift_assignments')->where('employee_id', $this->e1->id)->where('effective_date', self::DX)->count());
    }

    /* ── .4 bulk controls and the badge ── */

    #[TestDox('S-007.4 selecting a card selects its days for Approve selected; the badge counts cards')]
    public function test_s_007_4_select_all_and_badge(): void
    {
        $dates = [self::DR, self::DX, self::D9];
        $this->askChange($this->e1, $dates);
        $this->saveAll($this->h, $this->e2, self::DN, ['time_in' => '08:00']);

        $html = $this->page();
        $this->assertStringContainsString('id="navApprovalsCount">2<', $html, 'one card for the shift change, one for the times');
        $card = $this->groupCard($html, $this->e1);
        $this->assertStringContainsString('class="form-check-input entry-select group-select', $card);
        foreach ($dates as $date) {
            $this->assertStringContainsString('form="severalForm" name="entries[g0_' . $date . '][id]" value="' . $this->anchorId($this->e1, $date) . '"', html_entity_decode($card));
        }
        $this->assertStringContainsString('(3 days)', $card, 'the number of days is on the card');

        $entries = array_merge($this->entriesOf($this->e1, $dates), $this->entriesOf($this->e2, [self::DN]));
        $this->actingAs($this->c)->postJson('/approvals/approve-several', ['entries' => $entries])
            ->assertStatus(200)->assertJson(['done' => 4, 'pending_entries' => 0]);
        $this->assertSame(0, $this->requestCount('pending'));
    }

    #[TestDox('S-007.4 the count a decision returns is the number of cards left')]
    public function test_s_007_4_json_count_is_cards(): void
    {
        $this->askChange($this->e1, [self::DR, self::DX, self::D9]);
        $this->askChange($this->e2, [self::DR, self::DX]);
        $this->approve($this->c, $this->e1, self::DR)->assertJson(['pending_entries' => 2]);
    }

    /* ── .5 other kinds stay per day ── */

    #[TestDox('S-007.5 times asked for several days in one minute keep one card per day')]
    public function test_s_007_5_other_kinds_are_not_grouped(): void
    {
        $this->travelTo(\Carbon\Carbon::parse(self::ASKED_AT));
        foreach ([self::DR, self::DX, self::D9] as $date) {
            $this->saveAll($this->h, $this->e1, $date, ['time_in' => '08:00'])->assertJson(['pending' => true]);
        }
        $this->travelBack();

        $html = $this->page();
        $this->assertCount(0, $this->groupKeys($html));
        $this->assertSame(3, substr_count($html, 'class="approval-entry card'));
        $this->assertStringContainsString('id="navApprovalsCount">3<', $html);
    }

    /* ── Untrusted posts ── */

    #[TestDox('S-007 a group post with a day of another change, or a made-up key, approves nothing')]
    public function test_s_007_group_post_is_checked_on_the_server(): void
    {
        $this->askChange($this->e1, [self::DR, self::DX]);
        $this->askChange($this->e1, [self::D9], $this->h2);
        $this->saveAll($this->h, $this->e2, self::DN, ['time_in' => '08:00']);
        [$key] = $this->groupKeys($this->page());
        $before = $this->official();

        // A day asked by someone else slipped into the post.
        $this->groupPost('approve', $key, $this->entriesOf($this->e1, [self::DR, self::DX, self::D9]))->assertStatus(422);
        // A times request slipped into the post.
        $this->groupPost('approve', $key, array_merge($this->entriesOf($this->e1, [self::DR]), $this->entriesOf($this->e2, [self::DN])))->assertStatus(422);
        // A key that is not the change of these days.
        $this->groupPost('approve', $key . 'x', $this->entriesOf($this->e1, [self::DR, self::DX]))->assertStatus(422);
        $this->groupPost('reject', $key . 'x', $this->entriesOf($this->e1, [self::DR, self::DX]), ['reason' => 'Mali'])->assertStatus(422);
        // Not the CEO.
        $this->actingAs($this->h2)->postJson('/approvals/shift-group/approve', ['key' => $key, 'entries' => $this->entriesOf($this->e1, [self::DR])])->assertStatus(403);

        $this->assertSame($before, $this->official());
        $this->assertSame(4, $this->requestCount('pending'));
    }

    #[TestDox('S-007 a group post naming a day that also has a times request is refused')]
    public function test_s_007_group_day_must_be_alone(): void
    {
        $this->askChange($this->e1, [self::DR, self::DX]);
        [$key] = $this->groupKeys($this->page());
        // After the page was loaded, a times request joins DR: it is no longer a shift-only day.
        $this->saveAll($this->h, $this->e1, self::DR, ['time_in' => '08:00'])->assertJson(['pending' => true]);

        $this->groupPost('approve', $key, $this->entriesOf($this->e1, [self::DR, self::DX]))->assertStatus(422);
        $this->assertSame(3, $this->requestCount('pending'));
    }

    #[TestDox('S-007 a change of more than 50 days is cut into cards of at most 50 days, with the same key')]
    public function test_s_007_long_change_is_cut_into_cards(): void
    {
        $rows = collect(range(1, 55))->map(function ($n) {
            $row = new \App\Models\AttendanceChangeRequest([
                'employee_id' => 7, 'work_date' => \Carbon\Carbon::parse('2026-08-01')->addDays($n)->toDateString(),
                'field' => 'shift_id', 'new_value' => '3', 'requested_by' => 2,
            ]);
            $row->id = $n;
            $row->created_at = \Carbon\Carbon::parse(self::ASKED_AT);

            return $row;
        });

        $cards = \App\Services\ApprovalCards::build($rows);

        $this->assertSame([50, 5], array_map(fn ($c) => count($c['pairs']), $cards));
        $this->assertSame($cards[0]['key'], $cards[1]['key']);
        $this->assertSame(['group', 'group'], array_column($cards, 'type'));
    }
}
