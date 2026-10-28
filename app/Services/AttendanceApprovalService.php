<?php

namespace App\Services;

use App\Models\AttendanceChangeRequest;
use App\Models\AttendanceDateLock;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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

    /** The pending rows of an employee and date, oldest first. */
    public function entryRows(int $employeeId, string $date, bool $lock = false): Collection
    {
        return AttendanceChangeRequest::with('requester')
            ->where('employee_id', $employeeId)
            ->where('work_date', $date)
            ->where('status', AttendanceChangeRequest::STATUS_PENDING)
            ->orderBy('id')
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->get();
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
     * @return Collection the approved rows
     */
    public function approve(AttendanceChangeRequest $anchor, string $seen, bool $confirmed, User $approver): Collection
    {
        return $this->changes->withLockRetry(function () use ($anchor, $seen, $confirmed, $approver) {
            $date = $anchor->dateString();

            // The lock every official write takes: this approval and a direct edit of
            // the same employee now run one after the other. It is the first statement
            // of the transaction on purpose: every read after it then sees what the
            // writer before us committed, not the data as it was before the wait.
            $employee = $this->changes->lockEmployeeById($anchor->employee_id);
            $rows = $this->entryRows($employee->id, $date, true);

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

            $applied = $this->apply($employee, $date, $rows, $approver);

            // Once per entry, after every field is in: the writes above recompute only the
            // minutes, so the day would keep the "needs review" and note of the punches it
            // had before. Inside this transaction, so a failed recheck undoes the approval.
            $this->changes->recheckDay($employee, $date);

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

            return $rows;
        });
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
            $rows = $this->entryRows($anchor->employee_id, $anchor->dateString(), true);

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
            [$remarks, $recompute] = match ($row->origin) {
                'save_all'            => ['Set via attendance calendar (Save All)', AttendanceChangeService::RECOMPUTE_DAY],
                'attendance_override' => ['Set via attendance page', AttendanceChangeService::RECOMPUTE_DAY],
                default               => ['Set via attendance calendar', AttendanceChangeService::RECOMPUTE_PUNCHES],
            };
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
