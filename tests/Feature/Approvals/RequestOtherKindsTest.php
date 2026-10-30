<?php

namespace Tests\Feature\Approvals;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * S-06, S-07 (without the bulk case), S-08.2, S-08.4, S-08.5, S-09.10, S-11.3,
 * S-17.4, S-17.5, S-17.9, S-17.10: approved overtime, SIL, a shift for a date
 * and rest-day changes.
 */
class RequestOtherKindsTest extends ApprovalsTestCase
{
    /* ── S-06 Overtime, SIL and shift changes also wait ── */

    #[TestDox('S-06.1 approved overtime asked by HR staff waits; "0 h" and "automatic" are different requests')]
    public function test_s_06_1_overtime_becomes_a_request(): void
    {
        $dayBefore = $this->day($this->e1, self::DR);

        $this->saveAll($this->h, $this->e1, self::DR, ['approved_ot_hours' => '2'])->assertStatus(200)->assertJson(['pending' => true]);

        $pending = $this->pending($this->e1, self::DR);
        $this->assertSame(['approved_overtime_minutes'], array_keys($pending));
        $this->assertSame('120', $pending['approved_overtime_minutes']['new_value']);
        $this->assertSame($dayBefore, $this->day($this->e1, self::DR));
        $this->assertSame(0, DB::table('attendance_overrides')->count());
        $this->actingAs($this->c)->get('/approvals')->assertSee('<span class="approval-value">2 h</span>', false);

        // "0" approves no overtime.
        $this->saveAll($this->h, $this->e1, self::DR, ['approved_ot_hours' => '0'])->assertStatus(200);
        $this->assertSame('0', $this->pending($this->e1, self::DR)['approved_overtime_minutes']['new_value']);
        // Asked: 0 h. The official value beside it is "automatic", which is not what was asked.
        $this->actingAs($this->c)->get('/approvals')
            ->assertSee('<span class="approval-value">0 h</span>', false)
            ->assertDontSee('<span class="approval-value">automatic</span>', false);

        // "Back to automatic" on a day that has approved overtime.
        $this->punchedDay($this->e2, self::DR, ['08:00:00', '12:00:00', '13:00:00', '18:00:00']);
        DB::table('attendance_days')->where('employee_id', $this->e2->id)->update(['approved_overtime_minutes' => 60]);
        $this->saveAll($this->h, $this->e2, self::DR, ['clear_approved_ot' => true])->assertStatus(200)->assertJson(['pending' => true]);
        $this->assertNull($this->pending($this->e2, self::DR)['approved_overtime_minutes']['new_value']);
        $this->assertEquals(60, $this->day($this->e2, self::DR)['approved_overtime_minutes']);
        $this->actingAs($this->c)->get('/approvals')
            ->assertSee('<span class="approval-value">automatic</span>', false)
            ->assertSee('<span class="approval-value">0 h</span>', false);
    }

    #[TestDox('S-06.2 SIL applied by HR staff waits: no application, same balance, same payroll')]
    public function test_s_06_2_sil_apply_becomes_a_request(): void
    {
        $this->silSetup($this->e1, 5);
        $payrollBefore = $this->payroll();

        $this->applySil($this->h, $this->e1, self::DN)->assertStatus(200)->assertJson(['success' => true, 'pending' => true]);

        $pending = $this->pending($this->e1, self::DN);
        $this->assertSame('apply', $pending['sil']['new_value']);
        $this->assertSame(0, DB::table('sil_applications')->count());
        $this->assertSame(5.0, $this->silRemaining($this->e1));
        $this->assertSame($payrollBefore, $this->payroll());
    }

    #[TestDox('S-06.3 a SIL removal asked by HR staff waits and the SIL stays applied')]
    public function test_s_06_3_sil_removal_becomes_a_request(): void
    {
        $this->silSetup($this->e1, 5);
        $this->applySil($this->c, $this->e1, self::DN)->assertStatus(200);
        $payrollBefore = $this->payroll();

        $this->removeSil($this->h, $this->e1, self::DN)->assertStatus(200)->assertJson(['pending' => true]);

        $this->assertSame('remove', $this->pending($this->e1, self::DN)['sil']['new_value']);
        $this->assertSame(1, DB::table('sil_applications')->count());
        $this->assertSame(4.0, $this->silRemaining($this->e1));
        $this->assertSame($payrollBefore, $this->payroll());

        // Every request carries a reason.
        $this->removeSil($this->h2, $this->e1, self::DN, null)->assertStatus(422);
    }

