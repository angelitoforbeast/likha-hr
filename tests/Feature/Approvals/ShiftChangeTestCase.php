<?php

namespace Tests\Feature\Approvals;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Spec 007: fixtures and helpers for the cases on shift changes: E1 and E2 work the same
 * days, a change is asked in one minute, and the Approvals page is read as the CEO.
 */
abstract class ShiftChangeTestCase extends ApprovalsTestCase
{
    protected const D9  = '2026-10-09';
    protected const D12 = '2026-10-12';
    protected const D13 = '2026-10-13';

    /** The minute the requests of a change are asked in. */
    protected const ASKED_AT = '2026-10-26 16:44:05';

    protected function setUp(): void
    {
        parent::setUp();
        // E2 works the same days as E1, so one can be approved as a group and the other day by day.
        $this->punchedDay($this->e2, self::DR, ['08:03:00', '12:00:00', '13:00:00', '17:00:00']);
        foreach ([$this->e1, $this->e2] as $employee) {
            $this->punchedDay($employee, self::DX, ['08:10:00', '12:00:00', '13:00:00', '16:30:00']);
            $this->punchedDay($employee, self::D9, ['07:55:00', '12:00:00', '13:00:00', '17:05:00']);
        }
    }

    /* ── Helpers ── */

    protected function askShift(User $user, Employee $employee, string $date, ?int $shiftId = null): TestResponse
    {
        return $this->actingAs($user)->postJson('/attendance-calendar/assign-shift', [
            'employee_id' => $employee->id, 'date' => $date,
            'shift_id' => $shiftId ?? $this->otherShift->id, 'reason' => 'Lumipat ng shift',
        ])->assertStatus(200)->assertJson(['pending' => true]);
    }

    /** H asks the late shift for an employee on several dates in the same minute. */
    protected function askChange(Employee $employee, array $dates, ?User $user = null, string $at = self::ASKED_AT): void
    {
        $this->travelTo(\Carbon\Carbon::parse($at));
        foreach ($dates as $date) {
            $this->askShift($user ?? $this->h, $employee, $date);
        }
        $this->travelBack();
    }

    protected function page(string $query = ''): string
    {
        return $this->actingAs($this->c)->get('/approvals' . $query)->assertStatus(200)->getContent();
    }

    /** The group keys on the page, in the order shown. */
    protected function groupKeys(string $html): array
    {
        preg_match_all('/class="approval-group card[^"]*"[^>]*data-group-key="([^"]+)"/', $html, $m);

        return array_map(fn ($k) => html_entity_decode($k, ENT_QUOTES), $m[1]);
    }

    /** The HTML of the one group card of an employee. */
    protected function groupCard(string $html, Employee $employee): string
    {
        $start = strpos($html, 'data-group-employee="' . $employee->id . '"');
        $this->assertNotFalse($start, "no group card for employee {$employee->id}");
        $end = strpos($html, '<!-- /approval-group -->', $start);

        return substr($html, $start, $end - $start);
    }

    protected function entriesOf(Employee $employee, array $dates): array
    {
        return array_map(fn ($date) => ['id' => $this->anchorId($employee, $date), 'seen' => $this->seen($employee, $date)], $dates);
    }

    protected function groupPost(string $verb, string $key, array $entries, array $extra = []): TestResponse
    {
        return $this->actingAs($this->c)->postJson('/approvals/shift-group/' . $verb, array_merge([
            'key' => $key, 'entries' => $entries,
        ], $extra));
    }

    protected function shiftStatus(Employee $employee, string $date): ?string
    {
        return DB::table('attendance_change_requests')->where('employee_id', $employee->id)
            ->where('work_date', $date)->where('field', 'shift_id')->orderByDesc('id')->value('status');
    }

    protected function makeShift(string $name): \App\Models\Shift
    {
        return \App\Models\Shift::create([
            'name' => $name, 'start_time' => '22:00:00', 'end_time' => '07:00:00',
            'lunch_start' => '02:00:00', 'lunch_end' => '03:00:00', 'required_work_minutes' => 480,
        ]);
    }
}
