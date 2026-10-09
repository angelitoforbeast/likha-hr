<?php

namespace Tests\Feature\Import;

use App\Jobs\ParseZktecoImport;
use App\Models\AttendanceImportRun;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Shared fixtures for the import cases of slice 001: four invented employees,
 * three dates, and helpers that run the real import job against a small file.
 */
abstract class ImportTestCase extends TestCase
{
    use RefreshDatabase;

    protected const D1 = '2026-10-05';
    protected const D2 = '2026-10-06';
    protected const D3 = '2026-10-07';

    protected const FULL_DAY = ['08:00:00', '12:00:00', '13:00:00', '17:00:00'];

    protected User $uploader;
    protected Shift $shift;
    protected Employee $a;
    protected Employee $b;
    protected Employee $c;
    protected Employee $d;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->uploader = User::factory()->create(['role' => 'ceo']);

        $this->shift = Shift::create([
            'name'                  => 'Day shift',
            'start_time'            => '08:00:00',
            'end_time'              => '17:00:00',
            'lunch_start'           => '12:00:00',
            'lunch_end'             => '13:00:00',
            'required_work_minutes' => 480,
        ]);

        $this->a = $this->employee('1', 'Employee A', 'active');
        $this->b = $this->employee('2', 'Employee B', 'active');
        $this->c = $this->employee('3', 'Employee C', 'inactive');
        $this->d = $this->employee('4', 'Employee D', 'active');
    }

    protected function employee(string $zktecoId, string $name, string $status): Employee
    {
        return Employee::create([
            'zkteco_id'        => $zktecoId,
            'full_name'        => $name,
            'status'           => $status,
            'default_shift_id' => $this->shift->id,
        ]);
    }

    /** One attlog line in the device's tab-separated layout. */
    protected function line(string $zktecoId, string $date, string $time): string
    {
        return "{$zktecoId}\t{$date} {$time}\t0\t1\t\t0\t0";
    }

    /** The four lines of a full day. */
    protected function fullDay(string $zktecoId, string $date): array
    {
        return array_map(fn ($time) => $this->line($zktecoId, $date, $time), self::FULL_DAY);
    }

    /**
     * A queued run with the given attlog lines in its folder. Pass null for
     * $lines to leave the folder without an attlog.dat.
     */
    protected function makeRun(?array $lines, ?array $stats = null): AttendanceImportRun
    {
        $run = AttendanceImportRun::create([
            'uploaded_by' => $this->uploader->id,
            'status'      => 'queued',
            'stats_json'  => $stats,
        ]);

        if ($lines !== null) {
            Storage::disk('local')->put("imports/{$run->id}/attlog.dat", implode("\r\n", $lines) . "\r\n");
        }

        return $run;
    }

    /** Run the import job for the run, as the queue worker would, and reload it. */
    protected function process(AttendanceImportRun $run): AttendanceImportRun
    {
        ParseZktecoImport::dispatchSync($run->id);

        return $run->fresh();
    }

    /** A run that finished earlier, used as the source of stored punches and day rows. */
    protected function earlierRun(): AttendanceImportRun
    {
        return AttendanceImportRun::create([
            'uploaded_by' => $this->uploader->id,
            'status'      => 'done',
        ]);
    }

    protected function storedPunch(Employee $employee, string $date, string $time, AttendanceImportRun $run): void
    {
        DB::table('attendance_logs')->insert([
            'employee_id'   => $employee->id,
            'punched_at'    => "{$date} {$time}",
            'source_run_id' => $run->id,
            'created_at'    => '2026-10-01 09:00:00',
            'updated_at'    => '2026-10-01 09:00:00',
        ]);
    }

    /**
     * An existing day row. Inserted raw with a date-only work_date, the way
     * the production DATE column holds it, so the existing lookup by
     * 'Y-m-d' finds it on sqlite as well.
     */
    protected function dayRow(Employee $employee, string $date, ?AttendanceImportRun $run = null, array $values = []): int
    {
        return DB::table('attendance_days')->insertGetId(array_merge([
            'employee_id'           => $employee->id,
            'work_date'             => $date,
            'shift_id'              => $this->shift->id,
            'time_in'               => "{$date} 08:10:00",
            'lunch_out'             => "{$date} 12:05:00",
            'lunch_in'              => "{$date} 12:55:00",
            'time_out'              => "{$date} 17:20:00",
            'computed_work_minutes' => 111,
            'payable_work_minutes'  => 111,
            'notes'                 => 'kept as it was',
            'source_run_id'         => $run?->id,
            'created_at'            => '2026-10-01 09:00:00',
            'updated_at'            => '2026-10-01 09:00:00',
        ], $values));
    }

    protected function override(int $dayId, Employee $employee, string $date, string $field, string $newValue): int
    {
        return DB::table('attendance_overrides')->insertGetId([
            'attendance_day_id' => $dayId,
            'employee_id'       => $employee->id,
            'work_date'         => $date,
            'field'             => $field,
            'old_value'         => null,
            'new_value'         => $newValue,
            'reason'            => 'corrected by hand',
            'updated_by'        => $this->uploader->id,
            'created_at'        => '2026-10-01 09:00:00',
            'updated_at'        => '2026-10-01 09:00:00',
        ]);
    }

    protected function lock(string $date): void
    {
        DB::table('attendance_date_locks')->insert([
            'lock_date'  => $date,
            'locked_by'  => $this->uploader->id,
            'reason'     => 'closed',
            'locked_at'  => '2026-10-01 09:00:00',
            'created_at' => '2026-10-01 09:00:00',
            'updated_at' => '2026-10-01 09:00:00',
        ]);
    }

    /** The day row of an employee on a date as a plain array, or null. */
    protected function day(Employee $employee, string $date): ?array
    {
        $row = DB::table('attendance_days')
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $date)
            ->first();

        return $row ? (array) $row : null;
    }

    /** Every day row as "employee id/date", sorted, for exact comparisons. */
    protected function dayKeys(): array
    {
        return DB::table('attendance_days')->get()
            ->map(fn ($row) => $row->employee_id . '/' . substr($row->work_date, 0, 10))
            ->sort()->values()->all();
    }

    protected function key(Employee $employee, string $date): string
    {
        return $employee->id . '/' . $date;
    }

    protected function punchCount(Employee $employee, string $date): int
    {
        return DB::table('attendance_logs')
            ->where('employee_id', $employee->id)
            ->whereDate('punched_at', $date)
            ->count();
    }

    /** The compute summary stored on the run, or null when there is none. */
    protected function summary(AttendanceImportRun $run): ?array
    {
        $stats = $run->stats_json;

        return is_array($stats) ? ($stats['compute'] ?? null) : null;
    }

    protected function assertSummary(int $computed, int $lockedSkipped, int $errors, AttendanceImportRun $run): void
    {
        $this->assertSame(
            ['days_computed' => $computed, 'locked_skipped' => $lockedSkipped, 'errors' => $errors],
            $this->summary($run)
        );
    }
}