    #[TestDox('S-06.4 a shift for a date asked by HR staff waits and the old shift stays')]
    public function test_s_06_4_shift_becomes_a_request(): void
    {
        $response = $this->assignShift($this->h, $this->e1, self::DR, $this->otherShift);

        $response->assertStatus(422);
        $this->assertSame(0, $this->requestCount(), 'a request needs a reason');

        $this->actingAs($this->h)->postJson('/attendance-calendar/assign-shift', [
            'employee_id' => $this->e1->id, 'date' => self::DR, 'shift_id' => $this->otherShift->id, 'reason' => 'Lumipat ng shift',
        ])->assertStatus(200)->assertJson(['pending' => true]);

        $pending = $this->pending($this->e1, self::DR);
        $this->assertSame((string) $this->otherShift->id, $pending['shift_id']['new_value']);
        $this->assertSame((string) $this->shift->id, $pending['shift_id']['old_value']);
        $this->assertSame(0, DB::table('employee_shift_assignments')->count());
        $this->assertSame($this->shift->id, Employee::find($this->e1->id)->getShiftForDate(self::DR)->id);
    }

    #[TestDox('S-06.5 SIL that cannot be applied is refused with the existing message and no request')]
    public function test_s_06_5_sil_that_cannot_be_applied(): void
    {
        $notEligible = $this->applySil($this->h, $this->e1, self::DN);
        $notEligible->assertStatus(422);
        $this->assertStringContainsString('not marked as SIL-eligible', $notEligible->json('message'));

        $this->silSetup($this->e1, 0);
        $noBalance = $this->applySil($this->h, $this->e1, self::DN);
        $noBalance->assertStatus(422);
        $this->assertStringContainsString('No remaining SIL days for 2026', $noBalance->json('message'));

        $this->silSetup($this->e1, 5);
        $this->applySil($this->c, $this->e1, self::DN)->assertStatus(200);
        $already = $this->applySil($this->h, $this->e1, self::DN);
        $already->assertStatus(422);
        $this->assertStringContainsString('SIL is already applied for this date', $already->json('message'));

        $this->assertSame(0, $this->requestCount());
    }

    #[TestDox('S-06.6 with one day left, the second of two SIL requests cannot be approved')]
    public function test_s_06_6_last_sil_day_goes_once(): void
    {
        $this->silSetup($this->e1, 1);
        $this->applySil($this->h, $this->e1, self::DN)->assertStatus(200);
        $this->applySil($this->h, $this->e1, self::DX)->assertStatus(200);

        $this->approve($this->c, $this->e1, self::DN)->assertStatus(200);
        $second = $this->approve($this->c, $this->e1, self::DX);

        $second->assertStatus(422);
        $this->assertStringContainsString('No remaining SIL days', $second->json('message'));
        $this->assertSame(1, DB::table('sil_applications')->count());
        $this->assertCount(1, $this->pending($this->e1, self::DX));
        $this->actingAs($this->c)->get('/approvals')->assertSee('No remaining SIL days');
    }

    #[TestDox('S-06.7 times, overtime and a shift in one save are one entry, approved together')]
    public function test_s_06_7_one_save_one_entry(): void
    {
        $this->saveAll($this->h, $this->e1, self::DR, [
            'time_in' => '08:00', 'approved_ot_hours' => '1', 'shift_id' => $this->otherShift->id,
        ])->assertStatus(200)->assertJson(['pending' => true]);

        $this->assertSame(['time_in', 'shift_id', 'approved_overtime_minutes'], array_keys($this->pending($this->e1, self::DR)));
        $html = $this->actingAs($this->c)->get('/approvals')->getContent();
        $this->assertSame(1, substr_count($html, 'class="approval-entry'));
        $this->assertSame(1, substr_count($html, 'class="btn btn-success approval-approve'));

        $this->approve($this->c, $this->e1, self::DR)->assertStatus(200);

        $day = $this->day($this->e1, self::DR);
        $this->assertSame(self::DR . ' 08:00:00', $day['time_in']);
        $this->assertEquals(60, $day['approved_overtime_minutes']);
        $this->assertEquals($this->otherShift->id, $day['shift_id']);
        $this->assertSame(1, DB::table('employee_shift_assignments')->count());
        $this->assertSame(3, $this->requestCount('approved'));
    }

    #[TestDox('S-06.8 a SIL request on a day with times shows the times it would replace')]
    public function test_s_06_8_sil_request_shows_the_day(): void
    {
        $this->silSetup($this->e1, 5);
        $this->applySil($this->h, $this->e1, self::DR)->assertStatus(200);

        $page = $this->actingAs($this->c)->get('/approvals')->assertStatus(200);

        $page->assertSee('Apply SIL')->assertSee('This day has attendance')->assertSee('8:03 AM')->assertSee('5:00 PM');
    }

