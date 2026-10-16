<?php

namespace Tests\Feature\ScheduleFeed;

use App\Models\AttendanceChangeRequest;
use App\Models\User;

/** What the feed says for each kind of day, and which rule wins when several apply. */
class FeedScheduleTest extends ScheduleFeedTestCase
{
    public function test_a_shift_day_gives_the_times_and_grace(): void
    {
        $e = $this->employee('1', 'Zk Name One', ['actual_name' => 'Employee One Actual']);

        $this->assertSame([
            'employee_id' => $e->id,
            'name'        => 'Employee One Actual',
            'department'  => 'Production',
            'date'        => self::MON,
            'scheduled'   => true,
            'shift'       => [
                'name' => 'Day shift', 'start' => '08:00', 'end' => '17:00',
                'lunch_start' => '12:00', 'lunch_end' => '13:00', 'grace_in_minutes' => 10,
            ],
        ], $this->entry($this->week(), $e, self::MON));
    }

    public function test_name_falls_back_to_the_full_name(): void
    {
        $e = $this->employee('1', 'Employee Full Name');
        $this->assertSame('Employee Full Name', $this->entry($this->week(), $e, self::MON)['name']);
    }

    public function test_a_rest_day(): void
    {
        $e = $this->employee('1', 'Employee One');
        $this->insert('rest_day_patterns', ['employee_id' => $e->id, 'day_of_week' => 0, 'effective_from' => '2026-01-01']);

        $entry = $this->entry($this->week(), $e, self::SUN);
        $this->assertFalse($entry['scheduled']);
        $this->assertSame('rest_day', $entry['reason']);
        $this->assertArrayNotHasKey('shift', $entry);
        $this->assertTrue($this->entry($this->week(), $e, '2026-10-10')['scheduled']);
    }

    public function test_a_day_off_with_its_type_and_a_cancelled_rest_day(): void
    {
        $e = $this->employee('1', 'Employee One');
        $this->insert('rest_day_patterns', ['employee_id' => $e->id, 'day_of_week' => 0, 'effective_from' => '2026-01-01']);
        $this->insert('day_offs', ['employee_id' => $e->id, 'off_date' => '2026-10-06', 'type' => 'day_off', 'remarks' => 'private remark']);
        $this->insert('day_offs', ['employee_id' => $e->id, 'off_date' => self::SUN, 'type' => 'cancel_day_off']);

        $response = $this->week();
        $entry = $this->entry($response, $e, '2026-10-06');
        $this->assertSame(['scheduled' => false, 'reason' => 'day_off', 'day_off_type' => 'day_off'],
            array_intersect_key($entry, array_flip(['scheduled', 'reason', 'day_off_type'])));
        $this->assertTrue($this->entry($response, $e, self::SUN)['scheduled']);
        $this->assertStringNotContainsString('private remark', $response->getContent());
    }

    public function test_approved_sil_is_leave_and_pending_or_rejected_stays_scheduled(): void
    {
        $e = $this->employee('1', 'Employee One');
        $hr = User::factory()->create(['role' => 'hr_staff']);
        $this->insert('sil_applications', ['employee_id' => $e->id, 'sil_date' => '2026-10-07', 'reason' => 'private reason', 'applied_by' => $hr->id]);
        foreach (['2026-10-08' => AttendanceChangeRequest::STATUS_PENDING, '2026-10-09' => AttendanceChangeRequest::STATUS_REJECTED] as $date => $status) {
            $this->insert('attendance_change_requests', [
                'employee_id' => $e->id, 'work_date' => $date, 'field' => AttendanceChangeRequest::FIELD_SIL,
                'new_value' => 'apply', 'reason' => 'asked', 'origin' => 'calendar', 'requested_by' => $hr->id,
                'status' => $status, 'pending_marker' => $status === AttendanceChangeRequest::STATUS_PENDING ? 1 : null,
            ]);
        }

        $response = $this->week();
        $leave = $this->entry($response, $e, '2026-10-07');
        $this->assertFalse($leave['scheduled']);
        $this->assertSame('leave', $leave['reason']);
        $this->assertTrue($this->entry($response, $e, '2026-10-08')['scheduled']);
        $this->assertTrue($this->entry($response, $e, '2026-10-09')['scheduled']);
        $this->assertStringNotContainsString('private reason', $response->getContent());
    }

    public function test_a_holiday_with_its_name_and_not_for_an_ineligible_status(): void
    {
        $e = $this->employee('1', 'Employee One');
        $trainee = $this->employee('2', 'Employee Two');
        $this->insert('holidays', ['date' => '2026-10-07', 'name' => 'Test Holiday', 'type' => 'regular', 'recurring' => false]);
        $this->insert('holidays', ['date' => '2020-10-09', 'name' => 'Recurring Test Day', 'type' => 'special', 'recurring' => true]);
        $statusId = $this->insert('employment_statuses', ['name' => 'Trainee (test)', 'holiday_eligible' => false]);
        $this->insert('employee_status_history', ['employee_id' => $trainee->id, 'employment_status_id' => $statusId, 'effective_from' => '2026-01-01']);

        $response = $this->week();
        $entry = $this->entry($response, $e, '2026-10-07');
        $this->assertSame(['scheduled' => false, 'reason' => 'holiday', 'holiday_name' => 'Test Holiday'],
            array_intersect_key($entry, array_flip(['scheduled', 'reason', 'holiday_name'])));
        $this->assertSame('Recurring Test Day', $this->entry($response, $e, '2026-10-09')['holiday_name']);
        // Payroll treats a holiday as a working day for an employee who is not eligible.
        $this->assertTrue($this->entry($response, $trainee, '2026-10-07')['scheduled']);
    }

