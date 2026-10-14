<?php

namespace Tests\Feature\Approvals;

use App\Models\User;
use App\Services\AttendanceChangeRequestService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * S-05, S-08.1, S-08.3, S-15, S-19.4, S-21.1, S-21.2: what HR staff and admins
 * enter for times becomes a pending request and nothing official changes.
 */
class RequestTimesTest extends ApprovalsTestCase
{
    #[TestDox('S-05.1 four times saved by HR staff on a day without a row wait as a request')]
    public function test_s_05_1_times_on_a_day_without_a_row_become_a_request(): void
    {
        $payrollBefore = $this->payroll();
        $officialBefore = $this->official();

        $response = $this->askFourTimes();

        $response->assertStatus(200)->assertJson(['success' => true, 'pending' => true]);
        $this->assertStringContainsString('Sent for approval', $response->json('message'));

        $pending = $this->pending($this->e1, self::DN);
        $this->assertSame(['time_in', 'lunch_out', 'lunch_in', 'time_out'], array_keys($pending));
        $this->assertSame('08:00:00', $pending['time_in']['new_value']);
        $this->assertSame('12:00:00', $pending['lunch_out']['new_value']);
        $this->assertSame('13:00:00', $pending['lunch_in']['new_value']);
        $this->assertSame('17:00:00', $pending['time_out']['new_value']);
        foreach ($pending as $row) {
            $this->assertSame(self::REASON, $row['reason']);
            $this->assertEquals($this->h->id, $row['requested_by']);
            $this->assertNotNull($row['created_at']);
            $this->assertSame(self::DN, $row['work_date']);
        }

        $this->assertNull($this->day($this->e1, self::DN), 'a request must not create a day row');
        $this->assertSame(0, DB::table('attendance_overrides')->count());
        $this->assertSame($officialBefore, $this->official());
        $this->assertSame($payrollBefore, $this->payroll());
    }

    #[TestDox('S-05.2 a changed time on a day with a row waits and the row is untouched')]
    public function test_s_05_2_change_on_a_day_with_a_row_leaves_the_row_untouched(): void
    {
        $dayBefore = $this->day($this->e1, self::DR);

        $this->saveAll($this->h, $this->e1, self::DR, ['time_in' => '08:00'])->assertStatus(200);

        $pending = $this->pending($this->e1, self::DR);
        $this->assertSame(['time_in'], array_keys($pending));
        $this->assertSame('08:03:00', $pending['time_in']['old_value']);
        $this->assertSame('08:00:00', $pending['time_in']['new_value']);
        $this->assertSame($dayBefore, $this->day($this->e1, self::DR));
        $this->assertSame(0, DB::table('attendance_overrides')->count());
    }

    #[TestDox('S-05.3 one field edited from the modal waits and the row is untouched')]
    public function test_s_05_3_single_field_edit_becomes_a_request(): void
    {
        $dayBefore = $this->day($this->e1, self::DR);

        $response = $this->actingAs($this->h)->postJson('/attendance-calendar/override-time', [
            'attendance_day_id' => $dayBefore['id'],
            'field'             => 'time_out',
            'new_value'         => '17:30',
            'reason'            => self::REASON,
        ]);

        $response->assertStatus(200)->assertJson(['success' => true, 'pending' => true]);
        $pending = $this->pending($this->e1, self::DR);
        $this->assertSame(['time_out'], array_keys($pending));
        $this->assertSame('17:30:00', $pending['time_out']['new_value']);
        $this->assertSame($dayBefore, $this->day($this->e1, self::DR));
        $this->assertSame(0, DB::table('attendance_overrides')->count());
    }

