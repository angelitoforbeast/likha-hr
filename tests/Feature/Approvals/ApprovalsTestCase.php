<?php

namespace Tests\Feature\Approvals;

use App\Models\Employee;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\Shift;
use App\Models\User;
use App\Services\AttendanceComputeService;
use App\Services\PayrollService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Shared fixtures for the cases of slice 002: one user per role, three invented
 * employees, one shift, a pay period with a draft payroll run, and three dates.
 */
abstract class ApprovalsTestCase extends TestCase
{
    use RefreshDatabase;

    /** Dr: a weekday on which E1 has a day row from punches. */
    protected const DR = '2026-10-05';
    /** Dn: a weekday on which E1 has no day row, no rest day and no SIL. */
    protected const DN = '2026-10-06';
    /** Dl: a locked date. */
    protected const DL = '2026-10-07';
    /** Another open weekday, for cases that need a second date. */
    protected const DX = '2026-10-08';

    protected const PERIOD_START = '2026-10-01';
    protected const PERIOD_END   = '2026-10-15';

    protected const FOUR_TIMES = [
        'time_in'   => '08:00',
        'lunch_out' => '12:00',
        'lunch_in'  => '13:00',
        'time_out'  => '17:00',
    ];

    protected const REASON = 'Nakalimutan mag-punch';

    protected User $c;
    protected User $h;
    protected User $h2;
    protected User $m;
    protected Shift $shift;
    protected Shift $otherShift;
    protected Employee $e1;
    protected Employee $e2;
    protected Employee $e3;
    protected PayrollRun $run;
    protected ?int $importRunId = null;

    protected function setUp(): void
    {
        parent::setUp();

        // PayrollService writes payroll_items.holiday_earnings_detail, a column no
        // migration creates (the live database has it). Added here for the tests only,
        // so payroll can be computed on a database built from the migrations.
        if (!Schema::hasColumn('payroll_items', 'holiday_earnings_detail')) {
            Schema::table('payroll_items', fn ($table) => $table->json('holiday_earnings_detail')->nullable());
        }

        $this->datesAsInProduction();

        $this->c  = User::factory()->create(['role' => 'ceo', 'name' => 'Ceo Tester']);
        $this->h  = User::factory()->create(['role' => 'hr_staff', 'name' => 'Hr One']);
        $this->h2 = User::factory()->create(['role' => 'hr_staff', 'name' => 'Hr Two']);
        $this->m  = User::factory()->create(['role' => 'admin', 'name' => 'Admin Tester']);

        $this->shift = Shift::create([
            'name'                  => 'Day shift',
            'start_time'            => '08:00:00',
            'end_time'              => '17:00:00',
            'lunch_start'           => '12:00:00',
            'lunch_end'             => '13:00:00',
            'required_work_minutes' => 480,
        ]);
        $this->otherShift = Shift::create([
            'name'                  => 'Late shift',
            'start_time'            => '09:00:00',
            'end_time'              => '18:00:00',
            'lunch_start'           => '13:00:00',
            'lunch_end'             => '14:00:00',
            'required_work_minutes' => 480,
        ]);

        $this->e1 = $this->employee('901', 'Employee One', 'active');
        $this->e2 = $this->employee('902', 'Employee Two', 'active');
        $this->e3 = $this->employee('903', 'Employee Three', 'inactive');

        $this->punchedDay($this->e1, self::DR, ['08:03:00', '12:00:00', '13:00:00', '17:00:00']);
        $this->lock(self::DL);

        $this->run = PayrollRun::create([
            'cutoff_start' => self::PERIOD_START,
            'cutoff_end'   => self::PERIOD_END,
            'created_by'   => $this->c->id,
            'status'       => 'draft',
        ]);
    }

    protected function employee(string $zktecoId, string $name, string $status): Employee
    {
        $employee = Employee::create([
            'zkteco_id'        => $zktecoId,
            'full_name'        => $name,
            'status'           => $status,
            'default_shift_id' => $this->shift->id,
        ]);
        DB::table('employee_rates')->insert([
            'employee_id'    => $employee->id,
            'daily_rate'     => 520.00,
            'effective_date' => '2026-01-01',
            'created_at'     => '2026-01-01 09:00:00',
            'updated_at'     => '2026-01-01 09:00:00',
        ]);

        return $employee;
    }