    /* ── S-07 Rest day changes ── */

    #[TestDox('S-07.1 a rest day on a day without a row is direct, logged, and not a request')]
    public function test_s_07_1_rest_day_on_an_absent_day_is_direct(): void
    {
        $this->toggleDayOff($this->h, $this->e1, self::DN, 'add_day_off', 'Mali ang iskedyul')->assertStatus(200)->assertJsonMissing(['pending' => true]);

        $dayOff = DB::table('day_offs')->get();
        $this->assertCount(1, $dayOff);
        $this->assertSame('day_off', $dayOff[0]->type);
        $logs = DB::table('day_off_logs')->get();
        $this->assertCount(1, $logs);
        $this->assertEquals($this->h->id, $logs[0]->updated_by);
        $this->assertSame('Mali ang iskedyul', $logs[0]->reason);
        $this->assertSame(0, $this->requestCount());
        $this->assertStringContainsString('data-status="day_off"', $this->cell($this->calendar($this->h), $this->e1, self::DN));
    }

    #[TestDox('S-07.2 cancelling a rest day on a day without a row is direct')]
    public function test_s_07_2_cancel_on_an_absent_day_is_direct(): void
    {
        $this->restDay($this->e1, self::DN);

        $this->toggleDayOff($this->h, $this->e1, self::DN, 'cancel_day_off')->assertStatus(200)->assertJsonMissing(['pending' => true]);

        $this->assertSame('cancel_day_off', DB::table('day_offs')->value('type'));
        $this->assertSame(1, DB::table('day_off_logs')->where('action', 'cancel_day_off')->where('updated_by', $this->h->id)->count());
        $this->assertSame(0, $this->requestCount());
    }

    #[TestDox('S-07.3 removing a day-off override on a day without a row is direct')]
    public function test_s_07_3_remove_on_an_absent_day_is_direct(): void
    {
        $this->restDay($this->e1, self::DN);

        $this->toggleDayOff($this->h, $this->e1, self::DN, 'remove_override')->assertStatus(200)->assertJsonMissing(['pending' => true]);

        $this->assertSame(0, DB::table('day_offs')->count());
        $this->assertSame(1, DB::table('day_off_logs')->where('action', 'remove_override')->count());
        $this->assertSame(0, $this->requestCount());
    }

    #[TestDox('S-07.4 a rest day on a day with a row waits and nothing is official yet')]
    public function test_s_07_4_rest_day_on_a_worked_day_becomes_a_request(): void
    {
        $officialBefore = $this->official();
        $payrollBefore = $this->payroll();

        $this->toggleDayOff($this->h, $this->e1, self::DR, 'add_day_off')->assertStatus(200)->assertJson(['success' => true, 'pending' => true]);

        $this->assertSame('add_day_off', $this->pending($this->e1, self::DR)['rest_day']['new_value']);
        $this->assertSame($officialBefore, $this->official());
        $this->assertSame($payrollBefore, $this->payroll());
        $this->assertStringContainsString('data-pending-count="1"', $this->cell($this->calendar($this->h), $this->e1, self::DR));
    }

    #[TestDox('S-07.5 cancelling or removing a rest day on a day with a row waits')]
    public function test_s_07_5_cancel_or_remove_on_a_worked_day_becomes_a_request(): void
    {
        $this->punchedDay($this->e2, self::DR, ['08:00:00', '12:00:00', '13:00:00', '17:00:00']);
        $this->restDay($this->e1, self::DR);
        $this->restDay($this->e2, self::DR);
        $officialBefore = $this->official();

        $this->toggleDayOff($this->h, $this->e1, self::DR, 'cancel_day_off')->assertStatus(200)->assertJson(['pending' => true]);
        $this->toggleDayOff($this->h, $this->e2, self::DR, 'remove_override')->assertStatus(200)->assertJson(['pending' => true]);

        $this->assertSame('cancel_day_off', $this->pending($this->e1, self::DR)['rest_day']['new_value']);
        $this->assertSame('remove_override', $this->pending($this->e2, self::DR)['rest_day']['new_value']);
        $this->assertSame($officialBefore, $this->official());
    }

