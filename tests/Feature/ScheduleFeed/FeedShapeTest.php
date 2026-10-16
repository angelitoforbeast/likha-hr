<?php

namespace Tests\Feature\ScheduleFeed;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** The exact keys the feed sends, that nothing private leaves, and its query plan. */
class FeedShapeTest extends ScheduleFeedTestCase
{
    private const FORBIDDEN = [
        'time_in', 'time_out', 'lunch_in', 'lunch_out', 'punched_at', 'punches', 'attendance', 'status',
        'payable_work_minutes', 'computed_work_minutes', 'computed_late_minutes', 'overtime', 'approved_overtime_minutes',
        'pay', 'rate', 'daily_rate', 'deductions', 'cash_advance', 'benefits', 'sss', 'philhealth', 'pagibig', 'tin',
        'phone', 'email', 'address', 'photo', 'notes', 'remarks', 'zkteco_id', 'full_name', 'actual_name',
        'night_differential_eligible', 'sil_eligible', 'schedule_mode', 'required_work_minutes', 'grace_out_minutes',
    ];

    public function test_exact_keys_and_no_forbidden_field(): void
    {
        $hr = User::factory()->create(['role' => 'hr_staff']);
        $on = $this->employee('9001', 'Employee Shift');
        $rest = $this->employee('9002', 'Employee Rest');
        $off = $this->employee('9003', 'Employee Off');
        $leave = $this->employee('9004', 'Employee Leave');
        $holiday = $this->employee('9005', 'Employee Holiday', ['department_id' => null]);
        $gone = $this->employee('9006', 'Employee Gone', ['status' => 'inactive']);

        $this->insert('rest_day_patterns', ['employee_id' => $rest->id, 'day_of_week' => 1, 'effective_from' => '2026-01-01']);
        $this->insert('day_offs', ['employee_id' => $off->id, 'off_date' => self::MON, 'type' => 'day_off']);
        $this->insert('sil_applications', ['employee_id' => $leave->id, 'sil_date' => self::MON, 'reason' => 'x', 'applied_by' => $hr->id]);
        $this->insert('holidays', ['date' => '2026-10-06', 'name' => 'Test Holiday', 'type' => 'regular', 'recurring' => false]);

        // Private data the feed must never carry, with values easy to spot in the body.
        DB::table('employee_rates')->insert(['employee_id' => $on->id, 'daily_rate' => 777.77, 'effective_date' => '2026-01-01'] + self::STAMP);
        $run = DB::table('attendance_import_runs')->insertGetId(['uploaded_by' => $hr->id, 'status' => 'done'] + self::STAMP);
        DB::table('attendance_logs')->insert(['employee_id' => $on->id, 'punched_at' => self::MON . ' 07:41:00', 'source_run_id' => $run] + self::STAMP);
        DB::table('attendance_days')->insert([
            'employee_id' => $on->id, 'work_date' => self::MON, 'shift_id' => $this->dayShift->id,
            'time_in' => self::MON . ' 07:41:00', 'time_out' => self::MON . ' 17:13:00',
            'computed_work_minutes' => 433, 'payable_work_minutes' => 433, 'notes' => 'secret day note',
        ] + self::STAMP);

        $response = $this->feed('from=' . self::MON . '&to=2026-10-06')->assertStatus(200);
        $json = $response->json();

        $this->assertSame(['from', 'to', 'generated_at', 'days'], array_keys($json));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+08:00$/', $json['generated_at']);
        foreach ($json['days'] as $day) {
            $this->assertSame(['date', 'employees'], array_keys($day));
        }

        $base = ['employee_id', 'name', 'department', 'date', 'scheduled'];
        $this->assertSame([...$base, 'shift'], array_keys($this->entry($response, $on, self::MON)));
        $this->assertSame(['name', 'start', 'end', 'lunch_start', 'lunch_end', 'grace_in_minutes'],
            array_keys($this->entry($response, $on, self::MON)['shift']));
        $this->assertSame([...$base, 'reason'], array_keys($this->entry($response, $rest, self::MON)));
        $this->assertSame([...$base, 'reason', 'day_off_type'], array_keys($this->entry($response, $off, self::MON)));
        $this->assertSame([...$base, 'reason'], array_keys($this->entry($response, $leave, self::MON)));
        $this->assertSame([...$base, 'reason', 'holiday_name'], array_keys($this->entry($response, $holiday, '2026-10-06')));
        $this->assertNull($this->entry($response, $holiday, '2026-10-06')['department']);
        $this->assertSame([...$base, 'reason'], array_keys($this->entry($response, $gone, self::MON)));

        // Every key anywhere in the body is one of the allowed ones.
        $allowed = ['from', 'to', 'generated_at', 'days', 'date', 'employees', 'employee_id', 'name', 'department',
            'scheduled', 'shift', 'reason', 'day_off_type', 'holiday_name', 'start', 'end', 'lunch_start', 'lunch_end', 'grace_in_minutes'];
        $keys = [];
        $walk = function ($node) use (&$walk, &$keys) {
            if (!is_array($node)) return;
            foreach ($node as $k => $v) { if (is_string($k)) $keys[$k] = true; $walk($v); }
        };
        $walk($json);
        $this->assertSame([], array_values(array_diff(array_keys($keys), $allowed)));
        $this->assertSame([], array_values(array_intersect(array_keys($keys), self::FORBIDDEN)));

        $body = $response->getContent();
        $this->assertStringContainsString('Employee Shift', $body); // the name is meant to be there
        foreach (['777.77', '07:41', '17:13', '433', 'secret day note', '9001'] as $private) {
            $this->assertStringNotContainsString($private, $body);
        }
    }

    public function test_queries_do_not_grow_with_employees_or_days(): void
    {
        $count = function (int $employees, string $to) {
            Employee::query()->delete();
            for ($i = 1; $i <= $employees; $i++) {
                $e = $this->employee((string) (100 + $i), "Employee {$i}");
                $this->insert('rest_day_patterns', ['employee_id' => $e->id, 'day_of_week' => 0, 'effective_from' => '2026-01-01']);
                $this->insert('employee_shift_assignments', ['employee_id' => $e->id, 'shift_id' => $this->lateShift->id, 'effective_date' => '2026-10-08']);
                $this->insert('employee_active_statuses', ['employee_id' => $e->id, 'status' => 'active', 'effective_from' => '2026-01-01']);
                $this->insert('day_offs', ['employee_id' => $e->id, 'off_date' => '2026-10-06', 'type' => 'day_off']);
            }
            $this->insert('holidays', ['date' => '2026-10-07', 'name' => 'Holiday', 'type' => 'regular', 'recurring' => false]);

            $queries = 0;
            DB::listen(function () use (&$queries) { $queries++; });
            $this->feed('from=' . self::MON . '&to=' . $to)->assertStatus(200);
            DB::getEventDispatcher()->forget(\Illuminate\Database\Events\QueryExecuted::class);
            DB::table('holidays')->delete();

            return $queries;
        };

        $thirtyForAWeek = $count(30, self::SUN);
        $oneForADay = $count(1, self::MON);
        $thirtyForAMonth = $count(30, '2026-11-04');

        // Employees, department, default shift, then one query per kind of schedule data.
        $this->assertSame(11, $thirtyForAWeek);
        $this->assertSame($thirtyForAWeek, $thirtyForAMonth);
        $this->assertLessThanOrEqual($thirtyForAWeek, $oneForADay);
    }
}
