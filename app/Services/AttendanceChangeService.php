<?php

namespace App\Services;

use App\Models\AttendanceDateLock;
use App\Models\AttendanceDay;
use App\Models\AttendanceOverride;
use App\Models\DayOff;
use App\Models\DayOffLog;
use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use App\Models\EmployeeSilBalance;
use App\Models\SilApplication;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The one place that makes an attendance change official. The CEO's direct
 * edits and the approval of a request both call these methods, so the rows,
 * the change log, the recompute and the rounding cannot differ between them.
 *
 * Every method is one transaction and starts by locking the employee row:
 * creating a day row, checking a leave balance and writing are then one step
 * per employee, also when an approval and a direct edit arrive together.
 */
class AttendanceChangeService
{
    public const TIME_FIELDS = ['time_in', 'lunch_out', 'lunch_in', 'time_out'];

    public const RECOMPUTE_DAY     = 'day';
    public const RECOMPUTE_PUNCHES = 'punches';

    /**
     * While dryRun() runs: the work a change leaves for after its commit, run by
     * dryRun() itself before it rolls back. Null at every other time.
     *
     * @var array<int, callable>|null
     */
    protected ?array $deferred = null;

    public function __construct(protected AttendanceComputeService $compute)
    {
    }

    /**
     * Run $work exactly as a real change runs, read the result with $read, then undo
     * everything: the outer transaction is always rolled back, so nothing $work writes
     * (day rows, overrides, logs, audit rows) is ever committed. The work a real change
     * does after its commit (the rebuild of autoComputeSingleDay) is run here, inside
     * the transaction and before $read, so what is read is what the commit would leave.
     * Exceptions of $work and $read reach the caller, after the rollback.
     */
    public function dryRun(callable $work, callable $read): mixed
    {
        if ($this->deferred !== null) {
            throw new \LogicException('A dry run cannot run inside another one.');
        }

        $this->guardConnection();
        $level = DB::transactionLevel();
        DB::beginTransaction();
        $this->deferred = [];
        self::$dryRuns++;

        try {
            $work();
            while (!empty($this->deferred)) {
                $next = array_shift($this->deferred);
                $next();
            }

            return $read();
        } finally {
            $this->deferred = null;
            self::$dryRuns--;
            // Back to the level we started at, also when the database already ended an
            // inner transaction (a deadlock) and the framework counted it down.
            try {
                if (DB::transactionLevel() > $level) {
                    DB::rollBack($level);
                }
            } catch (\Throwable $e) {
                // The rollback itself failed. At the top level, drop the connection: the
                // database ends an unfinished transaction when it goes, and the next query
                // starts on a fresh connection that knows of no open transaction.
                if ($level === 0) {
                    DB::purge();
                }
                throw $e;
            }
        }
    }

    /** How many dry runs are open in this process; read by the guard below. */
    protected static int $dryRuns = 0;

    /** @var \WeakMap<\Illuminate\Database\Connection, true>|null connections that carry the guard */
    protected static ?\WeakMap $guarded = null;

    /**
     * While a dry run is open, refuse every statement that would run outside a
     * transaction. On MySQL a deadlock ends the whole transaction at once; if code
     * inside the dry run swallows that error (the rebuild after an edit does, and so
     * does the audit log), the next statements would run in autocommit and be saved
     * for real. With this guard they fail instead, and the preview says it is not
     * available.
     */
    protected function guardConnection(): void
    {
        $connection = DB::connection();
        self::$guarded ??= new \WeakMap();
        if (isset(self::$guarded[$connection])) {
            return;
        }
        self::$guarded[$connection] = true;

        $connection->beforeExecuting(function ($query, $bindings, $connection) {
            if (self::$dryRuns > 0 && !$connection->getPdo()->inTransaction()) {
                throw new \RuntimeException('The dry run lost its transaction; the statement was not run.');
            }
        });
    }

    /** Abort with 423 when the date is locked. Used by every entry point before it writes or asks. */
    public function assertDateNotLocked(string $date): void
    {
        $lock = AttendanceDateLock::forDate($date);
        if ($lock) {
            abort(423, $this->lockMessage($lock));
        }
    }

    public function lockMessage(AttendanceDateLock $lock): string
    {
        return 'This date is locked (by ' . ($lock->locker->name ?? 'CEO') . ' on '
            . $lock->locked_at?->format('M d, Y g:i A') . '). Reason: ' . $lock->reason;
    }

    public function findDay(int $employeeId, string $date): ?AttendanceDay
    {
        // whereDate, because the stored value carries a time part on some database drivers.
        return AttendanceDay::where('employee_id', $employeeId)->whereDate('work_date', $date)->first();
    }

    /** 'H:i' or 'H:i:s' as 'H:i:s'. The caller has validated the shape. */
    public static function normalizeTime(string $time): string
    {
        return Carbon::parse('2000-01-01 ' . $time)->format('H:i:s');
    }

