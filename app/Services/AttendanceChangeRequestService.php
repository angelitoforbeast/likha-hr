<?php

namespace App\Services;

use App\Models\AttendanceChangeRequest;
use App\Models\AttendanceDateLock;
use App\Models\DayOff;
use App\Models\Employee;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Stores what HR staff and admins ask for. Nothing here writes to the
 * attendance tables: a request lives only in attendance_change_requests
 * until the CEO approves it.
 */
class AttendanceChangeRequestService
{
    public function __construct(protected AttendanceChangeService $changes)
    {
    }

    /** True when this user's changes wait for approval. The role is read from the user at every call. */
    public static function mustRequest(?User $user): bool
    {
        return !$user || $user->role !== User::ROLE_CEO;
    }

    /**
     * The official value of a field now, in the form a request stores it.
     */
    public function officialValue(Employee $employee, string $date, string $field): ?string
    {
        if (in_array($field, AttendanceChangeRequest::TIME_FIELDS, true)) {
            $day = $this->changes->findDay($employee->id, $date);

            return $day && $day->{$field} ? Carbon::parse($day->{$field})->format('H:i:s') : null;
        }

        return match ($field) {
            AttendanceChangeRequest::FIELD_OVERTIME => (function () use ($employee, $date) {
                $minutes = $this->changes->findDay($employee->id, $date)?->approved_overtime_minutes;

                return $minutes === null ? null : (string) $minutes;
            })(),
            AttendanceChangeRequest::FIELD_SHIFT => ($shift = $employee->getShiftForDate($date)) ? (string) $shift->id : null,
            AttendanceChangeRequest::FIELD_SIL => $employee->isDateSil($date) ? 'applied' : null,
            AttendanceChangeRequest::FIELD_REST_DAY => DayOff::where('employee_id', $employee->id)->whereDate('off_date', $date)->value('type'),
            default => null,
        };
    }

    /** What the day is now: kept on the request so an approval can tell what changed since. */
    public function dayState(Employee $employee, string $date): array
    {
        return [
            'has_day_row' => $this->changes->findDay($employee->id, $date) !== null,
            'is_rest_day' => $employee->isDayOff($date),
            'is_sil'      => $employee->isDateSil($date),
        ];
    }

    /**
     * What the calendar shows for one day: its pending requests, then the rejected ones
     * that were not asked again. Sent back with every reply that changes a request, so
     * the page shows the new state without being loaded again.
     */
    public function dayItems(Employee $employee, string $date, ?int $viewerId): array
    {
        $shiftNames = \App\Models\Shift::pluck('name', 'id')->all();

        $rows = AttendanceChangeRequest::with(['requester', 'decider'])
            ->where('employee_id', $employee->id)
            ->where('work_date', $date)
            ->where(fn ($q) => $q->where('status', AttendanceChangeRequest::STATUS_PENDING)
                ->orWhere(fn ($r) => $r->where('status', AttendanceChangeRequest::STATUS_REJECTED)->whereNull('superseded_at')))
            ->orderBy('id')
            ->get();

        $items = fn ($list) => $list->map(fn ($row) => $row->toDayItem(
            $row->isPending() ? $this->officialValue($employee, $date, $row->field) : null,
            $shiftNames,
            $viewerId
        ));

        return $items($rows->filter->isPending())->concat($items($rows->reject->isPending()))->values()->all();
    }

    /**
     * Store one save as requests, one per field. All or nothing: a field that
     * another person already asked for refuses the whole save.
     *
     * @param array<string, ?string> $fields field => requested value
     * @return array{rows: AttendanceChangeRequest[], already: bool} already = every field was
     *         pending with the same value, so nothing changed
     */
    public function submit(Employee $employee, string $date, array $fields, string $reason, User $actor, string $origin, ?string $batchId = null): array
    {
        return $this->changes->withLockRetry(function () use ($employee, $date, $fields, $reason, $actor, $origin, $batchId) {
            // Same lock as an approval and an official write take, so a request is
            // never added to a day in the middle of that day being approved.
            $this->changes->lockEmployee($employee);

            $rows = [];
            $changed = false;
            $state = $this->dayState($employee, $date);

            foreach ($fields as $field => $newValue) {
                $existing = $this->pendingRow($employee->id, $date, $field);
                $wasFound = $existing !== null;

                if (!$existing) {
                    try {
                        // A savepoint, so that meeting the unique index leaves this transaction usable.
                        $rows[] = DB::transaction(fn () => AttendanceChangeRequest::create([
                            'employee_id'    => $employee->id,
                            'work_date'      => $date,
                            'field'          => $field,
                            'new_value'      => $newValue,
                            'old_value'      => $this->officialValue($employee, $date, $field),
                            'day_state'      => $state,
                            'reason'         => $reason,
                            'origin'         => $origin,
                            'batch_id'       => $batchId,
                            'requested_by'   => $actor->id,
                            'status'         => AttendanceChangeRequest::STATUS_PENDING,
                            'pending_marker' => 1,
                            'version'        => 1,
                        ]));
                        $this->supersedeRejected($employee->id, $date, $field, end($rows)->id);
                        $changed = true;
                        continue;
                    } catch (QueryException $e) {
                        // After a deadlock the whole transaction is gone: nothing may go on in it.
                        if (AttendanceChangeService::isLockConflict($e)) {
                            throw $e;
                        }
                        // The index refused a second pending row: someone asked at the same instant.
                        $existing = AttendanceChangeRequest::where('employee_id', $employee->id)
                            ->where('work_date', $date)->where('field', $field)
                            ->where('status', AttendanceChangeRequest::STATUS_PENDING)->first();
                        if (!$existing) {
                            throw $e;
                        }
                    }
                }

                if ((int) $existing->requested_by !== (int) $actor->id) {
                    throw new AttendanceChangeRefused(
                        $existing->fieldLabel() . ' for this day is already requested by ' . $existing->requesterName()
                        . ' and is pending approval. Nothing was saved.',
                        409
                    );
                }

                // The same person asking again: the one request is updated, never duplicated.
                if ($wasFound && ($existing->new_value !== $newValue || $existing->reason !== $reason)) {
                    $this->updateRow($existing, $employee, $newValue, $reason);
                    $changed = true;
                }
                $rows[] = $existing;
            }

            return ['rows' => $rows, 'already' => !$changed];
        });
    }