    #[TestDox('S-05.4 the add-time flow of the modal makes one request and no blank day row')]
    public function test_s_05_4_add_time_flow_makes_no_blank_day_row(): void
    {
        $create = $this->actingAs($this->h)->postJson('/attendance-calendar/create-day', [
            'employee_id' => $this->e1->id,
            'work_date'   => self::DN,
        ]);

        $create->assertStatus(200)->assertJson(['success' => true, 'pending' => true, 'attendance_day_id' => null]);
        $this->assertStringNotContainsStringIgnoringCase('CEO', $create->json('message'));
        $this->assertNull($this->day($this->e1, self::DN));
        $this->assertSame(0, $this->requestCount(), 'creating a day must not list a request without values');

        $save = $this->actingAs($this->h)->postJson('/attendance-calendar/override-time', [
            'employee_id' => $this->e1->id,
            'date'        => self::DN,
            'field'       => 'time_in',
            'new_value'   => '08:00',
            'reason'      => self::REASON,
        ]);

        $save->assertStatus(200)->assertJson(['success' => true, 'pending' => true]);
        $this->assertStringNotContainsStringIgnoringCase('CEO', $save->json('message'));
        $this->assertSame(1, $this->requestCount());
        $this->assertSame('08:00:00', $this->pending($this->e1, self::DN)['time_in']['new_value']);
        $this->assertNull($this->day($this->e1, self::DN));
    }

    #[TestDox('S-05.5 Fill from Shift by HR staff waits with the four times of the shift')]
    public function test_s_05_5_fill_from_shift_becomes_a_request(): void
    {
        $response = $this->actingAs($this->h)->postJson('/attendance-calendar/fill-from-shift', [
            'employee_id' => $this->e1->id,
            'date'        => self::DN,
            'reason'      => self::REASON,
        ]);

        $response->assertStatus(200)->assertJson(['success' => true, 'pending' => true]);
        $pending = $this->pending($this->e1, self::DN);
        $this->assertSame(
            ['time_in' => '08:00:00', 'lunch_out' => '12:00:00', 'lunch_in' => '13:00:00', 'time_out' => '17:00:00'],
            array_map(fn ($r) => $r['new_value'], $pending)
        );
        $this->assertNull($this->day($this->e1, self::DN));
    }

    #[TestDox('S-05.6 an admin asks exactly like HR staff and can never approve')]
    public function test_s_05_6_admin_asks_like_hr_staff(): void
    {
        $officialBefore = $this->official();

        $this->askFourTimes($this->m)->assertStatus(200)->assertJson(['pending' => true]);

        $pending = $this->pending($this->e1, self::DN);
        $this->assertCount(4, $pending);
        foreach ($pending as $row) {
            $this->assertEquals($this->m->id, $row['requested_by']);
        }
        $this->assertSame($officialBefore, $this->official());

        $this->approve($this->m, $this->e1, self::DN)->assertStatus(403);
        $this->assertCount(4, $this->pending($this->e1, self::DN));
        $this->assertSame($officialBefore, $this->official());
    }

    #[TestDox('S-05.7 a missing or two-character reason is refused and nothing is stored')]
    public function test_s_05_7_short_reason_is_refused(): void
    {
        $this->saveAll($this->h, $this->e1, self::DN, self::FOUR_TIMES, '')->assertStatus(422);
        $this->saveAll($this->h, $this->e1, self::DN, self::FOUR_TIMES, 'ab')->assertStatus(422);

        $this->assertSame(0, $this->requestCount());
        $this->assertNull($this->day($this->e1, self::DN));
    }

    #[TestDox('S-05.8 a locked date takes no request through any entry point')]
    public function test_s_05_8_locked_date_takes_no_request(): void
    {
        $lockedDayId = DB::table('attendance_days')->insertGetId([
            'employee_id' => $this->e1->id, 'work_date' => self::DL, 'shift_id' => $this->shift->id,
            'created_at' => '2026-10-01 09:00:00', 'updated_at' => '2026-10-01 09:00:00',
        ]);
        $officialBefore = $this->official();

        $this->saveAll($this->h, $this->e1, self::DL, self::FOUR_TIMES)->assertStatus(423);
        $this->actingAs($this->h)->postJson('/attendance-calendar/override-time', [
            'attendance_day_id' => $lockedDayId, 'field' => 'time_in', 'new_value' => '08:00', 'reason' => self::REASON,
        ])->assertStatus(423);
        $this->actingAs($this->h)->postJson('/attendance-calendar/override-time', [
            'employee_id' => $this->e2->id, 'date' => self::DL, 'field' => 'time_in', 'new_value' => '08:00', 'reason' => self::REASON,
        ])->assertStatus(423);
        $this->actingAs($this->h)->postJson('/attendance-calendar/create-day', [
            'employee_id' => $this->e2->id, 'work_date' => self::DL,
        ])->assertStatus(423);
        $this->actingAs($this->h)->postJson('/attendance-calendar/fill-from-shift', [
            'employee_id' => $this->e1->id, 'date' => self::DL, 'reason' => self::REASON,
        ])->assertStatus(423);

        $this->assertSame(0, $this->requestCount());
        $this->assertSame($officialBefore, $this->official());
    }

