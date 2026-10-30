<?php

namespace App\Services;

use App\Models\AttendanceChangeRequest;
use App\Models\AttendanceDateLock;
use App\Models\AttendanceDay;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The CEO's decision on an entry: every pending request of one employee and
 * date, approved or rejected as a whole.
 *
 * An approval is one transaction. It locks the employee row and the entry's
 * rows, checks again everything that may have changed since the page was
 * loaded, applies each field through AttendanceChangeService (the same code
 * the CEO's direct edit uses) and marks the rows approved. Any refusal or
 * error rolls all of it back and leaves the entry pending.
 */
class AttendanceApprovalService
{
    public function __construct(
        protected AttendanceChangeService $changes,
        protected AttendanceChangeRequestService $requests
    ) {
    }

    /** The role as stored now, not as a page or a session remembers it. */
    public static function isCeoNow(?User $user): bool
    {
        return $user !== null && User::whereKey($user->id)->value('role') === User::ROLE_CEO;
    }

    /**
     * The pending rows of an employee and date, oldest first. A profile assignment request is
     * never part of a day's entry, even on its first date: it is decided on its own card.
     */
    public function entryRows(int $employeeId, string $date, bool $lock = false): Collection
    {
        return AttendanceChangeRequest::with('requester')
            ->where('employee_id', $employeeId)
            ->where('work_date', $date)
            ->where('field', '!=', AttendanceChangeRequest::FIELD_ASSIGNMENT)
            ->where('status', AttendanceChangeRequest::STATUS_PENDING)
            ->orderBy('id')
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->get();
    }

    /** The rows decided together with $anchor: its day's entry, or the assignment request alone. */
    protected function rowsOf(AttendanceChangeRequest $anchor, bool $lock): Collection
    {
        if ($anchor->isAssignment()) {
            return AttendanceChangeRequest::with('requester')
                ->whereKey($anchor->id)
                ->where('status', AttendanceChangeRequest::STATUS_PENDING)
                ->when($lock, fn ($q) => $q->lockForUpdate())
                ->get();
        }

        return $this->entryRows($anchor->employee_id, $anchor->dateString(), $lock);
    }

    /* ── Requests that change nothing close themselves ── */

    /** The kinds whose asked value can be compared with the official one. Rest days are actions on a pattern, not values. */
    public const CLOSABLE_FIELDS = [
        'time_in', 'lunch_out', 'lunch_in', 'time_out',
        AttendanceChangeRequest::FIELD_OVERTIME, AttendanceChangeRequest::FIELD_SHIFT, AttendanceChangeRequest::FIELD_SIL,
    ];

    /** True when the official value already is what the request asks for. */
    public function isUnneeded(Employee $employee, AttendanceChangeRequest $row): bool
    {
        if (!in_array($row->field, self::CLOSABLE_FIELDS, true)) {
            return false;
        }
        $official = $this->requests->officialValue($employee, $row->dateString(), $row->field);

        if ($row->field === AttendanceChangeRequest::FIELD_SIL) {
            return ($row->new_value === 'apply' && $official === 'applied')
                || ($row->new_value === 'remove' && $official === null);
        }

        return $official === $row->new_value;
    }

    /**
     * Close the pending requests whose value is already official, as "no longer needed".
     * Never on a locked date. Each employee is one transaction under the employee lock, the
     * same lock an approval takes, and a row is closed only if it is still pending at the
     * version that was compared. A closed shift request whose day was computed with another
     * shift is recomputed (see recomputeClosedShift). Returns how many were closed.
     *
     * @param int[]|null $ids only these requests; null for every pending one
     */
    public function closeUnneeded(?array $ids = null): int
    {
        $candidates = AttendanceChangeRequest::where('status', AttendanceChangeRequest::STATUS_PENDING)
            ->whereIn('field', self::CLOSABLE_FIELDS)
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->get(['id', 'employee_id', 'work_date']);
        if ($candidates->isEmpty()) {
            return 0;
        }

        $closed = 0;
        foreach ($candidates->groupBy('employee_id') as $employeeId => $rows) {
            try {
                $closed += $this->changes->withLockRetry(function () use ($employeeId, $rows) {
                    $employee = $this->changes->lockEmployeeById((int) $employeeId);
                    $locked = $this->lockedDatesForUpdate($rows->map->dateString()->unique()->values()->all());
                    $fresh = AttendanceChangeRequest::whereIn('id', $rows->pluck('id'))
                        ->where('status', AttendanceChangeRequest::STATUS_PENDING)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get()
                        ->reject(fn ($row) => in_array($row->dateString(), $locked, true));

                    return $this->closeRows($employee, $fresh->filter(fn ($row) => $this->isUnneeded($employee, $row)));
                });
            } catch (AttendanceChangeRefused $e) {
                // Someone else holds this employee right now: the next page load closes them.
            } catch (\Throwable $e) {
                // One employee's day that cannot be recomputed must not keep the CEO out of the
                // page: that employee's requests stay pending and are tried again next time.
                Log::warning('Closing requests that are no longer needed failed: ' . get_class($e), ['employee_id' => $employeeId]);
            }
        }

        return $closed;
    }

    /**
     * Mark rows "no longer needed" (compare-and-set on their version) and recompute the days
     * of the closed shift requests. Runs inside the caller's transaction and employee lock.
     */
    protected function closeRows(Employee $employee, Collection $rows): int
    {
        $shiftNames = Shift::pluck('name', 'id')->all();
        $closed = 0;
        foreach ($rows as $row) {
            $updated = AttendanceChangeRequest::whereKey($row->id)
                ->where('status', AttendanceChangeRequest::STATUS_PENDING)
                ->where('version', $row->version)
                ->update([
                    'status'          => AttendanceChangeRequest::STATUS_NO_LONGER_NEEDED,
                    'pending_marker'  => null,
                    'decided_by'      => null,
                    'decided_at'      => now(),
                    'decision_reason' => 'The official ' . strtolower($row->fieldLabel()) . ' is already '
                        . $row->displayValue($row->new_value, $shiftNames) . '. Nothing was written.',
                    'updated_at'      => now(),
                ]);
            if ($updated === 1) {
                $closed++;
                $row->status = AttendanceChangeRequest::STATUS_NO_LONGER_NEEDED;
                if ($row->field === AttendanceChangeRequest::FIELD_SHIFT) {
                    $this->recomputeClosedShift($employee, $row);
                }
            }
        }

        return $closed;
    }

    /**
     * A shift request closed because the shift already is official (a profile assignment, say)
     * may sit on a day whose minutes were computed with the shift it had before. That day gets
     * the compute the approval of this request would have run after its write (the same
     * recompute of the shift change for its origin, then the recheck), without the write itself.
     * A day already computed with the official shift, or without a day row, is left alone.
     */
    protected function recomputeClosedShift(Employee $employee, AttendanceChangeRequest $row): void
    {
        $date = $row->dateString();
        $day = $this->changes->findDay($employee->id, $date);
        if (!$day || (string) $day->shift_id === (string) $row->new_value) {
            return;
        }
        [, $recompute] = $this->shiftRecompute($row->origin);
        $this->changes->recomputeAfterShiftChange($employee, $date, $recompute);
        $this->changes->recheckDay($employee, $date);
    }

    /** The remarks and the recompute that follow a shift change, as the screen it was asked on does it. */
    protected function shiftRecompute(string $origin): array
    {
        return match ($origin) {
            'save_all'            => ['Set via attendance calendar (Save All)', AttendanceChangeService::RECOMPUTE_DAY],
            'attendance_override' => ['Set via attendance page', AttendanceChangeService::RECOMPUTE_DAY],
            default               => ['Set via attendance calendar', AttendanceChangeService::RECOMPUTE_PUNCHES],
        };
    }

    /** @return string[] the given dates that are locked, read for update so a lock set now waits */
    protected function lockedDatesForUpdate(array $dates): array
    {
        if ($dates === []) {
            return [];
        }

        return AttendanceDateLock::where(function ($q) use ($dates) {
            foreach ($dates as $date) {
                $q->orWhereDate('lock_date', $date);
            }
        })->lockForUpdate()->get()->map(fn ($lock) => $lock->lock_date->format('Y-m-d'))->all();
    }

    /** What a page showed of an entry: its pending rows and the version of each. */
    public static function seenToken(Collection $rows): string
    {
        return $rows->sortBy('id')->map(fn ($row) => $row->id . ':' . $row->version)->implode(',');
    }

    /**
     * Reasons why the entry cannot be approved as the day is now. Empty when it can.
     * A date lock is not in this list; it is checked on its own.
     *
     * @return string[]
     */
    public function blocks(Employee $employee, string $date, Collection $rows): array
    {
        $blocks = [];
        $isRestDay = null;
        $isSil = null;
        $hasTimeRows = $rows->contains(fn ($row) => $row->isTimeField());

        foreach ($rows as $row) {
            $was = $row->day_state ?? [];

            if (in_array($row->kind(), ['times', 'overtime', 'shift'], true)) {
                // Only a day that BECAME a rest day or SIL after the request blocks it:
                // times on a day that already was one were asked for knowingly.
                $isRestDay ??= $employee->isDayOff($date);
                $isSil ??= $employee->isDateSil($date);
                if ($isRestDay && empty($was['is_rest_day'])) {
                    $blocks['rest_day'] = 'Now a rest day';
                }
                if ($isSil && empty($was['is_sil'])) {
                    $blocks['sil'] = 'SIL applied';
                }
            }

            if ($row->field === AttendanceChangeRequest::FIELD_OVERTIME
                && !$hasTimeRows && !$this->changes->findDay($employee->id, $date)) {
                $blocks['no_day'] = 'No attendance record for this date';
            }

            if ($row->field === AttendanceChangeRequest::FIELD_SIL) {
                if ($row->new_value === 'apply') {
                    try {
                        $this->changes->assertSilCanBeApplied($employee, $date);
                    } catch (AttendanceChangeRefused $e) {
                        $blocks['sil_apply'] = $e->getMessage();
                    }
                } elseif (!$employee->isDateSil($date)) {
                    $blocks['sil_remove'] = 'No SIL is applied on this date';
                }
            }
        }

        return array_values($blocks);
    }

    /**
     * The fields whose official value is no longer what the request saw.
     *
     * @return array<int, array{field: string, label: string, was: ?string, now: ?string}> keyed by request id
     */
    public function drift(Employee $employee, string $date, Collection $rows): array
    {
        $drift = [];
        foreach ($rows as $row) {
            $now = $this->requests->officialValue($employee, $date, $row->field);
            if ($now !== $row->old_value) {
                $drift[$row->id] = [
                    'field' => $row->field,
                    'label' => $row->fieldLabel(),
                    'was'   => $row->old_value,
                    'now'   => $now,
                ];
            }
        }

        return $drift;
    }

    /**
     * Approve the entry the given request belongs to.
     *
     * @param string $seen      the entry as the CEO saw it (see seenToken)
     * @param bool   $confirmed the CEO confirmed approving over changed official values
     * @return Collection the rows of the entry, each with the status it was given: approved,
     *         or no longer needed when its value already was official (nothing is written for it)
     */
    public function approve(AttendanceChangeRequest $anchor, string $seen, bool $confirmed, User $approver): Collection
    {
        if ($anchor->isAssignment()) {
            return $this->approveAssignment($anchor, $seen, $confirmed, $approver);
        }

        return $this->changes->withLockRetry(function () use ($anchor, $seen, $confirmed, $approver) {
            $date = $anchor->dateString();

            // The lock every official write takes: this approval and a direct edit of
            // the same employee now run one after the other. It is the first statement
            // of the transaction on purpose: every read after it then sees what the
            // writer before us committed, not the data as it was before the wait.
            $employee = $this->changes->lockEmployeeById($anchor->employee_id);
            $all = $rows = $this->entryRows($employee->id, $date, true);

            $this->assertStillPending($anchor, $rows);

            if (!self::isCeoNow($approver)) {
                throw new AttendanceChangeRefused('Only the CEO can approve.', 403, ['short' => 'not allowed']);
            }
            if ($rows->contains(fn ($row) => (int) $row->requested_by === (int) $approver->id)) {
                throw new AttendanceChangeRefused('You cannot approve your own request.', 403, ['short' => 'own request']);
            }

            $this->assertSeen($rows, $seen);

            // Read for update, so a lock being set on this date at the same moment
            // waits for this approval instead of slipping in behind the check.
            $lock = AttendanceDateLock::whereDate('lock_date', $date)->lockForUpdate()->first();
            if ($lock) {
                throw new AttendanceChangeRefused($this->changes->lockMessage($lock), 423, ['short' => 'date locked']);
            }

            // A field whose value became official since the page was loaded (the same shift set
            // on the profile, the same time entered) is closed, not written again; the rest of
            // the entry is approved as usual. When nothing is left, the entry is done.
            $unneeded = $rows->filter(fn ($row) => $this->isUnneeded($employee, $row));
            if ($unneeded->isNotEmpty()) {
                $this->closeRows($employee, $unneeded);
                $rows = $rows->reject(fn ($row) => $row->status === AttendanceChangeRequest::STATUS_NO_LONGER_NEEDED)->values();
                if ($rows->isEmpty()) {
                    return $all;
                }
            }

            $blocks = $this->blocks($employee, $date, $rows);
            if (!empty($blocks)) {
                throw new AttendanceChangeRefused(
                    'Cannot approve: ' . implode('; ', array_map(fn ($block) => rtrim($block, '.'), $blocks)) . '.',
                    422,
                    ['short' => strtolower($blocks[0])]
                );
            }

            $drift = $this->drift($employee, $date, $rows);
            if (!empty($drift) && !$confirmed) {
                throw new AttendanceChangeRefused(
                    'Official value changed since this was asked. Review it and confirm.',
                    409,
                    ['short' => 'official value changed', 'needs_confirm' => true, 'changes' => array_values($drift)]
                );
            }

            $applied = $this->applyEntry($employee, $date, $rows, $approver);

            // Compare-and-set: the rows are marked approved only if each is still
            // pending at the version that was applied. This holds even where the
            // row lock above does not exist.
            $updated = 0;
            foreach ($rows as $row) {
                $updated += AttendanceChangeRequest::whereKey($row->id)
                    ->where('status', AttendanceChangeRequest::STATUS_PENDING)
                    ->where('version', $row->version)
                    ->update([
                        'status'         => AttendanceChangeRequest::STATUS_APPROVED,
                        'pending_marker' => null,
                        'decided_by'     => $approver->id,
                        'decided_at'     => now(),
                        'applied_type'   => $applied[$row->id][0] ?? null,
                        'applied_id'     => $applied[$row->id][1] ?? null,
                        'updated_at'     => now(),
                    ]);
            }
            if ($updated !== $rows->count()) {
                throw new AttendanceChangeRefused('Request changed, review again.', 409, ['short' => 'request changed']);
            }
            $rows->each(fn ($row) => $row->status = AttendanceChangeRequest::STATUS_APPROVED);

            return $all;
        });
    }

    /* ── Profile shift assignment requests ── */

    /**
     * Approve a shift assignment request of the profile: the assignment is written by the same
     * code as the CEO's own profile edit (closing the previous open assignment, the overlap
     * rule, the schedule mode), then the computed days of the range it changes are recomputed,
     * except those on a locked date. One transaction: a refusal leaves everything as it was.
     */
    protected function approveAssignment(AttendanceChangeRequest $anchor, string $seen, bool $confirmed, User $approver): Collection
    {
        return $this->changes->withLockRetry(function () use ($anchor, $seen, $confirmed, $approver) {
            $employee = $this->changes->lockEmployeeById($anchor->employee_id);
            $rows = $this->rowsOf($anchor, true);
            $this->assertStillPending($anchor, $rows);
            if (!self::isCeoNow($approver)) {
                throw new AttendanceChangeRefused('Only the CEO can approve.', 403, ['short' => 'not allowed']);
            }
            $row = $rows->first();
            if ((int) $row->requested_by === (int) $approver->id) {
                throw new AttendanceChangeRefused('You cannot approve your own request.', 403, ['short' => 'own request']);
            }
            $this->assertSeen($rows, $seen);

            $drift = $this->assignmentDrift($row);
            if ($drift === 'gone') {
                throw new AttendanceChangeRefused('The assignment this asks to change was removed since. Nothing was changed.', 409, ['short' => 'assignment removed']);
            }
            if ($drift !== null && !$confirmed) {
                throw new AttendanceChangeRefused('The assignment was changed since this was asked. Review it and confirm.', 409, ['short' => 'official value changed', 'needs_confirm' => true]);
            }

            $result = $this->applyAssignment($employee, $row);

            $updated = AttendanceChangeRequest::whereKey($row->id)
                ->where('status', AttendanceChangeRequest::STATUS_PENDING)
                ->where('version', $row->version)
                ->update([
                    'status'         => AttendanceChangeRequest::STATUS_APPROVED,
                    'pending_marker' => null,
                    'decided_by'     => $approver->id,
                    'decided_at'     => now(),
                    'applied_type'   => 'employee_shift_assignment',
                    'applied_id'     => $result['assignment_id'],
                    'updated_at'     => now(),
                ]);
            if ($updated !== 1) {
                throw new AttendanceChangeRefused('Request changed, review again.', 409, ['short' => 'request changed']);
            }
            $row->status = AttendanceChangeRequest::STATUS_APPROVED;

            return $rows;
        });
    }

    /**
     * What an approved assignment request writes, also run (and rolled back) by its preview.
     *
     * @return array{assignment_id: ?int, recomputed: string[], locked: string[]}
     */
    protected function applyAssignment(Employee $employee, AttendanceChangeRequest $row, ?array $onlyDates = null): array
    {
        $d = $row->details ?? [];
        $after = $d['after'] ?? null;
        $assignmentId = null;
        // Read before the write: the range depends on the assignments as they are now.
        [$from, $until] = $this->assignmentRange($row);

        switch ($d['action'] ?? null) {
            case AttendanceChangeRequest::ASSIGNMENT_ADD:
                $assignmentId = $this->changes->addShiftAssignment($employee, $after)['assignment']->id;
                break;
            case AttendanceChangeRequest::ASSIGNMENT_UPDATE:
                $assignment = \App\Models\EmployeeShiftAssignment::where('employee_id', $employee->id)->findOrFail($d['assignment_id']);
                $assignmentId = $this->changes->updateShiftAssignment($assignment, $after)->id;
                break;
            case AttendanceChangeRequest::ASSIGNMENT_DELETE:
                $assignment = \App\Models\EmployeeShiftAssignment::where('employee_id', $employee->id)->findOrFail($d['assignment_id']);
                $this->changes->deleteShiftAssignment($assignment);
                break;
            default:
                throw new AttendanceChangeRefused('This request cannot be applied.', 422, ['short' => 'unknown request']);
        }

        $done = $this->changes->recomputeShiftRange(Employee::find($employee->id), $from, $until, $onlyDates);

        return ['assignment_id' => $assignmentId] + $done;
    }

    /**
     * The dates whose shift the request can change, read before it is applied: from the
     * earliest start to the latest end of the assignment as it is now and as asked. Open-ended
     * when either is ongoing, and also when an addition closes an open-ended assignment that
     * started before it: the days after the new one's end lose that shift too.
     *
     * @return array{0: string, 1: ?string}
     */
    public function assignmentRange(AttendanceChangeRequest $row): array
    {
        $d = $row->details ?? [];
        $current = null;
        if (!empty($d['assignment_id'])) {
            $now = \App\Models\EmployeeShiftAssignment::where('employee_id', $row->employee_id)->find($d['assignment_id']);
            $current = $now ? [
                'effective_date'  => $now->effective_date->toDateString(),
                'effective_until' => $now->effective_until?->toDateString(),
            ] : null;
        }
        $parts = array_values(array_filter([$current ?? ($d['before'] ?? null), $d['after'] ?? null]));
        $from = min(array_column($parts, 'effective_date'));
        $until = null;
        if (!in_array(null, array_map(fn ($p) => $p['effective_until'] ?? null, $parts), true)) {
            $until = max(array_map(fn ($p) => $p['effective_until'], $parts));
        }
        if ($until !== null && ($d['action'] ?? null) === AttendanceChangeRequest::ASSIGNMENT_ADD
            && \App\Models\EmployeeShiftAssignment::where('employee_id', $row->employee_id)
                ->whereNull('effective_until')->where('effective_date', '<', $d['after']['effective_date'])->exists()) {
            $until = null;
        }

        return [$from, $until];
    }

    /** Null when the assignment is as the request saw it; 'gone' when it was removed; 'changed' otherwise. */
    public function assignmentDrift(AttendanceChangeRequest $row): ?string
    {
        $d = $row->details ?? [];
        if (($d['action'] ?? null) === AttendanceChangeRequest::ASSIGNMENT_ADD) {
            return null;
        }
        $now = \App\Models\EmployeeShiftAssignment::where('employee_id', $row->employee_id)->find($d['assignment_id'] ?? 0);
        if (!$now) {
            return 'gone';
        }
        $before = $d['before'] ?? [];
        $same = (int) $now->shift_id === (int) ($before['shift_id'] ?? 0)
            && $now->effective_date->toDateString() === ($before['effective_date'] ?? null)
            && ($now->effective_until?->toDateString()) === ($before['effective_until'] ?? null);

        return $same ? null : 'changed';
    }

    /** At most this many days are previewed on an assignment card, the most recent ones. */
    public const ASSIGNMENT_PREVIEW_DAYS = 62;

    /**
     * The computed days an assignment request changes, now and as Approve would leave them:
     * the approval's own applyAssignment run once in a dry run, then rolled back. Only the days
     * shown are recomputed in it, so a long ongoing range costs no more than the days on the card.
     *
     * @return array{days: array<string, array{now: array, after: ?array}>, locked: string[], error: ?string, more: int}
     */
    public function assignmentPreview(Employee $employee, AttendanceChangeRequest $row): array
    {
        $employee = (clone $employee)->setRelations([]);
        [$from, $until] = $this->assignmentRange($row);
        $dates = $this->changes->daysInRange($employee->id, $from, $until);
        $locked = $this->changes->lockedDates($dates);
        $open = array_values(array_diff($dates, $locked));
        $more = max(0, count($open) - self::ASSIGNMENT_PREVIEW_DAYS);
        $shown = array_slice($open, -self::ASSIGNMENT_PREVIEW_DAYS);
        $out = ['days' => [], 'locked' => $locked, 'error' => null, 'more' => $more];

        try {
            $now = [];
            foreach ($shown as $date) {
                $now[$date] = $this->dayPicture($employee, $date);
            }
            $after = $this->changes->dryRun(
                fn () => $this->applyAssignment($employee, $row, $shown),
                fn () => array_combine($shown, array_map(fn ($date) => $this->dayPicture(Employee::find($employee->id), $date), $shown))
            );
            foreach ($shown as $date) {
                $out['days'][$date] = ['now' => $now[$date], 'after' => $after[$date] ?? null];
            }
        } catch (AttendanceChangeRefused $e) {
            $out['error'] = $e->getMessage();
        } catch (\Throwable $e) {
            Log::warning('Assignment preview failed: ' . get_class($e), ['request_id' => $row->id]);
            $out['error'] = 'It could not be worked out.';
        }

        return $out;
    }

    /**
     * Worked, late and undertime minutes summed over days, now and if approved. Days without
     * a preview are left out of both sides and counted.
     *
     * @param array<int, array{now: ?array, after: ?array}> $days
     */
    public static function totals(array $days): array
    {
        $sum = ['now' => ['work' => 0, 'late' => 0, 'undertime' => 0], 'after' => ['work' => 0, 'late' => 0, 'undertime' => 0], 'days' => 0, 'missing' => 0];
        foreach ($days as $day) {
            if (empty($day['now']) || empty($day['after']) || !$day['now']['has_row'] || !$day['after']['has_row']) {
                $sum['missing']++;
                continue;
            }
            $sum['days']++;
            foreach (['work', 'late', 'undertime'] as $key) {
                $sum['now'][$key] += (int) $day['now'][$key];
                $sum['after'][$key] += (int) $day['after'][$key];
            }
        }

        return $sum;
    }

    /* ── Shift change groups ── */

    /**
     * Refuse a posted group unless every posted request is a pending shift request of one
     * change, the one the posted key names (ApprovalCards::groupKey), and alone on its day.
     * The page is not trusted: a wrong group would decide days the CEO did not see on the card.
     *
     * @param int[] $ids
     */
    public function assertOneShiftChange(array $ids, string $key): void
    {
        $rows = AttendanceChangeRequest::whereIn('id', $ids)->get();
        $refuse = fn () => throw new AttendanceChangeRefused('These days are not one shift change. Reload the page and decide again.', 422, ['short' => 'not one change']);
        if ($ids === [] || $rows->count() !== count(array_unique($ids))) {
            $refuse();
        }
        foreach ($rows as $row) {
            if (!$row->isPending() || ApprovalCards::groupKey($row) !== $key
                || $this->entryRows($row->employee_id, $row->dateString())->count() !== 1) {
                $refuse();
            }
        }
    }

    /**
     * Reject the entry the given request belongs to. Nothing official changes, so
     * neither a date lock nor the state of the day stands in the way.
     */
    public function reject(AttendanceChangeRequest $anchor, string $seen, string $reason, User $rejecter): Collection
    {
        return $this->changes->withLockRetry(function () use ($anchor, $seen, $reason, $rejecter) {
            // The employee row first, as an approval does: a rejection and an approval
            // of the same entry then wait for each other instead of deadlocking.
            $this->changes->lockEmployeeById($anchor->employee_id);
            $rows = $this->rowsOf($anchor, true);

            $this->assertStillPending($anchor, $rows);

            if (!self::isCeoNow($rejecter)) {
                throw new AttendanceChangeRefused('Only the CEO can reject.', 403, ['short' => 'not allowed']);
            }

            $this->assertSeen($rows, $seen);

            $updated = AttendanceChangeRequest::whereIn('id', $rows->pluck('id'))
                ->where('status', AttendanceChangeRequest::STATUS_PENDING)
                ->update([
                    'status'          => AttendanceChangeRequest::STATUS_REJECTED,
                    'pending_marker'  => null,
                    'decided_by'      => $rejecter->id,
                    'decided_at'      => now(),
                    'decision_reason' => $reason,
                    'updated_at'      => now(),
                ]);
            if ($updated !== $rows->count()) {
                throw new AttendanceChangeRefused('Request changed, review again.', 409, ['short' => 'request changed']);
            }

            return $rows;
        });
    }

    protected function assertStillPending(AttendanceChangeRequest $anchor, Collection $rows): void
    {
        if ($rows->contains('id', $anchor->id)) {
            return;
        }

        $status = AttendanceChangeRequest::whereKey($anchor->id)->value('status');
        $message = match ($status) {
            AttendanceChangeRequest::STATUS_APPROVED  => 'Already approved.',
            AttendanceChangeRequest::STATUS_REJECTED  => 'Already rejected.',
            AttendanceChangeRequest::STATUS_WITHDRAWN => 'This request was withdrawn.',
            AttendanceChangeRequest::STATUS_NO_LONGER_NEEDED => 'No longer needed: the official value already is what was asked.',
            default                                   => 'This request is no longer pending.',
        };

        throw new AttendanceChangeRefused($message, 409, ['short' => strtolower(rtrim($message, '.'))]);
    }

    /** Refuse when the entry is not what the CEO saw: a value changed, a field was added or taken back. */
    protected function assertSeen(Collection $rows, string $seen): void
    {
        if (self::seenToken($rows) !== trim($seen)) {
            throw new AttendanceChangeRefused('Request changed, review again.', 409, ['short' => 'request changed']);
        }
    }

    /**
     * What an approval writes: every field of the entry, then the recheck of the day.
     * The approval and the preview both run this, so the preview cannot show anything
     * other than what Approve saves.
     *
     * @return array<int, array{0: string, 1: ?int}> see apply()
     */
    protected function applyEntry(Employee $employee, string $date, Collection $rows, User $approver): array
    {
        $applied = $this->apply($employee, $date, $rows, $approver);

        // Once per entry, after every field is in: the writes above recompute only the
        // minutes, so the day would keep the "needs review" and note of the punches it
        // had before. Inside the approval's transaction, so a failed recheck undoes it.
        $this->changes->recheckDay($employee, $date);

        return $applied;
    }

    /**
     * The day now and as Approve would leave it, for the CEO to see before deciding.
     *
     * "If approved" is not a calculation of its own: the entry is applied with
     * applyEntry(), the same code Approve runs, inside a transaction that is always
     * rolled back (AttendanceChangeService::dryRun), and the day is read before the
     * rollback. Nothing is committed. The checks that decide whether Approve is allowed
     * at all (role, date lock, blocks, changed values) are not part of it: the page
     * shows those on their own, and the preview shows what Approve writes once allowed.
     *
     * @return array{now: array, after: ?array, error: ?string, warnings: string[]}
     */
    public function preview(Employee $employee, string $date, Collection $rows, User $approver): array
    {
        // A copy without loaded relations: nothing the rolled-back writes leave in a
        // model's memory may reach the rest of the page.
        $employee = (clone $employee)->setRelations([]);
        $preview = ['now' => null, 'after' => null, 'error' => null, 'warnings' => []];

        // Whatever fails here, the page shows "Preview not available" and stays usable.
        try {
            $preview['now'] = $this->dayPicture($employee, $date);
            $after = $this->changes->dryRun(
                fn () => $this->applyEntry($employee, $date, $rows, $approver),
                fn () => $this->dayPicture($employee, $date)
            );

            if ($after['has_row'] && $after['shift_id'] === null) {
                return ['error' => 'No shift is set for this employee on this date, so hours, late and undertime cannot be worked out.'] + $preview;
            }

            return ['after' => $after, 'warnings' => $this->warnings($after)] + $preview;
        } catch (AttendanceChangeRefused $e) {
            return ['error' => $e->getMessage()] + $preview;
        } catch (\Throwable $e) {
            Log::warning('Approval preview failed: ' . get_class($e), ['employee_id' => $employee->id, 'date' => $date]);

            return ['error' => 'It could not be worked out.'] + $preview;
        }
    }

    /**
     * One employee-day as the preview shows it, read from the database as it is at
     * this moment (inside a dry run: as the approval leaves it).
     */
    public function dayPicture(Employee $employee, string $date): array
    {
        $day = $this->changes->findDay($employee->id, $date);
        $shift = $day ? ($day->shift_id ? Shift::find($day->shift_id) : null) : $employee->getShiftForDate($date);
        $time = fn ($value) => $value ? Carbon::parse($value)->format('H:i:s') : null;

        $lunch = null;
        if ($day && $day->lunch_out && $day->lunch_in) {
            // Rounded as the compute rounds them: lunch out down, lunch in up to the minute.
            $out = Carbon::parse($day->lunch_out)->second(0);
            $in = Carbon::parse($day->lunch_in);
            $in = $in->second > 0 ? $in->copy()->second(0)->addMinute() : $in->copy();
            $lunch = (int) $out->diffInMinutes($in, false);
        }

        $shiftLunch = null;
        if ($shift && $shift->lunch_start && $shift->lunch_end) {
            $shiftLunch = (int) Carbon::parse($date . ' ' . $shift->lunch_start)->diffInMinutes(Carbon::parse($date . ' ' . $shift->lunch_end), false);
        }

        return [
            'has_row'           => $day !== null,
            'time_in'           => $time($day?->time_in),
            'lunch_out'         => $time($day?->lunch_out),
            'lunch_in'          => $time($day?->lunch_in),
            'time_out'          => $time($day?->time_out),
            // A time out after midnight: shown as the next day's, and not "before time in".
            'time_out_next_day' => $day && $day->time_out && Carbon::parse($day->time_out)->toDateString() > $date,
            'shift_id'          => $shift?->id,
            'shift_name'        => $shift?->name,
            'shift_lunch'       => $shiftLunch,
            'shift_required'    => $shift ? (int) $shift->required_work_minutes : null,
            'lunch'             => $lunch,
            'work'              => $day ? (int) $day->computed_work_minutes : null,
            'late'              => $day ? (int) $day->computed_late_minutes : null,
            'undertime'         => $day ? (int) $day->computed_early_minutes : null,
            'overtime'          => $day ? (int) $day->computed_overtime_minutes : null,
            'approved_overtime' => $day && $day->approved_overtime_minutes !== null ? (int) $day->approved_overtime_minutes : null,
            'needs_review'      => $day ? (bool) $day->needs_review : null,
            'notes'             => $day?->notes,
            'rest_day'          => $employee->isDayOff($date),
            'sil'               => $employee->isDateSil($date),
        ];
    }

    /** Punches of the next date before this hour are shown beside the day, never added to it. */
    public const NEXT_DAY_UNTIL = '06:00:00';

    /**
     * The device punches of every entry of a page, read in one query: each date's own
     * punches (the compute's calendar day, 00:00:00 to 23:59:59) and the next date's
     * early ones. One condition per entry keeps the read to exactly those days, through
     * the (employee_id, punched_at) index, however far apart the page's dates are.
     *
     * @param  array<int, array{0: int, 1: string}>  $pairs  [employee id, Y-m-d]
     * @return array<string, array{day: Carbon[], next_day: Carbon[]}>  keyed "employeeId_date"
     */
    public function devicePunches(array $pairs): array
    {
        if ($pairs === []) {
            return [];
        }

        $ranges = [];
        foreach ($pairs as [$employeeId, $date]) {
            $next = Carbon::parse($date)->addDay()->toDateString();
            $ranges[$employeeId . '_' . $date] = [(int) $employeeId, $date, $next, $date . ' 00:00:00', $next . ' ' . self::NEXT_DAY_UNTIL];
        }

        // DISTINCT: older imports stored some punches twice; one punch is shown once.
        $rows = DB::table('attendance_logs')
            ->distinct()
            ->select('employee_id', 'punched_at')
            ->where(function ($q) use ($ranges) {
                foreach ($ranges as [$employeeId, , , $from, $until]) {
                    $q->orWhere(fn ($w) => $w->where('employee_id', $employeeId)
                        ->where('punched_at', '>=', $from)
                        ->where('punched_at', '<', $until));
                }
            })
            ->orderBy('punched_at')
            ->get()
            ->groupBy('employee_id');

        $out = [];
        foreach ($ranges as $key => [$employeeId, $date, $next, $from, $until]) {
            $out[$key] = ['day' => [], 'next_day' => []];
            foreach ($rows->get($employeeId, []) as $row) {
                $at = Carbon::parse($row->punched_at);
                $stamp = $at->format('Y-m-d H:i:s');
                if ($stamp < $from || $stamp >= $until) {
                    continue;
                }
                $out[$key][$at->toDateString() === $date ? 'day' : 'next_day'][] = $at;
            }
        }

        return $out;
    }

    /**
     * The day's punches with the official times each one equals now (to the minute,
     * date included), as the preview's Now column shows them.
     *
     * @param  Carbon[]  $punches
     * @return array<int, array{at: Carbon, labels: string[]}>
     */
    public function labelPunches(array $punches, ?AttendanceDay $day): array
    {
        $official = [];
        foreach (['time_in' => 'Time in', 'lunch_out' => 'Lunch out', 'lunch_in' => 'Lunch in', 'time_out' => 'Time out'] as $field => $label) {
            if ($day && $day->{$field}) {
                $official[$label] = Carbon::parse($day->{$field})->format('Y-m-d H:i');
            }
        }

        return array_map(fn (Carbon $at) => [
            'at'     => $at,
            'labels' => array_keys($official, $at->format('Y-m-d H:i'), true),
        ], $punches);
    }

    /**
     * Plain-word lines for what is still unusual about the day as approved.
     *
     * @return string[]
     */
    public function warnings(array $day): array
    {
        if (!$day['has_row']) {
            return [];
        }

        $warnings = [];
        $hasLunch = $day['shift_lunch'] !== null;
        $off = $day['rest_day'] || $day['sil'];

        $missing = [];
        foreach (['time_in' => 'time in', 'lunch_out' => 'lunch out', 'lunch_in' => 'lunch in', 'time_out' => 'time out'] as $field => $label) {
            if ($day[$field] === null && ($hasLunch || !in_array($field, ['lunch_out', 'lunch_in'], true))) {
                $missing[] = $label;
            }
        }
        if ($missing && !$off) {
            $warnings[] = 'Still missing: ' . implode(', ', $missing) . '.';
        }

        if ($day['lunch'] !== null && $day['lunch'] < 0) {
            $warnings[] = 'Lunch in is before lunch out.';
        } elseif ($day['lunch'] !== null && $hasLunch && $day['lunch'] > $day['shift_lunch']) {
            $warnings[] = 'Lunch is ' . self::minutesText($day['lunch']) . ', longer than the shift\'s ' . self::minutesText($day['shift_lunch']) . '.';
        }

        if ($day['time_in'] !== null && $day['time_out'] !== null && empty($day['time_out_next_day']) && $day['time_out'] < $day['time_in']) {
            $warnings[] = 'Time out is before time in.';
        }

        if (!$off && $day['shift_required'] !== null && $day['work'] < $day['shift_required']) {
            $warnings[] = 'Worked ' . self::minutesText($day['work']) . ', under the shift\'s ' . self::minutesText($day['shift_required']) . '.';
        }

        $uncovered = $day['overtime'] - ($day['approved_overtime'] ?? 0);
        if ($uncovered > 0) {
            $warnings[] = self::minutesText($uncovered) . ' of overtime is not covered by approved overtime.';
        }

        return $warnings;
    }

    /** Minutes as "8 h", "7 h 45 m" or "45 m"; null as a dash. */
    public static function minutesText(?int $minutes): string
    {
        if ($minutes === null) {
            return '–';
        }
        $sign = $minutes < 0 ? '-' : '';
        $minutes = abs($minutes);
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return $sign . match (true) {
            $h > 0 && $m > 0 => "{$h} h {$m} m",
            $h > 0           => "{$h} h",
            default          => "{$m} m",
        };
    }

    /**
     * Make every field of the entry official, in a fixed order, through the same
     * methods the CEO's direct edits call.
     *
     * @return array<int, array{0: string, 1: ?int}> request id => the official row it produced
     */
    protected function apply(Employee $employee, string $date, Collection $rows, User $approver): array
    {
        $applied = [];
        $byField = $rows->keyBy('field');

        if ($row = $byField->get(AttendanceChangeRequest::FIELD_REST_DAY)) {
            $log = $this->changes->changeRestDay(
                $employee, $date, $row->new_value, $row->reason, $approver, str_starts_with($row->origin, 'bulk')
            );
            $applied[$row->id] = ['day_off_log', $log->id];
        }

        $sil = $byField->get(AttendanceChangeRequest::FIELD_SIL);
        if ($sil && $sil->new_value === 'remove') {
            $this->changes->removeSil($employee, $date);
            $applied[$sil->id] = ['sil_removal', null];
        }

        if ($row = $byField->get(AttendanceChangeRequest::FIELD_SHIFT)) {
            // The recompute that follows a shift change is the one of the screen it was asked on.
            [$remarks, $recompute] = $this->shiftRecompute($row->origin);
            $assignment = $this->changes->assignShiftForDate($employee, $date, (int) $row->new_value, $remarks, $recompute);
            $applied[$row->id] = ['shift_assignment', $assignment->id];
        }

        $timeRows = $rows->filter(fn ($row) => $row->isTimeField());
        if ($timeRows->isNotEmpty()) {
            $fields = [];
            foreach ($timeRows as $row) {
                $fields[$row->field] = ['value' => $row->new_value, 'reason' => $row->reason];
            }
            $result = $this->changes->applyTimes($employee, $date, $fields, $approver, $this->dayNote($timeRows->first()->origin));
            foreach ($timeRows as $row) {
                $applied[$row->id] = ['attendance_override', $result['overrides'][$row->field]->id];
            }
        }

        if ($row = $byField->get(AttendanceChangeRequest::FIELD_OVERTIME)) {
            $override = $this->changes->setApprovedOvertime(
                $employee, $date, $row->new_value === null ? null : (int) $row->new_value, $row->reason, $approver
            );
            if (!$override) {
                throw new AttendanceChangeRefused('Cannot approve: No attendance record for this date.', 422, ['short' => 'no attendance record']);
            }
            $applied[$row->id] = ['attendance_override', $override->id];
        }

        if ($sil && $sil->new_value === 'apply') {
            $application = $this->changes->applySil($employee, $date, $sil->reason, $approver);
            $applied[$sil->id] = ['sil_application', $application->id];
        }

        return $applied;
    }

    /** The note a newly created day row gets, as the screen the request came from writes it. */
    protected function dayNote(string $origin): string
    {
        return match (true) {
            $origin === 'fill_from_shift'     => 'Auto-created via Fill from Shift Schedule',
            $origin === 'override_time'       => 'Manually created via attendance calendar',
            $origin === 'attendance_override' => 'Manually created by CEO from absent row.',
            str_starts_with($origin, 'bulk') => 'Auto-created via bulk action',
            default                          => 'Auto-created via Save All',
        };
    }
}
