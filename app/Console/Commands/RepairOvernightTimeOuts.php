<?php

namespace App\Console\Commands;

use App\Models\AttendanceChangeRequest;
use App\Models\AttendanceDateLock;
use App\Models\AttendanceDay;
use App\Models\AttendanceLog;
use App\Models\AttendanceOverride;
use App\Models\Employee;
use App\Services\AttendanceApprovalService;
use App\Services\AttendanceComputeService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-time fix for days computed before a time out after midnight counted on the day it
 * started. Such a start date has no time out (the punch went to the next date, as that
 * date's time in) or a typed time out stored before its time in. Each one is recomputed
 * with the compute every screen uses now; the next date loses the day the punch made on
 * its own. A dry run unless --apply is given; running it again changes nothing.
 */
class RepairOvernightTimeOuts extends Command
{
    protected $signature = 'attendance:repair-overnight
                            {--from= : First start date to look at (Y-m-d); default: the date of the first punch}
                            {--to= : Last start date to look at (Y-m-d); default: yesterday}
                            {--apply : Write the changes (without it nothing is written)}';

    protected $description = 'Recompute past days whose time out is after midnight (dry run unless --apply)';

    /** The values of a day row the listing compares, with how each is shown. */
    private const SHOWN = [
        'time_out'                  => 'time out',
        'computed_work_minutes'     => 'worked',
        'computed_late_minutes'     => 'late',
        'computed_early_minutes'    => 'undertime',
        'computed_overtime_minutes' => 'overtime',
        'payable_work_minutes'      => 'payable',
        'needs_review'              => 'needs review',
    ];

    public function handle(AttendanceComputeService $compute): int
    {
        $apply = (bool) $this->option('apply');
        [$from, $to] = $this->range();
        if ($from === null) {
            $this->line('No punches: nothing to repair.');

            return self::SUCCESS;
        }

        $this->line("Start dates {$from} to {$to}.");
        $this->line($apply ? 'Writing the changes.' : 'Dry run: nothing is written. Run again with --apply to write.');

        $locked = AttendanceDateLock::whereDate('lock_date', '>=', $from)
            ->whereDate('lock_date', '<=', Carbon::parse($to)->addDay()->format('Y-m-d'))
            ->get()
            ->mapWithKeys(fn ($lock) => [$lock->lock_date->format('Y-m-d') => true])
            ->all();

        $counts = ['changed' => 0, 'removed' => 0, 'locked' => 0, 'errors' => 0];

        foreach ($this->candidates($compute, $from, $to) as [$employee, $date]) {
            $label = "{$employee->full_name} (#{$employee->id}) {$date}";
            if (isset($locked[$date])) {
                $counts['locked']++;
                $this->line("{$label}: skipped, the date is locked");
                continue;
            }

            DB::beginTransaction();
            try {
                $lines = $this->repair($compute, $employee, $date, isset($locked[Carbon::parse($date)->addDay()->format('Y-m-d')]), $counts);
                $apply ? DB::commit() : DB::rollBack();
            } catch (\Throwable $e) {
                DB::rollBack();
                $counts['errors']++;
                $this->line("{$label}: not repaired, " . get_class($e) . ': ' . $e->getMessage());
                continue;
            }

            if ($lines) {
                $this->line("{$label}: " . array_shift($lines));
                foreach ($lines as $line) {
                    $this->line('  ' . $line);
                }
            }
        }

        $this->line(sprintf(
            '%s %s, %s %s, %s skipped.%s',
            $this->count($counts['changed'], 'day'),
            $apply ? 'changed' : 'would change',
            $this->count($counts['removed'], 'next-date day'),
            $apply ? 'removed' : 'would be removed',
            $this->count($counts['locked'], 'locked date'),
            $counts['errors'] ? ' ' . $this->count($counts['errors'], 'error') . '.' : ''
        ));

        return $counts['errors'] ? self::FAILURE : self::SUCCESS;
    }

    /** @return array{0: ?string, 1: ?string} */
    private function range(): array
    {
        $from = $this->option('from') ?: AttendanceLog::min('punched_at');
        $to = $this->option('to') ?: Carbon::yesterday()->format('Y-m-d');
        if ($from === null) {
            return [null, null];
        }

        return [Carbon::parse($from)->format('Y-m-d'), Carbon::parse($to)->format('Y-m-d')];
    }

    /**
     * The start dates to recompute, by date then employee: those that take a punch of the
     * next date as their time out, and those with a time out stored before the time in.
     * Active employees only, as the Recompute button.
     *
     * @return array<int, array{0: Employee, 1: string}>
     */
    private function candidates(AttendanceComputeService $compute, string $from, string $to): array
    {
        $found = [];

        // A punch before "until" on the date after a start date: that start date may take it.
        AttendanceLog::where('punched_at', '>=', Carbon::parse($from)->addDay()->startOfDay())
            ->where('punched_at', '<', Carbon::parse(Carbon::parse($to)->addDay()->format('Y-m-d') . ' ' . AttendanceComputeService::overnightUntil()))
            ->whereTime('punched_at', '<', AttendanceComputeService::overnightUntil())
            ->get(['employee_id', 'punched_at'])
            ->each(function ($log) use (&$found) {
                $found[$log->employee_id . '_' . Carbon::parse($log->punched_at)->subDay()->format('Y-m-d')] = true;
            });

        AttendanceDay::whereDate('work_date', '>=', $from)
            ->whereDate('work_date', '<=', $to)
            ->whereNotNull('time_in')
            ->whereNotNull('time_out')
            ->whereColumn('time_out', '<', 'time_in')
            ->get(['employee_id', 'work_date'])
            ->each(function ($day) use (&$found) {
                $found[$day->employee_id . '_' . $day->work_date->format('Y-m-d')] = true;
            });

        $employees = Employee::whereIn('id', array_unique(array_map(fn ($key) => (int) explode('_', $key)[0], array_keys($found))))
            ->where('status', 'active')
            ->get()
            ->keyBy('id');

        $out = [];
        foreach (array_keys($found) as $key) {
            [$employeeId, $date] = explode('_', $key);
            $employee = $employees->get((int) $employeeId);
            if (!$employee) {
                continue;
            }
            // A punch in the window only matters when the start date really takes it.
            $takes = AttendanceComputeService::overnightPunch($compute->workDatePunches($employee, $date, $date)[$date], $date) !== null;
            $typed = AttendanceDay::where('employee_id', $employee->id)->whereDate('work_date', $date)
                ->whereColumn('time_out', '<', 'time_in')->exists();
            if ($takes || $typed) {
                $out[] = [$employee, $date];
            }
        }

        usort($out, fn ($a, $b) => [$a[1], $a[0]->id] <=> [$b[1], $b[0]->id]);

        return $out;
    }

    /**
     * Recompute one start date and, when its time out is now the next date's punch, the
     * next date. Returns the lines to show; none when nothing changes.
     *
     * @return string[]
     */
    private function repair(AttendanceComputeService $compute, Employee $employee, string $date, bool $nextLocked, array &$counts): array
    {
        $next = Carbon::parse($date)->addDay()->format('Y-m-d');
        $row = $this->row($employee, $date);
        // A copy: the recheck below updates the row object it is given.
        $before = $row ? clone $row : null;
        $stats = ['processed' => 0, 'skipped' => 0, 'errors' => 0];

        $compute->computeForEmployee(
            $employee, Carbon::parse($date)->startOfDay(), Carbon::parse($date)->endOfDay(),
            $row?->source_run_id, $stats, false
        );
        if ($stats['errors'] > 0) {
            throw new \RuntimeException('the compute failed (see the log)');
        }
        if ($stats['processed'] === 0 && $row) {
            // A day typed by hand without punches: the same recheck an approval runs.
            $compute->recheckDay($row);
        }

        $after = $this->row($employee, $date);
        $lines = [];
        $changes = $this->changes($before, $after);
        if ($changes) {
            $counts['changed']++;
            $lines[] = implode('; ', $changes);
        }

        $tookNextPunch = $after && $after->time_out && $after->time_out->format('Y-m-d') === $next;
        $nextRow = $this->row($employee, $next);
        if ($tookNextPunch && $nextRow) {
            if ($nextLocked) {
                $lines[] = "next date {$next}: skipped, the date is locked";
            } else {
                $lines = array_merge($lines, $this->repairNext($compute, $employee, $next, $nextRow, $counts));
            }
        }

        if ($lines && !$changes) {
            array_unshift($lines, 'unchanged');
        }

        return $lines;
    }

    /** @return string[] */
    private function repairNext(AttendanceComputeService $compute, Employee $employee, string $next, AttendanceDay $nextRow, array &$counts): array
    {
        $own = $compute->workDatePunches($employee, $next, $next)[$next]
            ->filter(fn ($log) => Carbon::parse($log->punched_at)->format('Y-m-d') === $next);
        $all = AttendanceLog::where('employee_id', $employee->id)
            ->where('punched_at', '>=', Carbon::parse($next)->startOfDay())
            ->where('punched_at', '<=', Carbon::parse($next)->endOfDay())
            ->count();

        if ($own->isEmpty()) {
            // The same rule as Recompute: what a compute of these punches gives today.
            $result = $compute->dropDayOfTakenPunches($employee, $next);
            if ($result === 'kept') {
                return ["next date {$next}: kept, it has edits or requests of its own; check it by hand"];
            }
            if ($result !== 'removed') {
                // Typed by hand without punches: not a day the punch made.
                return [];
            }
            $counts['removed']++;

            return ["next date {$next}: day removed (" . ($all === 1 ? 'its only punch is the time out above' : 'its punches are all the time out above') . ')'];
        }

        $stats = ['processed' => 0, 'skipped' => 0, 'errors' => 0];
        $compute->computeForEmployee(
            $employee, Carbon::parse($next)->startOfDay(), Carbon::parse($next)->endOfDay(),
            $nextRow->source_run_id, $stats, false
        );
        if ($stats['errors'] > 0) {
            throw new \RuntimeException("the compute of {$next} failed (see the log)");
        }
        $changes = $this->changes($nextRow, $this->row($employee, $next), ['time_in' => 'time in']);
        if (!$changes) {
            return [];
        }
        $counts['changed']++;

        return ["next date {$next}: " . implode('; ', $changes)];
    }

    private function row(Employee $employee, string $date): ?AttendanceDay
    {
        return AttendanceDay::where('employee_id', $employee->id)->whereDate('work_date', $date)->first();
    }

    /** @return string[] "time out none -> 2026-10-13 01:11", one per value that differs */
    private function changes(?AttendanceDay $before, ?AttendanceDay $after, array $extra = []): array
    {
        $out = [];
        foreach ($extra + self::SHOWN as $column => $label) {
            $was = $this->shown($column, $before?->{$column});
            $now = $this->shown($column, $after?->{$column});
            if ($was !== $now) {
                $out[] = "{$label} {$was} -> {$now}";
            }
        }

        return $out;
    }

    private function shown(string $column, mixed $value): string
    {
        return match (true) {
            $value === null                => 'none',
            $value instanceof Carbon       => $value->format('Y-m-d H:i'),
            $column === 'needs_review'     => $value ? 'yes' : 'no',
            default                        => AttendanceApprovalService::minutesText((int) $value),
        };
    }

    private function count(int $n, string $noun): string
    {
        return $n . ' ' . $noun . ($n === 1 ? '' : 's');
    }
}