    /** The requester corrects a pending request. Same row, next version. */
    public function change(AttendanceChangeRequest $request, ?string $newValue, string $reason, User $actor): AttendanceChangeRequest
    {
        return $this->changes->withLockRetry(function () use ($request, $newValue, $reason, $actor) {
            $this->changes->lockEmployeeById($request->employee_id);
            $row = AttendanceChangeRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            $this->assertOwnPending($row, $actor);

            $lock = AttendanceDateLock::forDate($row->dateString());
            if ($lock) {
                throw new AttendanceChangeRefused($this->changes->lockMessage($lock), 423);
            }

            $employee = Employee::findOrFail($row->employee_id);
            if ($this->officialValue($employee, $row->dateString(), $row->field) === $newValue
                && !in_array($row->field, [AttendanceChangeRequest::FIELD_SIL, AttendanceChangeRequest::FIELD_REST_DAY], true)) {
                throw new AttendanceChangeRefused('No changes: the value asked for is the official value. The request was kept as it was.', 422);
            }

            $this->updateRow($row, $employee, $newValue, $reason);

            return $row;
        });
    }

    /**
     * The requester takes a pending request back. A second withdrawal is not an error.
     * Whose request it is decides, not the role: someone who asked and was made CEO
     * since may still take their own request back, because nothing becomes official by it.
     */
    public function withdraw(AttendanceChangeRequest $request, User $actor): AttendanceChangeRequest
    {
        return $this->changes->withLockRetry(function () use ($request, $actor) {
            $this->changes->lockEmployeeById($request->employee_id);
            $row = AttendanceChangeRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();

            if ((int) $row->requested_by !== (int) $actor->id) {
                throw new AttendanceChangeRefused('Only the person who asked can withdraw this request.', 403);
            }
            if ($row->status === AttendanceChangeRequest::STATUS_WITHDRAWN) {
                return $row;
            }
            if (!$row->isPending()) {
                throw new AttendanceChangeRefused('This request was already decided (' . $row->status . ').', 409);
            }

            $row->update([
                'status'         => AttendanceChangeRequest::STATUS_WITHDRAWN,
                'pending_marker' => null,
            ]);

            return $row;
        });
    }

    protected function assertOwnPending(AttendanceChangeRequest $row, User $actor): void
    {
        // Also refused for the requester who is CEO by now: a CEO enters a value directly,
        // and a request they keep alive could only be approved by another CEO unseen by them.
        if ((int) $row->requested_by !== (int) $actor->id || !self::mustRequest($actor)) {
            throw new AttendanceChangeRefused('Only the person who asked can change this request.', 403);
        }
        if (!$row->isPending()) {
            throw new AttendanceChangeRefused('This request was already decided (' . $row->status . ').', 409);
        }
    }

    protected function updateRow(AttendanceChangeRequest $row, Employee $employee, ?string $newValue, string $reason): void
    {
        $row->update([
            'new_value'  => $newValue,
            'old_value'  => $this->officialValue($employee, $row->dateString(), $row->field),
            'day_state'  => $this->dayState($employee, $row->dateString()),
            'reason'     => $reason,
            'version'    => $row->version + 1,
            'changed_at' => now(),
        ]);
    }

    /** The pending request of an employee, date and field, locked for this transaction. */
    protected function pendingRow(int $employeeId, string $date, string $field): ?AttendanceChangeRequest
    {
        return AttendanceChangeRequest::where('employee_id', $employeeId)
            ->where('work_date', $date)
            ->where('field', $field)
            ->where('status', AttendanceChangeRequest::STATUS_PENDING)
            ->lockForUpdate()
            ->first();
    }

    /** A new request ends the "Rejected" notice of the earlier one for the same field. */
    protected function supersedeRejected(int $employeeId, string $date, string $field, int $newId): void
    {
        AttendanceChangeRequest::where('employee_id', $employeeId)
            ->where('work_date', $date)
            ->where('field', $field)
            ->where('status', AttendanceChangeRequest::STATUS_REJECTED)
            ->whereNull('superseded_at')
            ->where('id', '<', $newId)
            ->update(['superseded_at' => now()]);
    }
}