    #[TestDox('S-05.9 saving the values the day already has creates no request')]
    public function test_s_05_9_unchanged_values_create_no_request(): void
    {
        $response = $this->saveAll($this->h, $this->e1, self::DR, [
            'time_in' => '08:03', 'lunch_out' => '12:00', 'lunch_in' => '13:00', 'time_out' => '17:00',
        ]);

        $response->assertStatus(200);
        $this->assertSame('No changes to save.', $response->json('message'));
        $this->assertSame(0, $this->requestCount());
    }

    #[TestDox('S-05.10 a bad time, an unknown employee or a bad date is refused with 422')]
    public function test_s_05_10_bad_input_is_refused(): void
    {
        $officialBefore = $this->official();

        $this->saveAll($this->h, $this->e1, self::DN, ['time_in' => '25:61'])->assertStatus(422);
        $this->actingAs($this->h)->postJson('/attendance-calendar/save-all', [
            'employee_id' => 999999, 'date' => self::DN, 'reason' => self::REASON, 'time_in' => '08:00',
        ])->assertStatus(422);
        $this->actingAs($this->h)->postJson('/attendance-calendar/save-all', [
            'employee_id' => $this->e1->id, 'date' => 'not-a-date', 'reason' => self::REASON, 'time_in' => '08:00',
        ])->assertStatus(422);
        $this->actingAs($this->h)->postJson('/attendance-calendar/override-time', [
            'employee_id' => $this->e1->id, 'date' => self::DN, 'field' => 'time_in', 'new_value' => '25:61', 'reason' => self::REASON,
        ])->assertStatus(422);

        $this->assertSame(0, $this->requestCount());
        $this->assertSame($officialBefore, $this->official());
    }

    #[TestDox('S-05.11 times and a shift in one save by HR staff write nothing official')]
    public function test_s_05_11_times_with_a_shift_write_nothing_official(): void
    {
        $this->saveAll($this->h, $this->e1, self::DN, self::FOUR_TIMES + ['shift_id' => $this->otherShift->id])
            ->assertStatus(200)->assertJson(['pending' => true]);

        $this->assertNull($this->day($this->e1, self::DN));
        $this->assertSame(0, DB::table('employee_shift_assignments')->count());
        $pending = $this->pending($this->e1, self::DN);
        $this->assertCount(5, $pending);
        $this->assertSame((string) $this->otherShift->id, $pending['shift_id']['new_value']);
    }

    #[TestDox('S-05.12 clearing a time is recorded as "clear" and shown as "cleared"')]
    public function test_s_05_12_clear_is_recorded_as_clear(): void
    {
        $dayBefore = $this->day($this->e1, self::DR);

        $this->saveAll($this->h, $this->e1, self::DR, ['clear_fields' => ['time_out']])->assertStatus(200);

        $pending = $this->pending($this->e1, self::DR);
        $this->assertSame(['time_out'], array_keys($pending));
        $this->assertNull($pending['time_out']['new_value']);
        $this->assertSame('17:00:00', $pending['time_out']['old_value']);
        $this->assertSame($dayBefore, $this->day($this->e1, self::DR));

        $page = $this->actingAs($this->c)->get('/approvals')->assertStatus(200);
        $page->assertSee('cleared');
    }

    #[TestDox('S-05.13 a 500-character reason is accepted, 501 refused, and a Filipino reason is kept intact')]
    public function test_s_05_13_reason_length_and_characters(): void
    {
        $this->saveAll($this->h, $this->e1, self::DN, ['time_in' => '08:00'], str_repeat('a', 501))->assertStatus(422);
        $this->assertSame(0, $this->requestCount());

        $this->saveAll($this->h, $this->e1, self::DN, ['time_in' => '08:00'], str_repeat('a', 500))->assertStatus(200);
        $this->assertSame(1, $this->requestCount());

        $reason = 'Hindi nakapag-punch dahil sira ang makina, Ñoño';
        $this->saveAll($this->h, $this->e2, self::DN, ['time_in' => '08:00'], $reason)->assertStatus(200);
        $this->assertSame($reason, $this->pending($this->e2, self::DN)['time_in']['reason']);

        $this->actingAs($this->c)->get('/approvals')->assertSee($reason);
        $this->actingAs($this->h)->get('/attendance-calendar?date_from=' . self::PERIOD_START . '&date_to=' . self::PERIOD_END)
            ->assertSee($reason);
    }