    /** Stores punches and lets the real compute build the day row from them. */
    protected function punchedDay(Employee $employee, string $date, array $times): void
    {
        foreach ($times as $time) {
            DB::table('attendance_logs')->insert([
                'employee_id'   => $employee->id,
                'punched_at'    => "{$date} {$time}",
                'source_run_id' => $this->importRunId(),
                'created_at'  => '2026-10-01 09:00:00',
                'updated_at'  => '2026-10-01 09:00:00',
            ]);
        }
        $stats = ['processed' => 0, 'skipped' => 0, 'errors' => 0];
        app(AttendanceComputeService::class)->computeForEmployee(
            $employee, Carbon::parse($date)->startOfDay(), Carbon::parse($date)->endOfDay(), null, $stats, false
        );
        DB::table('attendance_days')->where('employee_id', $employee->id)->whereDate('work_date', $date)
            ->update(['created_at' => '2026-10-01 09:00:00', 'updated_at' => '2026-10-01 09:00:00']);
    }

    protected function importRunId(): int
    {
        return $this->importRunId ??= (int) DB::table('attendance_import_runs')->insertGetId([
            'uploaded_by' => $this->c->id,
            'status'      => 'done',
            'created_at'  => '2026-10-01 09:00:00',
            'updated_at'  => '2026-10-01 09:00:00',
        ]);
    }

    /**
     * A MySQL DATE column keeps the date and drops the time; a sqlite column keeps
     * whatever text it is given, so a date written by the models ("2026-10-05 00:00:00")
     * is not found again by the existing lookups that compare with "2026-10-05".
     * These triggers make the test database keep date columns the way production
     * does. They change no product code and exist only in this test database.
     */
    protected function datesAsInProduction(): void
    {
        $columns = [
            'attendance_days'            => ['work_date'],
            'attendance_overrides'       => ['work_date'],
            'day_offs'                   => ['off_date'],
            'day_off_logs'               => ['off_date'],
            'sil_applications'           => ['sil_date'],
            'employee_shift_assignments' => ['effective_date', 'effective_until'],
            'attendance_date_locks'      => ['lock_date'],
        ];
        foreach ($columns as $table => $dateColumns) {
            $set = implode(', ', array_map(fn ($c) => "{$c} = substr({$c}, 1, 10)", $dateColumns));
            $long = implode(' OR ', array_map(fn ($c) => "length(NEW.{$c}) > 10", $dateColumns));
            foreach (['INSERT', 'UPDATE'] as $event) {
                DB::statement(
                    "CREATE TRIGGER IF NOT EXISTS date_only_{$table}_" . strtolower($event)
                    . " AFTER {$event} ON {$table} WHEN {$long}"
                    . " BEGIN UPDATE {$table} SET {$set} WHERE id = NEW.id; END"
                );
            }
        }
    }

    protected function lock(string $date): void
    {
        DB::table('attendance_date_locks')->insert([
            'lock_date'  => $date,
            'locked_by'  => $this->c->id,
            'reason'     => 'closed',
            'source'     => 'manual',
            'locked_at'  => '2026-10-01 09:00:00',
            'created_at' => '2026-10-01 09:00:00',
            'updated_at' => '2026-10-01 09:00:00',
        ]);
    }

    protected function unlock(string $date): void
    {
        DB::table('attendance_date_locks')->whereDate('lock_date', $date)->delete();
    }

    /* ── Requests as a role ── */

    protected function saveAll(User $user, Employee $employee, string $date, array $fields, ?string $reason = self::REASON): TestResponse
    {
        return $this->actingAs($user)->postJson('/attendance-calendar/save-all', array_merge([
            'employee_id' => $employee->id,
            'date'        => $date,
            'reason'      => $reason,
        ], $fields));
    }