    #[TestDox('S-07.6 an approved rest-day request equals the rest day the CEO would set directly')]
    public function test_s_07_6_approved_rest_day_equals_a_direct_one(): void
    {
        // The control: the same worked day for another employee, set as a rest day by the CEO.
        $this->punchedDay($this->e2, self::DR, ['08:03:00', '12:00:00', '13:00:00', '17:00:00']);
        $this->toggleDayOff($this->c, $this->e2, self::DR, 'add_day_off')->assertStatus(200);
        $this->toggleDayOff($this->h, $this->e1, self::DR, 'add_day_off');

        $this->approve($this->c, $this->e1, self::DR)->assertStatus(200);

        $this->assertSame($this->comparable('day_offs', $this->e2), $this->comparable('day_offs', $this->e1));
        $logs = DB::table('day_off_logs')->where('employee_id', $this->e1->id)->get();
        $this->assertCount(1, $logs);
        $this->assertEquals($this->c->id, $logs[0]->updated_by, 'the log names the CEO as the one who set it');
        $this->assertSame($this->comparable('day_off_logs', $this->e2), $this->comparable('day_off_logs', $this->e1));

        $request = DB::table('attendance_change_requests')->first();
        $this->assertSame('approved', $request->status);
        $this->assertEquals($this->h->id, $request->requested_by);
        $this->assertSame('day_off_log', $request->applied_type);
        $this->assertEquals($logs[0]->id, $request->applied_id);
        $cell = $this->cell($this->calendar($this->h), $this->e1, self::DR);
        $this->assertStringContainsString('"requested_by":"Hr One"', $cell);
        $this->assertStringContainsString('"approved_by":"Ceo Tester"', $cell);

        $this->assertSame($this->payrollOf($this->e2), $this->payrollOf($this->e1));
    }

    #[TestDox('S-07.7 a rest-day change on a locked date is refused, and so is a short reason')]
    public function test_s_07_7_locked_date_and_short_reason(): void
    {
        DB::table('attendance_days')->insert([
            'employee_id' => $this->e1->id, 'work_date' => self::DL, 'shift_id' => $this->shift->id,
            'created_at' => '2026-10-01 09:00:00', 'updated_at' => '2026-10-01 09:00:00',
        ]);
        $officialBefore = $this->official();

        $this->toggleDayOff($this->h, $this->e1, self::DL, 'add_day_off')->assertStatus(423);
        $this->toggleDayOff($this->h, $this->e2, self::DL, 'add_day_off')->assertStatus(423);
        $this->toggleDayOff($this->h, $this->e1, self::DN, 'add_day_off', '')->assertStatus(422);
        $this->toggleDayOff($this->h, $this->e1, self::DR, 'add_day_off', 'ab')->assertStatus(422);

        $this->assertSame($officialBefore, $this->official());
        $this->assertSame(0, $this->requestCount());
    }

    #[TestDox('S-07.8 a times request on a day that then became a rest day is flagged and cannot be approved')]
    public function test_s_07_8_times_request_then_rest_day(): void
    {
        $this->askFourTimes();

        $this->toggleDayOff($this->h, $this->e1, self::DN, 'add_day_off')->assertStatus(200)->assertJsonMissing(['pending' => true]);

        $this->assertSame('day_off', DB::table('day_offs')->value('type'));
        $this->actingAs($this->c)->get('/approvals')->assertSee('Now a rest day');
        $refused = $this->approve($this->c, $this->e1, self::DN);
        $refused->assertStatus(422);
        $this->assertStringContainsString('Now a rest day', $refused->json('message'));
        $this->assertNull($this->day($this->e1, self::DN));
        $this->reject($this->c, $this->e1, self::DN)->assertStatus(200);
    }

    #[TestDox('a day row that appears while HR staff set a rest day turns the change into a request')]
    public function test_rest_day_exception_is_decided_under_the_lock(): void
    {
        // The day row is created after the entry point looked for it and before the write
        // takes its lock, as an import finishing at that moment would do.
        $date = self::DN;
        $this->app->bind(\App\Services\AttendanceChangeService::class, fn ($app) => new class($app->make(\App\Services\AttendanceComputeService::class), $date) extends \App\Services\AttendanceChangeService {
            public function __construct(\App\Services\AttendanceComputeService $compute, private string $raceDate)
            {
                parent::__construct($compute);
            }

            public function changeRestDay(Employee $employee, string $date, string $action, string $reason, User $actor, bool $bulk = false, bool $onlyWithoutDayRow = false): \App\Models\DayOffLog
            {
                DB::table('attendance_days')->insert([
                    'employee_id' => $employee->id, 'work_date' => $this->raceDate,
                    'time_in' => $this->raceDate . ' 08:00:00', 'notes' => 'computed at the same moment',
                    'created_at' => now(), 'updated_at' => now(),
                ]);

                return parent::changeRestDay($employee, $date, $action, $reason, $actor, $bulk, $onlyWithoutDayRow);
            }
        });

        $this->toggleDayOff($this->h, $this->e1, self::DN, 'add_day_off')->assertStatus(200)->assertJson(['pending' => true]);

        $this->assertSame(0, DB::table('day_offs')->count());
        $this->assertSame(0, DB::table('day_off_logs')->count());
        $this->assertSame('add_day_off', $this->pending($this->e1, self::DN)['rest_day']['new_value']);
        $this->assertNotNull($this->day($this->e1, self::DN), 'the day row that appeared is still there');
    }

