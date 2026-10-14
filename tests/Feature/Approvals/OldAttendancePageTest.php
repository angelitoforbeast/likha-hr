<?php

namespace Tests\Feature\Approvals;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * S-20 and S-09.5: the older Attendance page follows the same rule as the calendar.
 */
class OldAttendancePageTest extends ApprovalsTestCase
{
    private function override(User $user, array $day, string $field, ?string $value, string $reason = self::REASON): TestResponse
    {
        return $this->actingAs($user)->postJson('/attendance/override', [
            'attendance_day_id' => $day['id'],
            'field'             => $field,
            'new_value'         => $value,
            'reason'            => $reason,
        ]);
    }

    #[TestDox('S-20.1 a time posted by HR staff or an admin to the older page waits as a request')]
    public function test_s_20_1_old_page_time_edit_becomes_a_request(): void
    {
        $dayBefore = $this->day($this->e1, self::DR);

        $this->override($this->h, $dayBefore, 'time_in', '08:00')->assertStatus(200);

        $this->assertSame($dayBefore, $this->day($this->e1, self::DR), 'the day row must stay as it was');
        $this->assertSame(0, DB::table('attendance_overrides')->count());
        $pending = $this->pending($this->e1, self::DR);
        $this->assertSame('08:00:00', $pending['time_in']['new_value']);
        $this->assertEquals($this->h->id, $pending['time_in']['requested_by']);

        $this->override($this->m, $dayBefore, 'time_out', '17:30')->assertStatus(200)->assertJson(['pending' => true]);

        $this->assertSame($dayBefore, $this->day($this->e1, self::DR));
        $this->assertEquals($this->m->id, $this->pending($this->e1, self::DR)['time_out']['requested_by']);
    }

    #[TestDox('S-20.2 a shift posted by HR staff to the older page waits and the shift of the day is unchanged')]
    public function test_s_20_2_old_page_shift_becomes_a_request(): void
    {
        $dayBefore = $this->day($this->e1, self::DR);

        $this->override($this->h, $dayBefore, 'shift_id', (string) $this->otherShift->id)->assertStatus(200)->assertJson(['pending' => true]);

        $this->assertSame((string) $this->otherShift->id, $this->pending($this->e1, self::DR)['shift_id']['new_value']);
        $this->assertSame($dayBefore, $this->day($this->e1, self::DR));
        $this->assertSame(0, DB::table('employee_shift_assignments')->count());
        $this->assertSame(0, DB::table('attendance_overrides')->count());
        $this->assertSame($this->shift->id, Employee::find($this->e1->id)->getShiftForDate(self::DR)->id);
    }

    #[TestDox('S-20.3 the CEO posting to the older page saves at once: a time with its override row, a shift as a single-day assignment')]
    public function test_s_20_3_old_page_ceo_is_direct(): void
    {
        $day = $this->day($this->e1, self::DR);

        $this->override($this->c, $day, 'time_in', '08:00')->assertStatus(200)->assertJsonMissing(['pending' => true]);

        $after = $this->day($this->e1, self::DR);
        $this->assertSame(self::DR . ' 08:00:00', $after['time_in']);
        $this->assertSame(0, (int) $after['computed_late_minutes'], 'recomputed: no longer three minutes late');
        $override = DB::table('attendance_overrides')->first();
        $this->assertSame('time_in', $override->field);
        $this->assertSame('08:03:00', $override->old_value);
        $this->assertSame('08:00:00', $override->new_value);
        $this->assertEquals($this->c->id, $override->updated_by);

        $this->override($this->c, $day, 'shift_id', (string) $this->otherShift->id)->assertStatus(200);

        $assignment = DB::table('employee_shift_assignments')->first();
        $this->assertEquals($this->otherShift->id, $assignment->shift_id);
        $this->assertSame(self::DR, substr($assignment->effective_date, 0, 10));
        $this->assertSame(self::DR, substr($assignment->effective_until, 0, 10));
        $this->assertEquals($this->otherShift->id, $this->day($this->e1, self::DR)['shift_id']);
        $this->assertSame($this->otherShift->id, Employee::find($this->e1->id)->getShiftForDate(self::DR)->id);
        $this->assertSame(0, $this->requestCount());
    }