    /**
     * Set or clear time fields of one day and log each of them.
     *
     * @param array<string, array{value: ?string, reason: string}> $fields field => value 'H:i:s'
     *        (null clears the field) and the reason logged for that field
     * @return array{day: AttendanceDay, overrides: array<string, AttendanceOverride>, created: bool}
     */
    public function applyTimes(Employee $employee, string $date, array $fields, User $actor, string $dayNote = 'Auto-created via Save All'): array
    {
        return DB::transaction(function () use ($employee, $date, $fields, $actor, $dayNote) {
            $this->lockEmployee($employee);

            $day = $this->findDay($employee->id, $date);
            $created = false;
            if (!$day) {
                $day = $this->createBlankDay($employee, $date, $dayNote);
                $created = true;
            }

            $overrides = [];
            foreach (self::TIME_FIELDS as $field) {
                if (!array_key_exists($field, $fields)) {
                    continue;
                }
                $newValue = $fields[$field]['value'];
                $oldValue = $day->{$field} ? Carbon::parse($day->{$field})->format('H:i:s') : null;

                $day->{$field} = $newValue !== null
                    ? Carbon::parse($day->work_date->format('Y-m-d') . ' ' . $newValue)
                    : null;

                $overrides[$field] = AttendanceOverride::create([
                    'attendance_day_id' => $day->id,
                    'employee_id'       => $day->employee_id,
                    'work_date'         => $day->work_date,
                    'field'             => $field,
                    'old_value'         => $oldValue,
                    'new_value'         => $newValue,
                    'reason'            => $fields[$field]['reason'],
                    'updated_by'        => $actor->id,
                ]);
            }
            $day->save();

            // Once, after all fields, so the metrics reflect the final state of the day.
            $day->load('shift');
            $this->compute->recomputeDay($day);
            $day->refresh();

            return ['day' => $day, 'overrides' => $overrides, 'created' => $created];
        });
    }

    /**
     * A day row without times, with the shift of that date. Only ever called for a
     * change that is official: a request must never leave such a row, because
     * payroll counts any row on a working date as a worked day.
     */
    public function createBlankDay(Employee $employee, string $date, string $note): AttendanceDay
    {
        $shift = $employee->getShiftForDate($date);

        try {
            // A savepoint, so that losing the race for the unique key leaves the
            // surrounding transaction usable.
            return DB::transaction(fn () => AttendanceDay::create([
                'employee_id'               => $employee->id,
                'work_date'                 => Carbon::parse($date),
                'shift_id'                  => $shift?->id,
                'computed_work_minutes'     => 0,
                'computed_late_minutes'     => 0,
                'computed_early_minutes'    => 0,
                'computed_overtime_minutes' => 0,
                'payable_work_minutes'      => 0,
                'needs_review'              => true,
                'notes'                     => $note,
            ]));
        } catch (QueryException $e) {
            // After a deadlock the whole transaction is gone: nothing may go on in it.
            if (self::isLockConflict($e)) {
                throw $e;
            }
            // Another writer created the row for this employee and date first.
            $existing = $this->findDay($employee->id, $date);
            if ($existing) {
                return $existing;
            }
            throw $e;
        }
    }

    /**
     * Approved overtime in minutes; null goes back to the computed overtime.
     * Returns null when the day has no row: there is nothing to approve overtime on.
     */
    public function setApprovedOvertime(Employee $employee, string $date, ?int $minutes, string $reason, User $actor): ?AttendanceOverride
    {
        return DB::transaction(function () use ($employee, $date, $minutes, $reason, $actor) {
            $this->lockEmployee($employee);

            $day = $this->findDay($employee->id, $date);
            if (!$day) {
                return null;
            }

            $current = $day->approved_overtime_minutes;
            $day->approved_overtime_minutes = $minutes;
            $day->save();

            return AttendanceOverride::create([
                'attendance_day_id' => $day->id,
                'employee_id'       => $day->employee_id,
                'work_date'         => $day->work_date,
                'field'             => 'approved_overtime_minutes',
                'old_value'         => $current === null ? null : (string) $current,
                'new_value'         => $minutes === null ? null : (string) $minutes,
                'reason'            => $reason,
                'updated_by'        => $actor->id,
            ]);
        });
    }

    /** Hours typed by a person as whole minutes, the one rounding for approved overtime. */
    public static function overtimeMinutes(string|float|int $hours): int
    {
        return (int) round(((float) $hours) * 60);
    }