    #[TestDox('S-07.10 asking the same rest-day change again leaves one request')]
    public function test_s_07_10_same_rest_day_request_again(): void
    {
        $this->toggleDayOff($this->h, $this->e1, self::DR, 'add_day_off');
        $first = $this->pending($this->e1, self::DR);

        $this->toggleDayOff($this->h, $this->e1, self::DR, 'add_day_off')->assertStatus(200)->assertJson(['pending' => true]);

        $this->assertSame(1, $this->requestCount());
        $this->assertSame($first, $this->pending($this->e1, self::DR));
        $this->toggleDayOff($this->h2, $this->e1, self::DR, 'add_day_off')->assertStatus(409);
    }

    #[TestDox('S-07.11 the CEO sets and cancels a rest day directly, with or without a day row')]
    public function test_s_07_11_ceo_rest_day_is_direct(): void
    {
        $this->toggleDayOff($this->c, $this->e1, self::DR, 'add_day_off')->assertStatus(200)->assertJsonMissing(['pending' => true]);
        $this->toggleDayOff($this->c, $this->e1, self::DN, 'add_day_off')->assertStatus(200);
        $this->toggleDayOff($this->c, $this->e1, self::DR, 'cancel_day_off')->assertStatus(200);
        $this->toggleDayOff($this->c, $this->e1, self::DN, 'remove_override')->assertStatus(200);

        $this->assertSame(0, $this->requestCount());
        $this->assertSame(4, DB::table('day_off_logs')->where('updated_by', $this->c->id)->count());
        $this->assertSame(['cancel_day_off'], DB::table('day_offs')->pluck('type')->all());
    }

    #[TestDox('S-07.12 the Day Off Calendar refuses HR staff on a locked date and on a day with attendance')]
    public function test_s_07_12_day_off_calendar_guard(): void
    {
        $post = fn (User $user, Employee $employee, string $date) => $this->actingAs($user)->postJson('/day-off-calendar/toggle', [
            'employee_id' => $employee->id, 'date' => $date, 'action' => 'add_day_off',
        ]);
        DB::table('feature_permissions')->whereIn('role', ['hr_staff', 'admin'])->update(['can_view' => true, 'can_edit' => true]);
        $officialBefore = $this->official();

        foreach ([$this->h, $this->m] as $user) {
            $post($user, $this->e1, self::DL)->assertStatus(423);
            $refused = $post($user, $this->e1, self::DR);
            $refused->assertStatus(422);
            $this->assertStringContainsString('This day has attendance. Change it on the Attendance Calendar.', $refused->json('message'));
        }
        $this->assertSame($officialBefore, $this->official());
        $this->assertSame(0, $this->requestCount());

        // No day row: direct, as before. The CEO is unchanged, also on a day with attendance.
        $post($this->h, $this->e1, self::DN)->assertStatus(200);
        $post($this->c, $this->e1, self::DR)->assertStatus(200);
        $this->assertSame(2, DB::table('day_offs')->count());
        $this->assertSame(0, $this->requestCount());
    }