    #[TestDox('S-20.4 on a locked date the older page refuses everyone, the CEO included')]
    public function test_s_20_4_old_page_checks_the_lock(): void
    {
        $lockedId = DB::table('attendance_days')->insertGetId([
            'employee_id' => $this->e1->id, 'work_date' => self::DL, 'shift_id' => $this->shift->id,
            'time_in' => self::DL . ' 08:10:00',
            'created_at' => '2026-10-01 09:00:00', 'updated_at' => '2026-10-01 09:00:00',
        ]);
        $officialBefore = $this->official();

        foreach ([$this->c, $this->h, $this->m] as $user) {
            $this->override($user, ['id' => $lockedId], 'time_in', '08:00')->assertStatus(423);
            $this->override($user, ['id' => $lockedId], 'shift_id', (string) $this->otherShift->id)->assertStatus(423);
            $this->actingAs($user)->postJson('/attendance/create-day', ['employee_id' => $this->e2->id, 'work_date' => self::DL])->assertStatus(423);
        }

        $this->assertSame($officialBefore, $this->official());
        $this->assertSame(0, $this->requestCount());
    }

    #[TestDox('S-20.5 the older "create day" by HR staff creates no day row and no blank request')]
    public function test_s_20_5_old_create_day_by_hr_staff(): void
    {
        $response = $this->actingAs($this->h)->postJson('/attendance/create-day', ['employee_id' => $this->e1->id, 'work_date' => self::DN]);

        $response->assertStatus(200)->assertJson(['success' => true, 'pending' => true, 'attendance_day_id' => null]);
        $this->assertNull($this->day($this->e1, self::DN));
        $this->assertSame(0, $this->requestCount());

        // The time then goes in with the employee and the date, and waits.
        $this->actingAs($this->h)->postJson('/attendance/override', [
            'employee_id' => $this->e1->id, 'work_date' => self::DN, 'field' => 'time_in', 'new_value' => '08:00', 'reason' => self::REASON,
        ])->assertStatus(200)->assertJson(['pending' => true]);
        $this->assertNull($this->day($this->e1, self::DN));
        $this->assertSame(1, $this->requestCount('pending'));

        // The CEO still gets a day row from it.
        $this->actingAs($this->c)->postJson('/attendance/create-day', ['employee_id' => $this->e2->id, 'work_date' => self::DN])
            ->assertStatus(200)->assertJsonMissing(['pending' => true]);
        $this->assertNotNull($this->day($this->e2, self::DN));
    }

    #[TestDox('S-20.6 an empty value posted by HR staff to the older page is a "clear" request')]
    public function test_s_20_6_old_page_clear(): void
    {
        $dayBefore = $this->day($this->e1, self::DR);

        $this->override($this->h, $dayBefore, 'time_out', '')->assertStatus(200)->assertJson(['pending' => true]);

        $row = $this->pending($this->e1, self::DR)['time_out'];
        $this->assertNull($row['new_value']);
        $this->assertSame('17:00:00', $row['old_value']);
        $this->assertSame($dayBefore, $this->day($this->e1, self::DR));
        $this->actingAs($this->c)->get('/approvals')->assertSee('cleared');
    }

    #[TestDox('S-20.7 the same change asked through the calendar and through the older page gives the same request')]
    public function test_s_20_7_same_request_from_both_pages(): void
    {
        $this->punchedDay($this->e2, self::DR, ['08:03:00', '12:00:00', '13:00:00', '17:00:00']);

        $this->saveAll($this->h, $this->e1, self::DR, ['time_in' => '08:00', 'shift_id' => $this->otherShift->id]);
        $day = $this->day($this->e2, self::DR);
        $this->override($this->h, $day, 'time_in', '08:00');
        $this->override($this->h, $day, 'shift_id', (string) $this->otherShift->id);

        $shape = fn (Employee $employee) => array_map(
            fn ($row) => [$row['field'], $row['new_value'], $row['old_value'], $row['status'], $row['reason'], $row['requested_by']],
            array_values($this->pending($employee, self::DR))
        );
        $this->assertSame($shape($this->e1), $shape($this->e2));

        // And the same official result when approved.
        $this->approve($this->c, $this->e1, self::DR)->assertStatus(200);
        $this->approve($this->c, $this->e2, self::DR)->assertStatus(200);
        foreach (['time_in', 'shift_id', 'computed_late_minutes', 'computed_work_minutes'] as $column) {
            $this->assertSame($this->day($this->e1, self::DR)[$column], $this->day($this->e2, self::DR)[$column], $column);
        }
        $assignment = fn (Employee $employee) => array_map(
            fn ($row) => [$row['shift_id'], $row['effective_date'], $row['effective_until']],
            $this->comparable('employee_shift_assignments', $employee)
        );
        $this->assertSame($assignment($this->e1), $assignment($this->e2));
        $this->assertCount(1, $assignment($this->e2));
    }

