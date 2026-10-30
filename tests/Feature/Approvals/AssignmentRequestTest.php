<?php

namespace Tests\Feature\Approvals;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Spec 007 story .10: a shift assignment added, edited or removed on the employee profile
 * by someone who must ask is one pending approval for its whole range.
 */
class AssignmentRequestTest extends ShiftChangeTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['ceo', 'hr_staff', 'admin'] as $role) {
            foreach (['shift_assignments' => 'Shift assignments', 'nav_employees' => 'Employees'] as $key => $label) {
                DB::table('feature_permissions')->updateOrInsert(
                    ['role' => $role, 'feature_key' => $key],
                    ['feature_label' => $label, 'can_view' => true, 'can_edit' => true, 'created_at' => now(), 'updated_at' => now()]
                );
            }
        }
        // A computed day on the locked date, made before it was locked.
        $this->unlock(self::DL);
        $this->punchedDay($this->e1, self::DL, ['08:03:00', '12:00:00', '13:00:00', '17:00:00']);
        $this->lock(self::DL);
    }

    protected function addAssignment(User $user, Employee $employee, array $fields = []): TestResponse
    {
        return $this->actingAs($user)->post('/employees/' . $employee->id . '/assign-shift', array_merge([
            'shift_id' => $this->otherShift->id, 'effective_date' => self::PERIOD_START, 'remarks' => 'Bagong schedule',
        ], $fields));
    }

    protected function assignments(Employee $employee): array
    {
        return DB::table('employee_shift_assignments')->where('employee_id', $employee->id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    }

    protected function assignmentRequest(Employee $employee): ?array
    {
        $row = DB::table('attendance_change_requests')->where('employee_id', $employee->id)->where('field', 'shift_assignment')->orderByDesc('id')->first();

        return $row ? (array) $row : null;
    }

    protected function approveAssignment(Employee $employee, array $extra = []): TestResponse
    {
        $row = $this->assignmentRequest($employee);

        return $this->actingAs($this->c)->postJson('/approvals/' . $row['id'] . '/approve', array_merge(['seen' => $row['id'] . ':' . $row['version']], $extra));
    }

    protected function assignmentCard(string $html, Employee $employee): string
    {
        $start = strpos($html, 'data-assignment-employee="' . $employee->id . '"');
        $this->assertNotFalse($start, "no assignment card for employee {$employee->id}");

        return substr($html, $start, strpos($html, '<!-- /approval-assignment -->', $start) - $start);
    }

    #[TestDox('S-007.10 a profile assignment by HR is one pending card; nothing is in effect until approved')]
    public function test_s_007_10_request_is_one_card(): void
    {
        $officialBefore = $this->official();
        $modeBefore = Employee::find($this->e1->id)->schedule_mode;

        $this->addAssignment($this->h, $this->e1)->assertRedirect()->assertSessionHas('success');

        $this->assertSame($officialBefore, $this->official(), 'nothing in effect');
        $this->assertSame($modeBefore, Employee::find($this->e1->id)->schedule_mode);
        $row = $this->assignmentRequest($this->e1);
        $this->assertSame('pending', $row['status']);
        $this->assertSame('Bagong schedule', $row['reason']);
        $this->assertSame(1, $this->requestCount('pending'));

        $html = $this->page();
        $this->assertStringContainsString('id="navApprovalsCount">1<', $html);
        $card = $this->assignmentCard($html, $this->e1);
        $this->assertStringContainsString('Employee One', $card);
        $this->assertStringContainsString('Shift assignment → Late shift', $card);
        $this->assertStringContainsString('from Oct 01, 2026', $card);
        $this->assertStringContainsString('ongoing', $card);
        $this->assertStringContainsString('Asked by Hr One', $card);
        $this->assertStringContainsString('See each day', $card);
        // The computed days in the range: DR, DX, D9 preview; DL is locked and named.
        $this->assertSame(3, substr_count($card, 'data-preview="ok"'));
        $this->assertStringContainsString('Oct 07 is locked: its day is not recomputed', $card);
        $this->assertStringContainsString('data-total="late"', $card);
        $this->assertSame(0, substr_count($html, 'class="approval-entry card'), 'no per-day card for its start date');

        // The profile shows it as pending, not in effect.
        $profile = $this->actingAs($this->h)->get('/employees/' . $this->e1->id . '/edit')->assertStatus(200)->getContent();
        $this->assertStringContainsString('Pending approval', $profile);
    }

    #[TestDox('S-007.10 a reason is required to ask')]
    public function test_s_007_10_reason_required(): void
    {
        $this->addAssignment($this->h, $this->e1, ['remarks' => ''])->assertRedirect()->assertSessionHas('error');
        $this->assertSame(0, $this->requestCount());
        $this->assertSame([], $this->assignments($this->e1));
    }

    #[TestDox('S-007.10 approving creates the same rows as the direct path and recomputes the days, not the locked one')]
    public function test_s_007_10_approve_is_the_direct_path_plus_recompute(): void
    {
        $this->addAssignment($this->h, $this->e1)->assertRedirect();
        $lockedBefore = $this->day($this->e1, self::DL);
        $this->approveAssignment($this->e1)->assertStatus(200)->assertJson(['success' => true]);
        // The same assignment entered by the CEO on E2 (the direct path, unchanged).
        $this->addAssignment($this->c, $this->e2)->assertRedirect();

        $this->assertSame($this->comparable('employee_shift_assignments', $this->e2, 'effective_date'), $this->comparable('employee_shift_assignments', $this->e1, 'effective_date'));
        $this->assertSame(Employee::find($this->e2->id)->schedule_mode, Employee::find($this->e1->id)->schedule_mode);
        $this->assertSame('approved', $this->assignmentRequest($this->e1)['status']);
        $this->assertSame($this->c->id, (int) $this->assignmentRequest($this->e1)['decided_by']);
        $this->assertSame(0, $this->requestCount('pending'));

        $dr = $this->day($this->e1, self::DR);
        $this->assertSame($this->otherShift->id, (int) $dr['shift_id']);
        $this->assertSame(0, (int) $dr['computed_late_minutes']);
        $this->assertSame(60, (int) $dr['computed_early_minutes']);
        $this->assertSame($lockedBefore, $this->day($this->e1, self::DL), 'a locked date is not recomputed');
    }

    #[TestDox('S-007.10 the previous open assignment is closed on approval, as the profile form does')]
    public function test_s_007_10_previous_open_assignment_closed(): void
    {
        $this->addAssignment($this->c, $this->e1, ['shift_id' => $this->shift->id, 'effective_date' => '2026-09-01'])->assertRedirect();
        $this->addAssignment($this->h, $this->e1)->assertRedirect();
        $this->assertNull($this->assignments($this->e1)[0]['effective_until'], 'not closed while pending');

        $this->approveAssignment($this->e1)->assertStatus(200);

        $rows = $this->assignments($this->e1);
        $this->assertCount(2, $rows);
        $this->assertSame('2026-09-30', substr($rows[0]['effective_until'], 0, 10));
    }

    #[TestDox('S-007.10 an assignment that overlaps by the time of approval is refused and stays pending')]
    public function test_s_007_10_overlap_at_approval(): void
    {
        $this->addAssignment($this->h, $this->e1, ['effective_until' => '2026-10-31'])->assertRedirect();
        $this->addAssignment($this->c, $this->e1, ['shift_id' => $this->shift->id, 'effective_date' => '2026-10-10', 'effective_until' => '2026-10-20'])->assertRedirect();
        $before = $this->official();

        $this->approveAssignment($this->e1)->assertStatus(422);

        $this->assertSame($before, $this->official());
        $this->assertSame('pending', $this->assignmentRequest($this->e1)['status']);
    }

    #[TestDox('S-007.10 rejecting changes nothing')]
    public function test_s_007_10_reject(): void
    {
        $this->addAssignment($this->h, $this->e1)->assertRedirect();
        $before = $this->official();
        $row = $this->assignmentRequest($this->e1);

        $this->actingAs($this->c)->postJson('/approvals/' . $row['id'] . '/reject', ['seen' => $row['id'] . ':1', 'reason' => 'Hindi pa ngayon'])->assertStatus(200);

        $this->assertSame($before, $this->official());
        $this->assertSame('rejected', $this->assignmentRequest($this->e1)['status']);
    }

    #[TestDox('S-007.10 the CEO adds an assignment directly, as before')]
    public function test_s_007_10_ceo_direct(): void
    {
        $this->addAssignment($this->c, $this->e1)->assertRedirect()->assertSessionHas('success');
        $this->assertCount(1, $this->assignments($this->e1));
        $this->assertSame(0, $this->requestCount());
    }

    #[TestDox('S-007.10 editing or removing an assignment by HR is a request; approving it applies it')]
    public function test_s_007_10_edit_and_delete_are_requests(): void
    {
        $this->addAssignment($this->c, $this->e1, ['shift_id' => $this->shift->id, 'effective_date' => '2026-09-01'])->assertRedirect();
        $assignment = $this->assignments($this->e1)[0];

        // Edit: the shift becomes the late one.
        $this->actingAs($this->h)->put('/employees/' . $this->e1->id . '/shift-assignment/' . $assignment['id'], [
            'shift_id' => $this->otherShift->id, 'effective_date' => '2026-09-01', 'remarks' => 'Palit ng shift',
        ])->assertRedirect()->assertSessionHas('success');
        $this->assertSame($assignment, $this->assignments($this->e1)[0], 'not changed while pending');
        $this->assertStringContainsString('Change pending approval', $this->actingAs($this->h)->get('/employees/' . $this->e1->id . '/edit')->getContent());
        $this->approveAssignment($this->e1)->assertStatus(200);
        $this->assertSame($this->otherShift->id, (int) $this->assignments($this->e1)[0]['shift_id']);
        $this->assertSame($this->otherShift->id, (int) $this->day($this->e1, self::DR)['shift_id'], 'the days of the range are recomputed');

        // Delete without a reason is refused; with one it is a request.
        $url = '/employees/' . $this->e1->id . '/shift-assignment/' . $assignment['id'];
        $this->actingAs($this->h)->delete($url)->assertRedirect()->assertSessionHas('error');
        $this->actingAs($this->h)->delete($url, ['reason' => 'Mali ang na-encode'])->assertRedirect()->assertSessionHas('success');
        $this->assertCount(1, $this->assignments($this->e1), 'not removed while pending');
        $this->assertStringContainsString('Removal pending approval', $this->actingAs($this->h)->get('/employees/' . $this->e1->id . '/edit')->getContent());
        $this->assertStringContainsString('Remove shift assignment', $this->assignmentCard($this->page(), $this->e1));
        $this->approveAssignment($this->e1)->assertStatus(200);
        $this->assertSame([], $this->assignments($this->e1));
    }

    #[TestDox('S-007.10 an edit whose assignment the CEO changed since needs a confirm on the card')]
    public function test_s_007_10_changed_since_needs_confirm(): void
    {
        $this->addAssignment($this->c, $this->e1, ['shift_id' => $this->shift->id, 'effective_date' => '2026-09-01'])->assertRedirect();
        $id = $this->assignments($this->e1)[0]['id'];
        $this->actingAs($this->h)->put('/employees/' . $this->e1->id . '/shift-assignment/' . $id, [
            'shift_id' => $this->otherShift->id, 'effective_date' => '2026-09-01', 'remarks' => 'Palit ng shift',
        ])->assertSessionHas('success');
        // The CEO moves the start of that assignment directly.
        $this->actingAs($this->c)->put('/employees/' . $this->e1->id . '/shift-assignment/' . $id, [
            'shift_id' => $this->shift->id, 'effective_date' => '2026-09-15', 'remarks' => 'CEO',
        ])->assertSessionHas('success');

        $card = $this->assignmentCard($this->page(), $this->e1);
        $this->assertStringContainsString('name="confirmed"', $card);
        $this->assertStringContainsString('The assignment was changed since this was asked', $card);

        $this->approveAssignment($this->e1)->assertStatus(409)->assertJson(['needs_confirm' => true]);
        $this->approveAssignment($this->e1, ['confirmed' => true])->assertStatus(200);
        $this->assertSame($this->otherShift->id, (int) $this->assignments($this->e1)[0]['shift_id']);
    }

    #[TestDox('S-007.10 an addition with an end date that closes an open assignment recomputes past its end too')]
    public function test_s_007_10_range_reaches_past_the_end(): void
    {
        $this->addAssignment($this->c, $this->e1, ['shift_id' => $this->shift->id, 'effective_date' => '2026-09-01'])->assertRedirect();
        $this->addAssignment($this->h, $this->e1, ['effective_until' => self::DN])->assertRedirect();
        $row = \App\Models\AttendanceChangeRequest::where('field', 'shift_assignment')->firstOrFail();

        $this->assertSame([self::PERIOD_START, null], app(\App\Services\AttendanceApprovalService::class)->assignmentRange($row));
    }

    #[TestDox('S-007.10 an overlap or a second different change from the same date is refused when asked')]
    public function test_s_007_10_refused_when_asked(): void
    {
        $this->addAssignment($this->c, $this->e1, ['shift_id' => $this->shift->id, 'effective_date' => '2026-10-10', 'effective_until' => '2026-10-20'])->assertRedirect();
        $this->addAssignment($this->h, $this->e1, ['effective_until' => '2026-10-31'])->assertSessionHas('error');
        $this->assertSame(0, $this->requestCount());

        $id = $this->assignments($this->e1)[0]['id'];
        $this->actingAs($this->h)->delete('/employees/' . $this->e1->id . '/shift-assignment/' . $id, ['reason' => 'Mali'])->assertSessionHas('success');
        $this->actingAs($this->h)->put('/employees/' . $this->e1->id . '/shift-assignment/' . $id, [
            'shift_id' => $this->otherShift->id, 'effective_date' => '2026-10-10', 'effective_until' => '2026-10-20', 'remarks' => 'Palit',
        ])->assertSessionHas('error');
        $this->assertSame(1, $this->requestCount('pending'));
        $this->assertSame('profile_delete', $this->assignmentRequest($this->e1)['origin'], 'the first change waits; the second was not stored');
    }
}