    #[TestDox('S-07.13 the Employee page refuses HR staff day-off overrides on a locked date and on a day with attendance')]
    public function test_s_07_13_employee_page_guard(): void
    {
        DB::table('feature_permissions')->whereIn('role', ['hr_staff', 'admin'])->update(['can_view' => true, 'can_edit' => true]);
        $add = fn (User $user, string $date) => $this->actingAs($user)->post("/employees/{$this->e1->id}/add-day-off", [
            'off_date' => $date, 'type' => 'day_off',
        ]);

        foreach ([$this->h, $this->m] as $user) {
            $add($user, self::DL)->assertStatus(423);
            $add($user, self::DR)->assertSessionHas('error', 'This day has attendance. Change it on the Attendance Calendar.');
        }
        $this->assertSame(0, DB::table('day_offs')->count());

        // An override that exists on a day with attendance can be neither changed nor removed.
        $this->restDay($this->e1, self::DR);
        $id = DB::table('day_offs')->value('id');
        $this->actingAs($this->h)->put("/employees/{$this->e1->id}/day-off/{$id}", ['off_date' => self::DR, 'type' => 'cancel_day_off'])
            ->assertSessionHas('error', 'This day has attendance. Change it on the Attendance Calendar.');
        $this->actingAs($this->h)->put("/employees/{$this->e1->id}/day-off/{$id}", ['off_date' => self::DL, 'type' => 'day_off'])
            ->assertStatus(423);
        $this->actingAs($this->h)->delete("/employees/{$this->e1->id}/day-off/{$id}")
            ->assertSessionHas('error', 'This day has attendance. Change it on the Attendance Calendar.');
        $this->assertSame('day_off', DB::table('day_offs')->where('id', $id)->value('type'));
        $this->assertSame(self::DR, substr(DB::table('day_offs')->where('id', $id)->value('off_date'), 0, 10));

        // No day row: direct, as before. The CEO is unchanged.
        $add($this->h, self::DN)->assertSessionHasNoErrors()->assertSessionMissing('error');
        $this->actingAs($this->c)->delete("/employees/{$this->e1->id}/day-off/{$id}")->assertSessionMissing('error');
        $this->assertSame([self::DN], DB::table('day_offs')->pluck('off_date')->map(fn ($d) => substr($d, 0, 10))->all());
        $this->assertSame(0, $this->requestCount());
    }

    /* ── S-08 The CEO's own edits stay direct ── */

    #[TestDox('S-08.2 every kind of change by the CEO is direct and creates no request')]
    public function test_s_08_2_ceo_changes_are_direct(): void
    {
        $this->silSetup($this->e1, 5);

        $this->saveAll($this->c, $this->e1, self::DR, ['time_in' => '08:00'])->assertStatus(200);
        $this->saveAll($this->c, $this->e1, self::DR, ['approved_ot_hours' => '1.5'])->assertStatus(200);
        $this->applySil($this->c, $this->e1, self::DN)->assertStatus(200);
        $this->assignShift($this->c, $this->e1, self::DX, $this->otherShift)->assertStatus(200);
        $this->toggleDayOff($this->c, $this->e2, self::DR, 'add_day_off')->assertStatus(200);
        $this->actingAs($this->c)->postJson('/attendance-calendar/bulk-action', [
            'date' => self::DX, 'employee_ids' => [$this->e1->id, $this->e2->id], 'action' => 'fill_from_shift', 'reason' => self::REASON,
        ])->assertStatus(200);
        $this->removeSil($this->c, $this->e1, self::DN, null)->assertStatus(200);

        $this->assertSame(0, $this->requestCount());
        $day = $this->day($this->e1, self::DR);
        $this->assertSame(self::DR . ' 08:00:00', $day['time_in']);
        $this->assertEquals(90, $day['approved_overtime_minutes']);
        $this->assertSame(0, DB::table('sil_applications')->count());
        $this->assertSame(1, DB::table('employee_shift_assignments')->count());
        $this->assertSame(1, DB::table('day_offs')->count());
        $this->assertNotNull($this->day($this->e1, self::DX));
        $this->assertNotNull($this->day($this->e2, self::DX));
    }

    #[TestDox('S-08.4 a user who is CEO now edits directly, whatever they were when they asked')]
    public function test_s_08_4_role_is_read_at_each_call(): void
    {
        $this->saveAll($this->m, $this->e1, self::DN, ['time_in' => '08:00'])->assertJson(['pending' => true]);
        DB::table('users')->where('id', $this->m->id)->update(['role' => 'ceo']);
        $promoted = User::find($this->m->id);

        $this->saveAll($promoted, $this->e2, self::DN, ['time_in' => '08:00'])->assertStatus(200)->assertJsonMissing(['pending' => true]);

        $this->assertNotNull($this->day($this->e2, self::DN));
        $this->assertSame(1, $this->requestCount('pending'), 'the earlier request stays a request');
        $this->approve($promoted, $this->e1, self::DN)->assertStatus(403);
    }