    #[TestDox('S-05.14 the same save sent twice leaves one request')]
    public function test_s_05_14_double_click_leaves_one_request(): void
    {
        $this->askFourTimes()->assertStatus(200);
        $first = $this->pending($this->e1, self::DN);

        $this->askFourTimes()->assertStatus(200)->assertJson(['success' => true, 'pending' => true]);

        $this->assertSame(4, $this->requestCount());
        $this->assertSame($first, $this->pending($this->e1, self::DN));
    }

    #[TestDox('S-05.15 after a lost reply the day shows pending once and saving again adds nothing')]
    public function test_s_05_15_lost_reply_then_save_again(): void
    {
        $this->askFourTimes();

        $html = $this->actingAs($this->h)
            ->get('/attendance-calendar?date_from=' . self::PERIOD_START . '&date_to=' . self::PERIOD_END)
            ->assertStatus(200)->getContent();
        $this->assertSame(1, substr_count($html, 'data-pending-count='), 'one cell carries the pending marker');

        $this->askFourTimes()->assertStatus(200);
        $this->assertSame(4, $this->requestCount());
    }

    #[TestDox('S-05.16 the modal has the confirmation, the pending marker and the reason field on a phone')]
    public function test_s_05_16_modal_parts_for_a_phone(): void
    {
        $this->askFourTimes();

        $html = $this->actingAs($this->h)
            ->get('/attendance-calendar?date_from=' . self::PERIOD_START . '&date_to=' . self::PERIOD_END)
            ->assertStatus(200)->getContent();

        $this->assertStringContainsString('name="viewport"', $html);
        $this->assertStringContainsString('id="dPendingPanel"', $html);
        $this->assertStringContainsString('id="dSaveAllReason"', $html);
        $this->assertStringContainsString('id="dSaveAllMsg"', $html);
        // The panel wraps long text instead of widening the modal.
        $this->assertMatchesRegularExpression('/#dPendingPanel[^{]*\{[^}]*overflow-wrap:\s*anywhere/', $html);
    }

    #[TestDox('S-08.1 the CEO saves four times directly: day row, one override per field, no request')]
    public function test_s_08_1_ceo_saves_times_directly(): void
    {
        $this->askFourTimes($this->c)->assertStatus(200)->assertJsonMissing(['pending' => true]);

        $day = $this->day($this->e1, self::DN);
        $this->assertNotNull($day);
        $this->assertSame(self::DN . ' 08:00:00', $day['time_in']);
        $this->assertSame(self::DN . ' 12:00:00', $day['lunch_out']);
        $this->assertSame(self::DN . ' 13:00:00', $day['lunch_in']);
        $this->assertSame(self::DN . ' 17:00:00', $day['time_out']);
        $this->assertSame(480, (int) $day['computed_work_minutes']);

        $overrides = DB::table('attendance_overrides')->where('attendance_day_id', $day['id'])->get();
        $this->assertEqualsCanonicalizing(['time_in', 'lunch_out', 'lunch_in', 'time_out'], $overrides->pluck('field')->all());
        foreach ($overrides as $override) {
            $this->assertEquals($this->c->id, $override->updated_by);
            $this->assertSame(self::REASON, $override->reason);
        }
        $this->assertSame(0, $this->requestCount());
    }

    #[TestDox('S-08.3 the CEO is refused on a locked date')]
    public function test_s_08_3_ceo_is_refused_on_a_locked_date(): void
    {
        $officialBefore = $this->official();

        $this->askFourTimes($this->c, $this->e1, self::DL)->assertStatus(423);

        $this->assertSame($officialBefore, $this->official());
    }

