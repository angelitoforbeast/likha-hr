<?php

namespace Tests\Feature\Approvals;

use App\Models\Employee;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Spec 007: a pending request whose value is already the official one closes by itself
 * as "no longer needed". It writes nothing it asked for, and keeps its record.
 */
class AutoCloseTest extends ShiftChangeTestCase
{
    /** The profile assignment of the late shift from the start of the period, by the CEO (direct). */
    protected function profileAssignment(Employee $employee, string $from = self::PERIOD_START): void
    {
        DB::table('feature_permissions')->updateOrInsert(
            ['role' => 'ceo', 'feature_key' => 'shift_assignments'],
            ['feature_label' => 'Shift assignments', 'can_view' => true, 'can_edit' => true, 'created_at' => now(), 'updated_at' => now()]
        );
        $this->actingAs($this->c)->post('/employees/' . $employee->id . '/assign-shift', [
            'shift_id' => $this->otherShift->id, 'effective_date' => $from, 'remarks' => 'Bagong schedule',
        ])->assertRedirect();
        $this->assertSame(1, DB::table('employee_shift_assignments')->where('employee_id', $employee->id)->whereNull('effective_until')->count());
    }

    protected function row(Employee $employee, string $date, string $field = 'shift_id'): array
    {
        return (array) DB::table('attendance_change_requests')->where('employee_id', $employee->id)
            ->where('work_date', $date)->where('field', $field)->orderByDesc('id')->first();
    }

    #[TestDox('S-007.6 requests the profile assignment already made official close on page load as no longer needed, with their record kept')]
    public function test_s_007_6_closes_on_page_load(): void
    {
        $this->askChange($this->e1, [self::DR, self::DX, self::D9]);
        $this->profileAssignment($this->e1);
        $officialBefore = DB::table('employee_shift_assignments')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        $html = $this->page();

        $this->assertStringContainsString('Nothing is waiting for approval.', $html);
        $this->assertStringNotContainsString('navApprovalsCount', $html);
        foreach ([self::DR, self::DX, self::D9] as $date) {
            $row = $this->row($this->e1, $date);
            $this->assertSame('no_longer_needed', $row['status']);
            $this->assertNull($row['pending_marker']);
            $this->assertNull($row['decided_by'], 'closed by itself, not by a person');
            $this->assertNotNull($row['decided_at']);
            $this->assertSame($this->h->id, (int) $row['requested_by']);
            $this->assertSame('Lumipat ng shift', $row['reason']);
            $this->assertStringContainsString('already Late shift', $row['decision_reason']);
            $this->assertNull($row['applied_type']);
        }
        // Nothing asked for was written: no single-day assignment.
        $this->assertSame($officialBefore, DB::table('employee_shift_assignments')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all());

        $tab = $this->page('?status=no_longer_needed');
        $this->assertSame(3, substr_count($tab, 'class="approval-entry card'));
        $this->assertStringContainsString('No longer needed', $tab);
        $this->assertStringContainsString('Asked by Hr One', $tab);
        $this->assertStringContainsString('Closed by itself on', $tab);
    }

    #[TestDox('S-007.6 a request someone already entered closes; a locked date is left as it is')]
    public function test_s_007_6_same_edit_and_locked_date(): void
    {
        $this->askFourTimes();                                            // E1/Dn, four times
        $this->askChange($this->e2, [self::DX]);
        $this->lock(self::DX);
        // The CEO enters the same four times directly; E2 gets the late shift on the profile.
        $this->saveAll($this->c, $this->e1, self::DN, self::FOUR_TIMES)->assertStatus(200);
        $this->profileAssignment($this->e2);

        $this->page();

        foreach (array_keys(self::FOUR_TIMES) as $field) {
            $this->assertSame('no_longer_needed', $this->row($this->e1, self::DN, $field)['status'], $field);
        }
        $this->assertSame('pending', $this->row($this->e2, self::DX)['status'], 'never on a locked date');
    }

    #[TestDox('S-007.6 a request that still changes something stays pending')]
    public function test_s_007_6_different_value_stays(): void
    {
        $this->askChange($this->e1, [self::DR]);
        $this->saveAll($this->h, $this->e2, self::DN, ['time_in' => '08:00']);
        $this->page();
        $this->assertSame(2, $this->requestCount('pending'));
        $this->assertSame(0, $this->requestCount('no_longer_needed'));
    }

    #[TestDox('S-007.7 approving a group where one day is already official approves the others and closes that one')]
    public function test_s_007_7_partial_group(): void
    {
        $dates = [self::DR, self::DX, self::D9];
        $this->askChange($this->e1, $dates);
        [$key] = $this->groupKeys($this->page());
        $entries = $this->entriesOf($this->e1, $dates);
        // After the page was loaded, the CEO sets the late shift on DX directly.
        $this->assignShift($this->c, $this->e1, self::DX, $this->otherShift)->assertStatus(200);
        $direct = DB::table('employee_shift_assignments')->where('employee_id', $this->e1->id)->count();

        $this->groupPost('approve', $key, $entries)
            ->assertStatus(200)->assertJson(['done' => 2, 'closed' => 1]);

        $this->assertSame('approved', $this->row($this->e1, self::DR)['status']);
        $this->assertSame('no_longer_needed', $this->row($this->e1, self::DX)['status']);
        $this->assertSame('approved', $this->row($this->e1, self::D9)['status']);
        $this->assertSame($direct + 2, DB::table('employee_shift_assignments')->where('employee_id', $this->e1->id)->count(), 'DX got no second assignment');
    }

    #[TestDox('S-007.8 a closed day whose minutes were computed with the old shift is recomputed as its approval would')]
    public function test_s_007_8_closed_days_are_recomputed(): void
    {
        $dates = [self::DR, self::DX, self::D9];
        $this->askChange($this->e1, $dates);
        $this->askChange($this->e2, $dates, $this->h2);
        $this->profileAssignment($this->e1);
        // The profile assignment recomputes nothing: E1's days still carry the day shift.
        $this->assertSame($this->shift->id, (int) $this->day($this->e1, self::DR)['shift_id']);

        $this->page();
        // E2 the same change, approved the normal way.
        foreach ($dates as $date) {
            $this->approve($this->c, $this->e2, $date)->assertStatus(200);
        }

        foreach ($dates as $date) {
            $this->assertSame('no_longer_needed', $this->row($this->e1, $date)['status']);
            $e1 = $this->day($this->e1, $date);
            $e2 = $this->day($this->e2, $date);
            $this->assertSame($this->otherShift->id, (int) $e1['shift_id'], $date);
            foreach (['computed_work_minutes', 'computed_late_minutes', 'computed_early_minutes', 'computed_overtime_minutes', 'payable_work_minutes', 'needs_review', 'notes'] as $column) {
                $this->assertEquals($e2[$column], $e1[$column], "{$date} {$column}");
            }
        }
        // DR: in 8:03 is not late for 9:00, out 17:00 is an hour early.
        $this->assertSame(0, (int) $this->day($this->e1, self::DR)['computed_late_minutes']);
        $this->assertSame(60, (int) $this->day($this->e1, self::DR)['computed_early_minutes']);
    }

    #[TestDox('S-007.8 a closed day on a locked date is not recomputed')]
    public function test_s_007_8_locked_day_is_not_recomputed(): void
    {
        $this->askChange($this->e1, [self::DX]);
        $this->profileAssignment($this->e1);
        $this->lock(self::DX);
        $before = $this->day($this->e1, self::DX);

        $this->page();

        $this->assertSame('pending', $this->row($this->e1, self::DX)['status']);
        $this->assertSame($before, $this->day($this->e1, self::DX));
    }
}