    #[TestDox('S-08.5 a direct CEO edit on a date with a pending request leaves the request pending')]
    public function test_s_08_5_direct_edit_beside_a_pending_request(): void
    {
        $this->saveAll($this->h, $this->e1, self::DR, ['time_in' => '08:00']);
        $before = $this->pending($this->e1, self::DR);

        $this->saveAll($this->c, $this->e1, self::DR, ['time_in' => '08:10', 'time_out' => '17:30'], 'Itinama ng CEO')
            ->assertStatus(200)->assertJsonMissing(['pending' => true]);

        $this->assertSame($before, $this->pending($this->e1, self::DR));
        $this->assertSame(self::DR . ' 08:10:00', $this->day($this->e1, self::DR)['time_in']);
        $this->approve($this->c, $this->e1, self::DR)->assertStatus(409)->assertJson(['needs_confirm' => true]);
    }

    /* ── S-09.10, S-11.3, S-17 ── */

    #[TestDox('S-09.10 a pending rest-day request leaves the cell a worked day with its official times')]
    public function test_s_09_10_pending_rest_day_keeps_the_worked_day(): void
    {
        $html = $this->calendar($this->h);
        $statusBefore = [];
        preg_match('/data-status="[^"]*"/', $this->cell($html, $this->e1, self::DR), $statusBefore);
        $summaryBefore = $this->rowSummary($html, $this->e1);

        $this->toggleDayOff($this->h, $this->e1, self::DR, 'add_day_off');

        $html = $this->calendar($this->h);
        $cell = $this->cell($html, $this->e1, self::DR);
        $this->assertStringContainsString('data-pending-count="1"', $cell);
        $this->assertStringContainsString($statusBefore[0], $cell);
        $this->assertStringNotContainsString('data-status="day_off"', $cell);
        $this->assertStringContainsString('data-time-in="08:03:00"', $cell);
        $this->assertSame($summaryBefore, $this->rowSummary($html, $this->e1));
    }

    #[TestDox('S-11.3 approved overtime, SIL, SIL removal and shift requests write what the direct path writes')]
    public function test_s_11_3_other_kinds_equal_the_direct_path(): void
    {
        $this->punchedDay($this->e2, self::DR, ['08:03:00', '12:00:00', '13:00:00', '17:00:00']);
        $this->silSetup($this->e1, 5);
        $this->silSetup($this->e2, 5);
        // SIL on Dx for both, applied directly, so that its removal can be asked for and compared.
        $this->applySil($this->c, $this->e1, self::DX)->assertStatus(200);
        $this->applySil($this->c, $this->e2, self::DX)->assertStatus(200);

        // The control: the CEO directly.
        $this->saveAll($this->c, $this->e2, self::DR, ['approved_ot_hours' => '2'], 'Naaprubahang overtime');
        $this->applySil($this->c, $this->e2, self::DN, 'Bakasyon na may bayad');
        $this->removeSil($this->c, $this->e2, self::DX, null);
        $this->assignShift($this->c, $this->e2, '2026-10-09', $this->otherShift);

        // The same four changes asked by HR staff.
        $this->saveAll($this->h, $this->e1, self::DR, ['approved_ot_hours' => '2'], 'Naaprubahang overtime');
        $this->applySil($this->h, $this->e1, self::DN, 'Bakasyon na may bayad');
        $this->removeSil($this->h, $this->e1, self::DX);
        $this->actingAs($this->h)->postJson('/attendance-calendar/assign-shift', [
            'employee_id' => $this->e1->id, 'date' => '2026-10-09', 'shift_id' => $this->otherShift->id, 'reason' => 'Lumipat ng shift',
        ]);
        $this->assertSame(4, $this->requestCount('pending'));
        $this->assertSame(4.0, $this->silRemaining($this->e1), 'one SIL day used, by the direct application on Dx');

        foreach ([self::DR, self::DN, self::DX, '2026-10-09'] as $date) {
            $this->approve($this->c, $this->e1, $date)->assertStatus(200);
        }

        $this->assertSame($this->comparable('attendance_overrides', $this->e2), $this->comparable('attendance_overrides', $this->e1));
        $this->assertSame($this->comparable('sil_applications', $this->e2), $this->comparable('sil_applications', $this->e1));
        $this->assertSame($this->comparable('employee_shift_assignments', $this->e2), $this->comparable('employee_shift_assignments', $this->e1));
        $this->assertEquals(120, $this->day($this->e1, self::DR)['approved_overtime_minutes']);
        $this->assertEquals($this->day($this->e2, self::DR)['approved_overtime_minutes'], $this->day($this->e1, self::DR)['approved_overtime_minutes']);
        // Dx given back, Dn taken: the balance is where it was, each approval moved it by exactly one day.
        $this->assertSame(4.0, $this->silRemaining($this->e1));
        $this->assertSame([self::DN], DB::table('sil_applications')->where('employee_id', $this->e1->id)->pluck('sil_date')->map(fn ($d) => substr($d, 0, 10))->all());
        $this->assertSame($this->payrollOf($this->e2), $this->payrollOf($this->e1));
    }