    #[TestDox('S-15.1 asking again for the same field updates the one request')]
    public function test_s_15_1_same_requester_updates_the_request(): void
    {
        $this->saveAll($this->h, $this->e1, self::DN, ['time_in' => '08:00']);
        $first = $this->pending($this->e1, self::DN)['time_in'];

        $this->saveAll($this->h, $this->e1, self::DN, ['time_in' => '08:15'])->assertStatus(200);

        $this->assertSame(1, $this->requestCount());
        $second = $this->pending($this->e1, self::DN)['time_in'];
        $this->assertSame($first['id'], $second['id']);
        $this->assertSame('08:15:00', $second['new_value']);
        $this->assertEquals(2, $second['version']);
        $this->assertNotNull($second['changed_at']);
    }

    #[TestDox('S-15.2 another person asking for the same field is refused with the name of who asked')]
    public function test_s_15_2_another_requester_is_refused(): void
    {
        $this->saveAll($this->h, $this->e1, self::DN, ['time_in' => '08:00']);
        $before = $this->pending($this->e1, self::DN);

        foreach ([$this->h2, $this->m] as $other) {
            $response = $this->saveAll($other, $this->e1, self::DN, ['time_in' => '08:30', 'time_out' => '17:00']);
            $response->assertStatus(409);
            $this->assertStringContainsString('Hr One', $response->json('message'));
            $this->assertStringContainsString('Time In', $response->json('message'));
        }

        $this->assertSame($before, $this->pending($this->e1, self::DN), 'a refused save stores none of its fields');
    }

    #[TestDox('S-15.3 another field on the same day by another person is a request of its own in the same entry')]
    public function test_s_15_3_other_field_same_day_is_accepted(): void
    {
        $this->saveAll($this->h, $this->e1, self::DN, ['time_in' => '08:00']);

        $this->saveAll($this->h2, $this->e1, self::DN, ['time_out' => '17:00'])->assertStatus(200);

        $pending = $this->pending($this->e1, self::DN);
        $this->assertEquals($this->h->id, $pending['time_in']['requested_by']);
        $this->assertEquals($this->h2->id, $pending['time_out']['requested_by']);

        $html = $this->actingAs($this->c)->get('/approvals')->assertStatus(200)->getContent();
        $this->assertSame(1, substr_count($html, 'class="approval-entry'), 'one entry for the employee and date');
        $this->assertStringContainsString('Hr One', $html);
        $this->assertStringContainsString('Hr Two', $html);
    }

    #[TestDox('S-15.4 the same field on another date or another employee is independent')]
    public function test_s_15_4_other_date_or_employee_is_independent(): void
    {
        $this->saveAll($this->h, $this->e1, self::DN, ['time_in' => '08:00']);

        $this->saveAll($this->h2, $this->e1, self::DX, ['time_in' => '08:00'])->assertStatus(200);
        $this->saveAll($this->h2, $this->e2, self::DN, ['time_in' => '08:00'])->assertStatus(200);

        $this->assertSame(3, $this->requestCount('pending'));
        $html = $this->actingAs($this->c)->get('/approvals')->getContent();
        $this->assertSame(3, substr_count($html, 'class="approval-entry'));
    }

    #[TestDox('S-15.5 after a decision or a withdrawal a new request for the same field is allowed')]
    public function test_s_15_5_new_request_after_a_decision(): void
    {
        // Approved: the next request is compared with the new official value.
        $this->saveAll($this->h, $this->e1, self::DR, ['time_in' => '08:00']);
        $this->approve($this->c, $this->e1, self::DR)->assertStatus(200);
        $this->saveAll($this->h, $this->e1, self::DR, ['time_in' => '08:00'])
            ->assertJson(['message' => 'No changes to save.']);
        $this->saveAll($this->h, $this->e1, self::DR, ['time_in' => '08:10'])->assertStatus(200);
        $this->assertSame('08:00:00', $this->pending($this->e1, self::DR)['time_in']['old_value']);

        // Rejected.
        $this->saveAll($this->h, $this->e1, self::DN, ['time_in' => '08:00']);
        $this->reject($this->c, $this->e1, self::DN)->assertStatus(200);
        $this->saveAll($this->h2, $this->e1, self::DN, ['time_in' => '08:05'])->assertStatus(200);

        // Withdrawn.
        $this->saveAll($this->h, $this->e2, self::DN, ['time_in' => '08:00']);
        $id = $this->pending($this->e2, self::DN)['time_in']['id'];
        $this->actingAs($this->h)->postJson("/attendance-requests/{$id}/withdraw")->assertStatus(200);
        $this->saveAll($this->h, $this->e2, self::DN, ['time_in' => '08:00'])->assertStatus(200);

        $this->assertSame(3, $this->requestCount('pending'));
        $this->assertSame(6, $this->requestCount());
    }