    public function test_inactive_days_from_the_active_status_history(): void
    {
        $e = $this->employee('1', 'Employee One');
        $this->insert('employee_active_statuses', ['employee_id' => $e->id, 'status' => 'active', 'effective_from' => '2026-01-01', 'effective_until' => '2026-10-07']);
        $this->insert('employee_active_statuses', ['employee_id' => $e->id, 'status' => 'inactive', 'effective_from' => '2026-10-08', 'effective_until' => '2026-10-09']);
        $this->insert('employee_active_statuses', ['employee_id' => $e->id, 'status' => 'active', 'effective_from' => '2026-10-10']);
        // Inactive by the cached column only (no history): inactive every day.
        $gone = $this->employee('2', 'Employee Two', ['status' => 'inactive']);

        $response = $this->week();
        $this->assertTrue($this->entry($response, $e, '2026-10-07')['scheduled']);
        $this->assertSame(['scheduled' => false, 'reason' => 'inactive'],
            array_intersect_key($this->entry($response, $e, '2026-10-08'), array_flip(['scheduled', 'reason'])));
        $this->assertSame('inactive', $this->entry($response, $e, '2026-10-09')['reason']);
        $this->assertTrue($this->entry($response, $e, '2026-10-10')['scheduled']);
        $this->assertSame('inactive', $this->entry($response, $gone, self::MON)['reason']);
    }

    public function test_precedence_inactive_then_leave_then_day_off_then_holiday(): void
    {
        $e = $this->employee('1', 'Employee One');
        $hr = User::factory()->create(['role' => 'hr_staff']);
        // Monday: inactive + SIL. Tuesday: SIL + day off. Wednesday: day off + holiday. Thursday: rest day + holiday.
        $this->insert('employee_active_statuses', ['employee_id' => $e->id, 'status' => 'inactive', 'effective_from' => self::MON, 'effective_until' => self::MON]);
        $this->insert('employee_active_statuses', ['employee_id' => $e->id, 'status' => 'active', 'effective_from' => '2026-10-06']);
        foreach ([self::MON, '2026-10-06'] as $date) {
            $this->insert('sil_applications', ['employee_id' => $e->id, 'sil_date' => $date, 'reason' => 'x', 'applied_by' => $hr->id]);
        }
        foreach (['2026-10-06', '2026-10-07'] as $date) {
            $this->insert('day_offs', ['employee_id' => $e->id, 'off_date' => $date, 'type' => 'day_off']);
        }
        $this->insert('rest_day_patterns', ['employee_id' => $e->id, 'day_of_week' => 4, 'effective_from' => '2026-01-01']);
        foreach (['2026-10-07', '2026-10-08'] as $date) {
            $this->insert('holidays', ['date' => $date, 'name' => 'Holiday ' . $date, 'type' => 'regular', 'recurring' => false]);
        }

        $response = $this->week();
        $this->assertSame(
            ['inactive', 'leave', 'day_off', 'rest_day'],
            array_map(fn ($d) => $this->entry($response, $e, $d)['reason'], [self::MON, '2026-10-06', '2026-10-07', '2026-10-08'])
        );
    }

    public function test_employee_and_department_assignment_switching_mid_range(): void
    {
        $ceo = User::factory()->create(['role' => 'ceo']);
        $deptMode = $this->employee('1', 'Employee Dept Mode', ['schedule_mode' => 'department']);
        $manual = $this->employee('2', 'Employee Manual Mode');

        // The department switches to the late shift from Thursday, through the app's own page.
        $this->actingAs($ceo)->post("/departments/{$this->dept->id}/assign-shift", [
            'shift_id' => $this->lateShift->id, 'effective_date' => '2026-10-08',
        ])->assertRedirect();
        // The manual-mode employee has their own change from Wednesday, and one day back on Saturday.
        $this->insert('employee_shift_assignments', ['employee_id' => $manual->id, 'shift_id' => $this->lateShift->id, 'effective_date' => '2026-10-07']);
        $this->insert('employee_shift_assignments', ['employee_id' => $manual->id, 'shift_id' => $this->dayShift->id, 'effective_date' => '2026-10-10', 'effective_until' => '2026-10-10']);

        $response = $this->week();
        $shiftOf = fn ($e) => array_map(fn ($d) => $this->entry($response, $e, $d)['shift']['name'],
            ['2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09', '2026-10-10', self::SUN]);

        $this->assertSame(['Day shift', 'Day shift', 'Late shift', 'Late shift', 'Late shift', 'Late shift'], $shiftOf($deptMode));
        $this->assertSame(['Day shift', 'Late shift', 'Late shift', 'Late shift', 'Day shift', 'Late shift'], $shiftOf($manual));
        $this->assertSame('09:30', $this->entry($response, $deptMode, '2026-10-08')['shift']['start']);
        $this->assertSame(5, $this->entry($response, $deptMode, '2026-10-08')['shift']['grace_in_minutes']);
    }

    public function test_a_scheduled_day_without_any_shift_has_a_null_shift(): void
    {
        $e = $this->employee('1', 'Employee One', ['default_shift_id' => null]);
        $entry = $this->entry($this->week(), $e, self::MON);
        $this->assertTrue($entry['scheduled']);
        $this->assertNull($entry['shift']);
    }
}