    /**
     * A shift for one employee on one date: replaces the single-day assignment of
     * that date. $recompute keeps what each entry point has always done afterwards:
     * RECOMPUTE_PUNCHES rebuilds the day from its punches (override-aware),
     * RECOMPUTE_DAY recalculates the minutes of an existing day row.
     */
    public function assignShiftForDate(Employee $employee, string $date, int $shiftId, string $remarks, string $recompute): EmployeeShiftAssignment
    {
        return DB::transaction(function () use ($employee, $date, $shiftId, $remarks, $recompute) {
            $this->lockEmployee($employee);

            EmployeeShiftAssignment::where('employee_id', $employee->id)
                ->whereDate('effective_date', $date)
                ->whereDate('effective_until', $date)
                ->delete();

            $assignment = EmployeeShiftAssignment::create([
                'employee_id'     => $employee->id,
                'shift_id'        => $shiftId,
                'effective_date'  => $date,
                'effective_until' => $date,
                'remarks'         => $remarks,
            ]);

            if ($recompute === self::RECOMPUTE_PUNCHES) {
                $this->autoComputeSingleDay($employee, $date);
            } elseif ($day = $this->findDay($employee->id, $date)) {
                $day->load('shift');
                $this->compute->recomputeDay($day);
            }

            return $assignment;
        });
    }

    /**
     * Apply SIL for a date. The three checks and their messages are the ones the
     * calendar has always answered with; they run under the employee lock so two
     * applications cannot both take the last remaining day.
     */
    public function applySil(Employee $employee, string $date, string $reason, User $actor): SilApplication
    {
        return DB::transaction(function () use ($employee, $date, $reason, $actor) {
            $this->lockEmployee($employee);
            $this->assertSilCanBeApplied($employee->fresh(), $date);

            return SilApplication::create([
                'employee_id' => $employee->id,
                'sil_date'    => $date,
                'reason'      => $reason,
                'applied_by'  => $actor->id,
            ]);
        });
    }

    public function assertSilCanBeApplied(Employee $employee, string $date): void
    {
        $year = Carbon::parse($date)->year;

        if (!$employee->sil_eligible) {
            throw new AttendanceChangeRefused('Employee is not marked as SIL-eligible. Toggle SIL eligibility on the Employee edit page first.');
        }
        // The same number as Employee::getSilRemainingDays, read without creating a balance
        // row: the Approvals page runs this check for every SIL entry it shows, and showing
        // a page must not write.
        $total = (float) (EmployeeSilBalance::where('employee_id', $employee->id)->where('year', $year)->value('total_days') ?? 0);
        if ($total - $employee->getSilUsedDays($year) < 1) {
            throw new AttendanceChangeRefused('No remaining SIL days for ' . $year . '.');
        }
        if ($employee->isDateSil($date)) {
            throw new AttendanceChangeRefused('SIL is already applied for this date.');
        }
    }

    /** Remove the SIL of a date. False when none was applied. */
    public function removeSil(Employee $employee, string $date): bool
    {
        return DB::transaction(function () use ($employee, $date) {
            $this->lockEmployee($employee);

            return SilApplication::where('employee_id', $employee->id)->whereDate('sil_date', $date)->delete() > 0;
        });
    }

    /**
     * Add a rest day, cancel one ("must work") or remove the override of a date,
     * with its log row, then rebuild the day from its punches.
     */
    public function changeRestDay(Employee $employee, string $date, string $action, string $reason, User $actor, bool $bulk = false, bool $onlyWithoutDayRow = false): DayOffLog
    {
        return DB::transaction(function () use ($employee, $date, $action, $reason, $actor, $bulk, $onlyWithoutDayRow) {
            $this->lockEmployee($employee);

            // The direct change of HR staff and admins is allowed only while the day has
            // no row. Checked here, under the lock, so a row that an import or another
            // edit creates at the same moment cannot be overlooked.
            if ($onlyWithoutDayRow && $this->findDay($employee->id, $date)) {
                throw new AttendanceChangeRefused('This day has attendance now. The change has to be approved.', 409);
            }

            $existing = DayOff::where('employee_id', $employee->id)->whereDate('off_date', $date)->first();
            $oldType = $existing?->type;
            $newType = null;

            if ($action === 'remove_override') {
                $existing?->delete();
            } else {
                $newType = $action === 'add_day_off' ? DayOff::TYPE_DAY_OFF : DayOff::TYPE_CANCEL_DAY_OFF;
                $remarks = $action === 'add_day_off'
                    ? ($bulk ? 'Bulk-set via attendance calendar' : 'Set via attendance calendar')
                    : ($bulk ? 'Bulk-cancelled via attendance calendar' : 'Cancelled via attendance calendar');

                if ($existing) {
                    $existing->update(['type' => $newType, 'remarks' => $remarks]);
                } else {
                    DayOff::create([
                        'employee_id' => $employee->id,
                        'off_date'    => $date,
                        'type'        => $newType,
                        'remarks'     => $remarks,
                    ]);
                }
            }

            $log = DayOffLog::create([
                'employee_id' => $employee->id,
                'off_date'    => $date,
                'action'      => $action,
                'old_type'    => $oldType,
                'new_type'    => $newType,
                'reason'      => $reason,
                'updated_by'  => $actor->id,
            ]);

            $this->autoComputeSingleDay($employee, $date);

            return $log;
        });
    }