    /** H asks for the four times on E1/Dn: the request most cases start from. */
    protected function askFourTimes(?User $user = null, ?Employee $employee = null, string $date = self::DN): TestResponse
    {
        return $this->saveAll($user ?? $this->h, $employee ?? $this->e1, $date, self::FOUR_TIMES);
    }

    protected function toggleDayOff(User $user, Employee $employee, string $date, string $action, ?string $reason = 'Palit ng araw ng pahinga'): TestResponse
    {
        return $this->actingAs($user)->postJson('/attendance-calendar/toggle-day-off', [
            'employee_id' => $employee->id, 'date' => $date, 'action' => $action, 'reason' => $reason,
        ]);
    }

    protected function applySil(User $user, Employee $employee, string $date, ?string $reason = 'Bakasyon na may bayad'): TestResponse
    {
        return $this->actingAs($user)->postJson('/attendance-calendar/apply-sil', [
            'employee_id' => $employee->id, 'date' => $date, 'reason' => $reason,
        ]);
    }

    protected function removeSil(User $user, Employee $employee, string $date, ?string $reason = 'Pumasok pala sa araw na ito'): TestResponse
    {
        return $this->actingAs($user)->postJson('/attendance-calendar/remove-sil', array_filter([
            'employee_id' => $employee->id, 'date' => $date, 'reason' => $reason,
        ], fn ($v) => $v !== null));
    }

    protected function assignShift(User $user, Employee $employee, string $date, Shift $shift): TestResponse
    {
        return $this->actingAs($user)->postJson('/attendance-calendar/assign-shift', [
            'employee_id' => $employee->id, 'date' => $date, 'shift_id' => $shift->id,
        ]);
    }

    /** Makes an employee SIL-eligible with a balance for 2026. */
    protected function silSetup(Employee $employee, float $totalDays = 5): void
    {
        DB::table('employees')->where('id', $employee->id)->update(['sil_eligible' => true]);
        DB::table('employee_sil_balances')->updateOrInsert(
            ['employee_id' => $employee->id, 'year' => 2026],
            ['total_days' => $totalDays, 'created_at' => '2026-01-01 09:00:00', 'updated_at' => '2026-01-01 09:00:00']
        );
    }

    protected function silRemaining(Employee $employee): float
    {
        return (float) Employee::find($employee->id)->getSilRemainingDays(2026);
    }