    #[TestDox('S-17.4 a day that became a rest day blocks the approval until the rest day is undone')]
    public function test_s_17_4_became_a_rest_day(): void
    {
        $this->askFourTimes();
        $this->toggleDayOff($this->c, $this->e1, self::DN, 'add_day_off');
        $officialBefore = $this->official();

        $refused = $this->approve($this->c, $this->e1, self::DN);
        $refused->assertStatus(422);
        $this->assertStringContainsString('Now a rest day', $refused->json('message'));
        $this->assertSame($officialBefore, $this->official());
        $this->assertCount(4, $this->pending($this->e1, self::DN));

        $this->toggleDayOff($this->c, $this->e1, self::DN, 'remove_override');
        $this->approve($this->c, $this->e1, self::DN)->assertStatus(200);
        $this->assertSame(self::DN . ' 08:00:00', $this->day($this->e1, self::DN)['time_in']);
    }

    #[TestDox('S-17.5 SIL applied for the date blocks the approval of times until the SIL is removed')]
    public function test_s_17_5_sil_applied_meanwhile(): void
    {
        $this->silSetup($this->e1, 5);
        $this->askFourTimes();
        $this->askFourTimes($this->h, $this->e2);
        $this->applySil($this->c, $this->e1, self::DN)->assertStatus(200);
        $officialBefore = $this->official();

        $refused = $this->approve($this->c, $this->e1, self::DN);
        $refused->assertStatus(422);
        $this->assertStringContainsString('SIL applied', $refused->json('message'));
        $this->assertSame($officialBefore, $this->official());
        $this->actingAs($this->c)->get('/approvals')->assertSee('SIL applied');
        $this->reject($this->c, $this->e2, self::DN)->assertStatus(200);

        $this->removeSil($this->c, $this->e1, self::DN, null);
        $this->approve($this->c, $this->e1, self::DN)->assertStatus(200);
        $this->assertNotNull($this->day($this->e1, self::DN));
    }

    #[TestDox('S-17.9 a SIL request is refused at approval when the balance is gone, and closes as no longer needed when the date is already SIL')]
    public function test_s_17_9_sil_request_after_the_balance_changed(): void
    {
        $this->silSetup($this->e1, 5);
        $this->applySil($this->h, $this->e1, self::DN)->assertStatus(200);
        $this->applySil($this->h, $this->e1, self::DX)->assertStatus(200);

        $this->silSetup($this->e1, 0);
        $noBalance = $this->approve($this->c, $this->e1, self::DN);
        $noBalance->assertStatus(422);
        $this->assertStringContainsString('No remaining SIL days', $noBalance->json('message'));

        $this->silSetup($this->e1, 5);
        $this->applySil($this->c, $this->e1, self::DX)->assertStatus(200);
        // Spec 007: what it asks for is already official, so it closes instead of being refused.
        $already = $this->approve($this->c, $this->e1, self::DX, ['confirmed' => true]);
        $already->assertStatus(200);
        $this->assertStringContainsString('No longer needed', $already->json('message'));

        $this->assertSame(1, DB::table('sil_applications')->count());
        $this->assertSame(1, $this->requestCount('pending'));
        $this->assertSame(1, $this->requestCount('no_longer_needed'));
    }

    #[TestDox('S-17.10 a rest-day request is still applied after the day row was removed')]
    public function test_s_17_10_rest_day_request_after_the_day_row_changed(): void
    {
        $this->toggleDayOff($this->h, $this->e1, self::DR, 'add_day_off');
        DB::table('attendance_days')->where('employee_id', $this->e1->id)->delete();
        DB::table('attendance_logs')->where('employee_id', $this->e1->id)->delete();

        $this->approve($this->c, $this->e1, self::DR)->assertStatus(200);

        $this->assertSame('day_off', DB::table('day_offs')->where('employee_id', $this->e1->id)->value('type'));
        $this->assertSame(1, DB::table('day_off_logs')->count());
        $this->assertSame(1, $this->requestCount('approved'));
    }

    /** A rest-day override on a date, as the database holds it. */
    private function restDay(Employee $employee, string $date, string $type = 'day_off'): void
    {
        DB::table('day_offs')->insert([
            'employee_id' => $employee->id, 'off_date' => $date, 'type' => $type,
            'created_at' => '2026-10-01 09:00:00', 'updated_at' => '2026-10-01 09:00:00',
        ]);
    }
}