    #[TestDox('S-20.8 "Compute" on a range with pending requests neither applies nor deletes them and keeps overrides')]
    public function test_s_20_8_compute_leaves_requests_and_overrides(): void
    {
        $this->saveAll($this->c, $this->e1, self::DR, ['time_in' => '08:10'], 'Itinama ng CEO');
        $this->saveAll($this->h, $this->e1, self::DR, ['time_out' => '18:00']);
        $this->askFourTimes();
        $pendingBefore = [$this->pending($this->e1, self::DR), $this->pending($this->e1, self::DN)];

        $this->actingAs($this->h)->post('/attendance/compute', ['start_date' => self::PERIOD_START, 'end_date' => self::PERIOD_END])->assertStatus(302);

        $this->assertSame($pendingBefore, [$this->pending($this->e1, self::DR), $this->pending($this->e1, self::DN)]);
        $day = $this->day($this->e1, self::DR);
        $this->assertSame(self::DR . ' 08:10:00', $day['time_in'], 'the override is kept');
        $this->assertSame(self::DR . ' 17:00:00', $day['time_out'], 'the requested time is not applied');
        $this->assertNull($this->day($this->e1, self::DN));
        $this->assertSame(1, DB::table('attendance_overrides')->count());
    }

    #[TestDox('S-09.5 the older page marks the pending rows and its CSV and print hold official values only')]
    public function test_s_09_5_old_page_marks_pending_rows(): void
    {
        // The print view carries the minute it was generated: the clock stands still for the comparison.
        $this->freezeTime();
        $range = '?start_date=' . self::DR . '&end_date=' . self::DN . '&employee_id=' . $this->e1->id;
        $csvBefore = $this->actingAs($this->h)->get('/attendance/export-csv' . $range)->streamedContent();
        $printBefore = $this->actingAs($this->h)->get('/attendance/print' . $range)->getContent();

        $this->saveAll($this->h, $this->e1, self::DN, ['time_in' => '07:45', 'time_out' => '16:45']);
        $this->saveAll($this->h, $this->e1, self::DR, ['time_in' => '07:45']);

        $html = $this->actingAs($this->h)->get('/attendance' . $range)->assertStatus(200)->getContent();
        $this->assertSame(2, substr_count($html, 'class="badge bg-warning text-dark pending-badge"'), 'one marker per pending row');
        $this->assertStringContainsString('Pending approval', $html);
        $this->assertStringContainsString('Absent', $html, 'the requested day is still listed as absent');
        $this->assertStringContainsString('08:03', $html, 'the official time of the worked day stays');
        $this->assertStringNotContainsString('07:45', $html);

        // Another employee's rows are not marked.
        $other = $this->actingAs($this->h)->get('/attendance?start_date=' . self::DR . '&end_date=' . self::DN . '&employee_id=' . $this->e2->id)->getContent();
        $this->assertStringNotContainsString('pending-badge"', $other);

        $csv = $this->actingAs($this->h)->get('/attendance/export-csv' . $range)->streamedContent();
        $this->assertSame($csvBefore, $csv);
        $this->assertStringContainsString('08:03', $csv);
        $this->assertStringNotContainsString('07:45', $csv);
        $this->assertSame($printBefore, $this->actingAs($this->h)->get('/attendance/print' . $range)->getContent());
    }
}
