<?php

namespace App\Console\Commands;

use App\Models\AttendanceChangeRequest;
use App\Models\AttendanceDateLock;
use App\Models\Employee;
use App\Services\AttendanceChangeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-time fix for days approved before an approval rechecked the day: such a day
 * can be complete and still carry the "needs review" flag and note of the punches
 * it had before. Rechecks every day that has an approved change request, with the
 * same code an approval now runs. A dry run unless --apply is given; running it
 * again changes nothing.
 */
class RecheckApprovedDays extends Command
{
    protected $signature = 'attendance:recheck-approved
                            {--apply : Write the changes (without it nothing is written)}
                            {--allow-other-changes : Also write days whose recheck changes more than the flag and the note (times, shift, minutes)}';

    protected $description = 'Recheck "needs review" and the note of the attendance days that have approved change requests';

    /** The columns compared before and after, besides the flag and the note. */
    private const OTHER_COLUMNS = [
        'shift_id', 'time_in', 'lunch_out', 'lunch_in', 'time_out',
        'computed_work_minutes', 'computed_late_minutes', 'computed_early_minutes',
        'computed_overtime_minutes', 'payable_work_minutes', 'approved_overtime_minutes', 'source_run_id',
    ];

    public function handle(AttendanceChangeService $changes): int
    {
        $apply = (bool) $this->option('apply');
        // The fix is for the flag and the note. A day whose minutes would move too (its
        // shift assignment changed after the approval, say) changes pay, so it is only
        // written when asked for after reading the dry run.
        $allowOther = (bool) $this->option('allow-other-changes');

        $days = AttendanceChangeRequest::where('status', AttendanceChangeRequest::STATUS_APPROVED)
            ->get(['employee_id', 'work_date'])
            ->map(fn ($row) => ['employee_id' => (int) $row->employee_id, 'date' => $row->dateString()])
            ->unique(fn ($day) => $day['employee_id'] . '_' . $day['date'])
            ->sortBy(fn ($day) => $day['date'] . '_' . str_pad((string) $day['employee_id'], 10, '0', STR_PAD_LEFT))
            ->values();

        $counts = ['changed' => 0, 'held' => 0, 'unchanged' => 0, 'locked' => 0, 'no_day' => 0, 'errors' => 0];
        $lines = [];

        foreach ($days as $entry) {
            $employee = Employee::find($entry['employee_id']);
            $label = '#' . $entry['employee_id'] . ' ' . ($employee->full_name ?? '(employee not found)');
            if (!$employee) {
                $counts['no_day']++;
                $lines[] = [$label, $entry['date'], '', '', '', '', 'no employee'];
                continue;
            }

            DB::beginTransaction();
            try {
                // The same lock order as every attendance write: the employee first.
                $changes->lockEmployee($employee);

                // A locked date is closed for every writer; an unattended run leaves it as it is.
                if (AttendanceDateLock::whereDate('lock_date', $entry['date'])->lockForUpdate()->exists()) {
                    DB::rollBack();
                    $before = $this->snapshot($changes, $employee, $entry['date']);
                    $counts['locked']++;
                    $lines[] = [$label, $entry['date'], $before ? (string) $before['needs_review'] : '', $before['notes'] ?? '', '', '', 'skipped: date locked'];
                    continue;
                }

                $before = $this->snapshot($changes, $employee, $entry['date']);
                if (!$before) {
                    DB::rollBack();
                    $counts['no_day']++;
                    $lines[] = [$label, $entry['date'], '', '', '', '', 'no day row'];
                    continue;
                }

                $changes->recheckDay($employee, $entry['date']);
                $after = $this->snapshot($changes, $employee, $entry['date']);

                $others = [];
                foreach (self::OTHER_COLUMNS as $column) {
                    if ((string) $before[$column] !== (string) $after[$column]) {
                        $others[] = "{$column}: " . ($before[$column] ?? 'null') . ' → ' . ($after[$column] ?? 'null');
                    }
                }
                $changed = !empty($others)
                    || (string) $before['needs_review'] !== (string) $after['needs_review']
                    || (string) $before['notes'] !== (string) $after['notes'];
                $held = !empty($others) && !$allowOther;

                if ($changed && $apply && !$held) {
                    DB::commit();
                } else {
                    DB::rollBack();
                }

                if (!$changed) {
                    $counts['unchanged']++;
                    $result = 'unchanged';
                } elseif ($held) {
                    $counts['held']++;
                    $result = 'held: other columns change (--allow-other-changes)';
                } else {
                    $counts['changed']++;
                    $result = $apply ? 'changed' : 'would change';
                }
                $lines[] = [
                    $label,
                    $entry['date'],
                    $before['needs_review'] . ' → ' . $after['needs_review'],
                    $before['notes'] ?? '',
                    $after['notes'] ?? '',
                    implode('; ', $others),
                    $result,
                ];
            } catch (\Throwable $e) {
                DB::rollBack();
                $counts['errors']++;
                $lines[] = [$label, $entry['date'], '', '', '', '', 'error: ' . $e->getMessage()];
            }
        }

        $this->table(['Employee', 'Date', 'Needs review', 'Note before', 'Note after', 'Other changes', 'Result'], $lines);

        $summary = sprintf(
            '%d days checked: %d %s, %d held (other columns change), %d unchanged, %d skipped (date locked), %d without a day row, %d errors.',
            $days->count(),
            $counts['changed'],
            $apply ? 'changed' : 'to change',
            $counts['held'],
            $counts['unchanged'],
            $counts['locked'],
            $counts['no_day'],
            $counts['errors']
        );
        if ($apply) {
            $this->info($summary);
        } else {
            $this->warn('Dry run: nothing was written. Run again with --apply to write the changes.');
            $this->info($summary);
        }

        return $counts['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** The day row as stored, or null. */
    private function snapshot(AttendanceChangeService $changes, Employee $employee, string $date): ?array
    {
        $day = $changes->findDay($employee->id, $date);
        if (!$day) {
            return null;
        }
        $row = (array) DB::table('attendance_days')->where('id', $day->id)->first();
        $row['needs_review'] = (int) $row['needs_review'];

        return $row;
    }
}