    /**
     * For the screens that change a rest day without the request flow (the Day Off
     * Calendar and the Employee page). A locked date aborts with 423 for everyone.
     * For HR staff and admins a date that has a day row is refused: taking a worked
     * day out of pay has to be asked for on the Attendance Calendar, where it waits
     * for the CEO. Returns the refusal message, or null when the change may go on.
     */
    public function restDayGuard(?User $user, int $employeeId, string $date): ?string
    {
        $this->assertDateNotLocked($date);

        if (AttendanceChangeRequestService::mustRequest($user) && $this->findDay($employeeId, $date)) {
            return 'This day has attendance. Change it on the Attendance Calendar.';
        }

        return null;
    }

    /**
     * Recheck the day row of an employee and date after changes to it were made
     * official, so "needs review" and the note describe the day as it is now. Null
     * when the date has no day row (a SIL or rest-day change on a day nobody worked).
     * Errors are not swallowed: inside an approval they roll the approval back.
     */
    public function recheckDay(Employee $employee, string $date): ?AttendanceDay
    {
        return DB::transaction(function () use ($employee, $date) {
            $this->lockEmployee($employee);

            $day = $this->findDay($employee->id, $date);

            return $day ? $this->compute->recheckDay($day) : null;
        });
    }

    /**
     * Rebuild one employee-day from its punches without touching overridden fields.
     * Silent: the change that led here stands, and a day that cannot be rebuilt is
     * refreshed by the next "Compute Attendance".
     */
    public function autoComputeSingleDay(Employee $employee, string $date): void
    {
        // After the commit of the change (at once when no transaction is open): this
        // rebuild swallows its errors, and an error swallowed inside a transaction could
        // leave that transaction half-applied without anyone noticing.
        $rebuild = function () use ($employee, $date) {
            try {
                $stats = ['processed' => 0, 'errors' => 0, 'overrides_deleted' => 0];
                $this->compute->computeForEmployee(
                    $employee,
                    Carbon::parse($date)->startOfDay(),
                    Carbon::parse($date)->endOfDay(),
                    null,
                    $stats,
                    false
                );
            } catch (\Throwable $e) {
                Log::warning('Auto-compute after calendar edit failed', [
                    'employee_id' => $employee->id,
                    'date'        => $date,
                    'error'       => get_class($e),
                ]);
            }
        };

        // Inside a dry run the rebuild is part of what is shown, and is rolled back with it.
        if ($this->deferred !== null) {
            $this->deferred[] = $rebuild;

            return;
        }

        DB::afterCommit($rebuild);
    }

    /**
     * The first lock of every operation that writes attendance or that asks for,
     * changes, withdraws, approves or rejects a request.
     *
     * LOCK ORDER, the same everywhere: the employee row first, then the request rows
     * of that employee by id ascending. An operation that held request rows while
     * waiting for the employee row could deadlock against one that took them the
     * other way round; with one order the second simply waits for the first.
     */
    public function lockEmployeeById(int $employeeId): Employee
    {
        return Employee::whereKey($employeeId)->lockForUpdate()->firstOrFail();
    }

    public function lockEmployee(Employee $employee): void
    {
        $this->lockEmployeeById($employee->id);
    }

    /**
     * Run $work as one transaction. If the database gives up on it because of a
     * deadlock or a lock wait that timed out, the transaction has been rolled back:
     * it is run once more, and if that fails too the caller gets a refusal to show,
     * never the raw database error. Inside an outer transaction nothing is retried,
     * because the outer one is what the database rolled back.
     */
    public function withLockRetry(callable $work): mixed
    {
        if (DB::transactionLevel() > 0) {
            return DB::transaction($work);
        }

        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction($work);
            } catch (QueryException|DeadlockException $e) {
                if (!self::isLockConflict($e)) {
                    throw $e;
                }
                if ($attempt >= 2) {
                    throw new AttendanceChangeRefused(
                        'Someone else is changing this request. Try again.',
                        409,
                        ['short' => 'someone else is changing it']
                    );
                }
            }
        }
    }

    /**
     * MySQL error 1213 (deadlock, SQLSTATE 40001) or 1205 (lock wait timeout). When
     * either happens inside a nested transaction the framework reports it as a
     * DeadlockException instead of the query's own exception; both forms count.
     */
    public static function isLockConflict(\Throwable $e): bool
    {
        if ($e instanceof DeadlockException) {
            return true;
        }

        return $e instanceof QueryException
            && ((string) $e->getCode() === '40001' || in_array((int) ($e->errorInfo[1] ?? 0), [1213, 1205], true));
    }
}