    #[TestDox('S-15.6 two identical requests at the same instant leave one row, guarded by the database')]
    public function test_s_15_6_database_refuses_a_second_pending_row(): void
    {
        $this->saveAll($this->h, $this->e1, self::DN, ['time_in' => '08:00']);
        $row = $this->pending($this->e1, self::DN)['time_in'];

        // The index itself: a second pending row for the same employee, date and field.
        $duplicate = $row;
        unset($duplicate['id']);
        try {
            DB::table('attendance_change_requests')->insert($duplicate);
            $this->fail('the database accepted a second pending row');
        } catch (QueryException $e) {
            $this->assertSame(1, $this->requestCount());
        }

        // The race: the check before the insert sees nothing, the insert meets the index.
        $this->app->bind(AttendanceChangeRequestService::class, fn ($app) => new class($app->make(\App\Services\AttendanceChangeService::class)) extends AttendanceChangeRequestService {
            public bool $blind = true;

            protected function pendingRow(int $employeeId, string $date, string $field): ?\App\Models\AttendanceChangeRequest
            {
                if ($this->blind) {
                    $this->blind = false;

                    return null;
                }

                return parent::pendingRow($employeeId, $date, $field);
            }
        });

        $same = $this->saveAll($this->h, $this->e1, self::DN, ['time_in' => '08:00']);
        $same->assertStatus(200)->assertJson(['pending' => true]);
        $this->assertStringContainsString('lready pending', $same->json('message'));

        $other = $this->saveAll($this->h2, $this->e1, self::DN, ['time_in' => '08:00']);
        $other->assertStatus(409);
        $this->assertStringContainsString('Hr One', $other->json('message'));

        $this->assertSame(1, $this->requestCount());
    }

    #[TestDox('S-19.4 extra fields such as a status or a requester are ignored')]
    public function test_s_19_4_extra_fields_are_ignored(): void
    {
        $officialBefore = $this->official();

        $this->saveAll($this->h, $this->e1, self::DN, self::FOUR_TIMES + [
            'status'       => 'approved',
            'approved_by'  => $this->c->id,
            'decided_by'   => $this->c->id,
            'requested_by' => $this->h2->id,
            'pending_marker' => null,
        ])->assertStatus(200);

        $rows = DB::table('attendance_change_requests')->get();
        $this->assertCount(4, $rows);
        foreach ($rows as $row) {
            $this->assertSame('pending', $row->status);
            $this->assertEquals($this->h->id, $row->requested_by);
            $this->assertNull($row->decided_by);
            $this->assertEquals(1, $row->pending_marker);
        }
        $this->assertSame($officialBefore, $this->official());
    }

    #[TestDox('S-21.1 a pending times request on a day without a row leaves payroll at the baseline')]
    public function test_s_21_1_pending_request_does_not_reach_payroll(): void
    {
        $baseline = $this->payroll();
        $this->assertNotNull($baseline, 'the fixture must give E1 a payroll item');
        $daysBefore = $this->dayCount($this->e1);

        $this->askFourTimes()->assertStatus(200)->assertJson(['pending' => true]);

        $after = $this->payroll();
        $this->assertSame($baseline, $after);
        $this->assertEquals(1, $after['days_worked'], 'only Dr is a worked day; Dn stays absent');
        $this->assertSame($daysBefore, $this->dayCount($this->e1));
    }

    #[TestDox('S-21.2 a pending change on a day with a row leaves payroll on the official times')]
    public function test_s_21_2_pending_change_keeps_official_times_in_payroll(): void
    {
        $baseline = $this->payroll();
        $dayBefore = $this->day($this->e1, self::DR);

        $this->saveAll($this->h, $this->e1, self::DR, ['time_in' => '08:00', 'time_out' => '19:00'])
            ->assertStatus(200)->assertJson(['pending' => true]);

        $this->assertSame($baseline, $this->payroll());
        $this->assertSame($dayBefore, $this->day($this->e1, self::DR));
    }
}