    /**
     * The rows of an employee in a table without what differs between two employees
     * (ids, timestamps), to compare an approved change with the same change entered directly.
     */
    protected function comparable(string $table, Employee $employee, string $dateColumn = 'work_date'): array
    {
        return DB::table($table)->where('employee_id', $employee->id)->orderBy('id')->get()->map(function ($row) use ($dateColumn) {
            $row = (array) $row;
            unset($row['id'], $row['employee_id'], $row['created_at'], $row['updated_at'], $row['attendance_day_id'], $row['source_run_id']);
            foreach ($row as $column => $value) {
                // Dates are compared as dates: the same day whatever text form the driver kept.
                if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2} 00:00:00$/', $value)) {
                    $row[$column] = substr($value, 0, 10);
                }
            }

            return $row;
        })->all();
    }

    /** A payroll item without the employee it belongs to, to compare two employees. */
    protected function payrollOf(Employee $employee): array
    {
        $row = $this->payroll($employee);
        $this->assertNotNull($row, 'no payroll item for employee ' . $employee->id);
        unset($row['employee_id']);

        return $row;
    }

    /* ── Reads ── */

    /** The day row of an employee on a date as a plain array, or null. */
    protected function day(Employee $employee, string $date): ?array
    {
        $row = DB::table('attendance_days')->where('employee_id', $employee->id)->whereDate('work_date', $date)->first();

        return $row ? (array) $row : null;
    }

    protected function dayCount(?Employee $employee = null): int
    {
        return DB::table('attendance_days')->when($employee, fn ($q) => $q->where('employee_id', $employee->id))->count();
    }

    /** Pending requests of an employee on a date, keyed by field. */
    protected function pending(Employee $employee, string $date): array
    {
        return DB::table('attendance_change_requests')
            ->where('employee_id', $employee->id)->where('work_date', $date)->where('status', 'pending')
            ->orderBy('id')->get()->keyBy('field')->map(fn ($r) => (array) $r)->all();
    }

    protected function requestCount(?string $status = null): int
    {
        return DB::table('attendance_change_requests')->when($status, fn ($q) => $q->where('status', $status))->count();
    }

    /** Everything official, to prove an action wrote nothing. */
    protected function official(): array
    {
        $tables = ['attendance_days', 'attendance_overrides', 'day_offs', 'day_off_logs', 'sil_applications', 'employee_shift_assignments'];
        $out = [];
        foreach ($tables as $table) {
            $out[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        }

        return $out;
    }

    /** What PayrollService computes for an employee in the period, without ids and timestamps. */
    protected function payroll(?Employee $employee = null): ?array
    {
        $employee ??= $this->e1;
        app(PayrollService::class)->computePayroll($this->run);
        $item = PayrollItem::where('payroll_run_id', $this->run->id)->where('employee_id', $employee->id)->first();
        if (!$item) {
            return null;
        }
        $row = $item->getAttributes();
        unset($row['id'], $row['created_at'], $row['updated_at']);

        return $row;
    }

    /** The "what the CEO saw" value of an entry: its pending rows as id:version. */
    protected function seen(Employee $employee, string $date): string
    {
        return DB::table('attendance_change_requests')
            ->where('employee_id', $employee->id)->where('work_date', $date)->where('status', 'pending')
            ->orderBy('id')->get()->map(fn ($r) => $r->id . ':' . $r->version)->implode(',');
    }

    protected function anchorId(Employee $employee, string $date): int
    {
        return (int) DB::table('attendance_change_requests')
            ->where('employee_id', $employee->id)->where('work_date', $date)
            // A pending row when there is one; otherwise the oldest row of that day.
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")->orderBy('id')->value('id');
    }

    protected function approve(User $user, Employee $employee, string $date, array $extra = []): TestResponse
    {
        return $this->actingAs($user)->postJson('/approvals/' . $this->anchorId($employee, $date) . '/approve', array_merge([
            'seen' => $this->seen($employee, $date),
        ], $extra));
    }

    /* ── Pages ── */

    /** The calendar of the pay period as HTML, for a signed-in user. */
    protected function calendar(User $user): string
    {
        return $this->actingAs($user)
            ->get('/attendance-calendar?date_from=' . self::PERIOD_START . '&date_to=' . self::PERIOD_END)
            ->assertStatus(200)
            ->getContent();
    }

    /** The opening tag of one calendar cell, with its attributes decoded. */
    protected function cell(string $html, Employee $employee, string $date): string
    {
        $pattern = '/<td class="[^"]*"\s+data-date="' . $date . '"[^>]*data-employee-id="' . $employee->id . '"[^>]*>/s';
        $this->assertSame(1, preg_match($pattern, $html, $m), "no calendar cell for employee {$employee->id} on {$date}");

        return html_entity_decode($m[0], ENT_QUOTES);
    }

    /** The present, absent and undertime counts at the end of an employee's calendar row. */
    protected function rowSummary(string $html, Employee $employee): array
    {
        $start = strpos($html, 'data-employee-id="' . $employee->id . '"');
        $this->assertNotFalse($start, "employee {$employee->id} is not on the calendar");
        $row = substr($html, $start, strpos($html, '</tr>', $start) - $start);
        preg_match_all('/<td class="fw-bold[^>]*>(\d+)<\/td>/', $row, $m);

        return array_map('intval', $m[1]);
    }

    protected function reject(User $user, Employee $employee, string $date, ?string $reason = 'Walang katibayan', array $extra = []): TestResponse
    {
        return $this->actingAs($user)->postJson('/approvals/' . $this->anchorId($employee, $date) . '/reject', array_merge([
            'seen'   => $this->seen($employee, $date),
            'reason' => $reason,
        ], $extra));
    }
}
