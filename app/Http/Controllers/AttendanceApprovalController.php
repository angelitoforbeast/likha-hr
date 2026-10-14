<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AnswersAttendanceChanges;
use App\Models\AttendanceChangeRequest;
use App\Models\AttendanceDateLock;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\User;
use App\Services\AttendanceApprovalService;
use App\Services\AttendanceChangeRefused;
use App\Services\AttendanceChangeRequestService;
use App\Services\AttendanceChangeService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The Approvals page of the CEO, and the two things a requester may do with a
 * pending request of their own: change it and withdraw it.
 */
class AttendanceApprovalController extends Controller
{
    use AnswersAttendanceChanges;

    protected const PER_PAGE = 50;

    public function __construct(
        protected AttendanceApprovalService $approvals,
        protected AttendanceChangeRequestService $requests,
        protected AttendanceChangeService $changes
    ) {
    }

    /**
     * CEO-only guard, hard-coded on the role and read from the database at this
     * call: no feature permission opens this page to another role.
     */
    protected function assertCeo(): void
    {
        if (!AttendanceApprovalService::isCeoNow(Auth::user())) {
            abort(403, 'Only the CEO can open Approvals.');
        }
    }

    /**
     * The list. One entry per employee and date; pending entries oldest first.
     */
    public function index(Request $request)
    {
        $this->assertCeo();

        $status = in_array($request->input('status'), ['approved', 'rejected', 'withdrawn'], true)
            ? $request->input('status')
            : AttendanceChangeRequest::STATUS_PENDING;
        $isPending = $status === AttendanceChangeRequest::STATUS_PENDING;

        $filters = $this->filters($request);

        $pairs = AttendanceChangeRequest::query()
            ->where('status', $status)
            ->when($filters['employee_id'], fn ($q, $id) => $q->where('employee_id', $id))
            ->when($filters['requester_id'], fn ($q, $id) => $q->where('requested_by', $id))
            ->when($filters['date_from'], fn ($q, $date) => $q->where('work_date', '>=', $date))
            ->when($filters['date_to'], fn ($q, $date) => $q->where('work_date', '<=', $date))
            ->when($filters['kind'], fn ($q, $kind) => $q->whereIn('field', self::KIND_FIELDS[$kind]))
            ->select('employee_id', 'work_date', DB::raw('MIN(id) as first_id'), DB::raw('MAX(id) as last_id'))
            ->groupBy('employee_id', 'work_date')
            ->orderBy($isPending ? 'first_id' : 'last_id', $isPending ? 'asc' : 'desc')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $entries = $this->buildEntries($pairs->getCollection(), $status);
        $shiftNames = Shift::pluck('name', 'id')->all();

        // Only people and employees that have requests are offered in the filters.
        $filterEmployees = Employee::whereIn('id', AttendanceChangeRequest::select('employee_id')->distinct())
            ->orderBy('full_name')->get();
        $filterRequesters = User::whereIn('id', AttendanceChangeRequest::select('requested_by')->whereNotNull('requested_by')->distinct())
            ->orderBy('name')->get();

        return response()
            ->view('approvals.index', compact(
                'entries', 'pairs', 'status', 'isPending', 'shiftNames', 'filters', 'filterEmployees', 'filterRequesters'
            ))
            // A decision is taken on what this page shows: never serve it from a cache.
            ->header('Cache-Control', 'no-store, private');
    }

    protected const KIND_FIELDS = [
        'times'    => AttendanceChangeRequest::TIME_FIELDS,
        'overtime' => [AttendanceChangeRequest::FIELD_OVERTIME],
        'shift'    => [AttendanceChangeRequest::FIELD_SHIFT],
        'sil'      => [AttendanceChangeRequest::FIELD_SIL],
        'rest_day' => [AttendanceChangeRequest::FIELD_REST_DAY],
    ];

    /** The list filters, each null unless it holds a usable value. */
    protected function filters(Request $request): array
    {
        $date = function ($value) {
            try {
                return is_string($value) && $value !== '' ? Carbon::parse($value)->toDateString() : null;
            } catch (\Throwable $e) {
                return null;
            }
        };

        return [
            'employee_id'  => ctype_digit((string) $request->input('employee_id')) ? (int) $request->input('employee_id') : null,
            'requester_id' => ctype_digit((string) $request->input('requester_id')) ? (int) $request->input('requester_id') : null,
            'date_from'    => $date($request->input('date_from')),
            'date_to'      => $date($request->input('date_to')),
            'kind'         => array_key_exists((string) $request->input('kind'), self::KIND_FIELDS) ? $request->input('kind') : null,
        ];
    }

    /**
     * The entries of one page: their rows and, for pending ones, what the CEO
     * needs to decide (official values now, what changed, what blocks).
     */
    protected function buildEntries($pairs, string $status): array
    {
        if ($pairs->isEmpty()) {
            return [];
        }

        $rows = AttendanceChangeRequest::with(['requester', 'decider'])
            ->where('status', $status)
            ->whereIn('employee_id', $pairs->pluck('employee_id')->unique())
            ->whereIn('work_date', $pairs->pluck('work_date')->map(fn ($d) => substr((string) $d, 0, 10))->unique())
            ->orderBy('id')
            ->get()
            ->groupBy(fn ($row) => $row->employee_id . '_' . $row->dateString());

        $employees = Employee::with('department')->whereIn('id', $pairs->pluck('employee_id')->unique())->get()->keyBy('id');
        $dates = $pairs->pluck('work_date')->map(fn ($d) => substr((string) $d, 0, 10));
        $locks = AttendanceDateLock::whereDate('lock_date', '>=', $dates->min())
            ->whereDate('lock_date', '<=', $dates->max())
            ->get()
            ->keyBy(fn ($lock) => $lock->lock_date->format('Y-m-d'));

        $entries = [];
        foreach ($pairs as $pair) {
            $date = substr((string) $pair->work_date, 0, 10);
            $entryRows = $rows->get($pair->employee_id . '_' . $date, collect());
            $employee = $employees->get($pair->employee_id);
            if ($entryRows->isEmpty() || !$employee) {
                continue;
            }

            $entry = [
                'employee' => $employee,
                'date'     => $date,
                'rows'     => $entryRows,
                'anchor'   => $entryRows->first()->id,
                'inactive' => $employee->status !== 'active',
                'locked'   => $locks->has($date),
                'batch'    => $entryRows->pluck('batch_id')->unique()->count() === 1 ? $entryRows->first()->batch_id : null,
            ];

            if ($status === AttendanceChangeRequest::STATUS_PENDING) {
                $entry['seen']     = AttendanceApprovalService::seenToken($entryRows);
                $entry['official'] = $entryRows->mapWithKeys(fn ($row) => [
                    $row->id => $this->requests->officialValue($employee, $date, $row->field),
                ])->all();
                $entry['drift']  = $this->approvals->drift($employee, $date, $entryRows);
                $entry['blocks'] = $this->approvals->blocks($employee, $date, $entryRows);
                $entry['day']    = $this->changes->findDay($employee->id, $date);
            }

            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * The id in the address is looked up only after the role check, here and in reject():
     * for anyone but the CEO the answer is 403 whether the id exists or not.
     */
    public function approve(Request $request, string $changeRequest)
    {
        $this->assertCeo();
        $changeRequest = AttendanceChangeRequest::findOrFail((int) $changeRequest);
        $validated = $request->validate([
            'seen'      => 'required|string|max:2000',
            'confirmed' => 'nullable|boolean',
        ]);

        try {
            $this->approvals->approve($changeRequest, $validated['seen'], (bool) ($validated['confirmed'] ?? false), Auth::user());
        } catch (AttendanceChangeRefused $e) {
            return $this->decisionRefused($request, $e);
        } catch (\Throwable $e) {
            return $this->decisionFailed($request, $e, $changeRequest);
        }

        return $this->decisionDone($request, 'Approved.');
    }

    public function reject(Request $request, string $changeRequest)
    {
        $this->assertCeo();
        $changeRequest = AttendanceChangeRequest::findOrFail((int) $changeRequest);
        $validated = $request->validate([
            'seen'   => 'required|string|max:2000',
            'reason' => 'required|string|min:3|max:500',
        ]);

        try {
            $this->approvals->reject($changeRequest, $validated['seen'], $validated['reason'], Auth::user());
        } catch (AttendanceChangeRefused $e) {
            return $this->decisionRefused($request, $e);
        }

        return $this->decisionDone($request, 'Rejected.');
    }

    /**
     * Approve the entries selected on the page. Each one is its own transaction
     * with what the CEO saw of it: one that cannot be approved stays pending with
     * its reason named, and the others are applied.
     */
    public function approveSeveral(Request $request)
    {
        $this->assertCeo();
        $validated = $request->validate([
            'entries'        => 'required|array|min:1|max:200',
            'entries.*.id'   => 'nullable|integer',
            'entries.*.seen' => 'nullable|string|max:2000',
        ]);

        $outcome = $this->decideSeveral($validated['entries'], fn ($anchor, $seen) => $this->approvals->approve($anchor, $seen, false, Auth::user()));

        return $this->severalDone($request, $outcome, 'approved');
    }

    public function rejectSeveral(Request $request)
    {
        $this->assertCeo();
        $validated = $request->validate([
            'entries'        => 'required|array|min:1|max:200',
            'entries.*.id'   => 'nullable|integer',
            'entries.*.seen' => 'nullable|string|max:2000',
            'reason'         => 'required|string|min:3|max:500',
        ]);

        $outcome = $this->decideSeveral($validated['entries'], fn ($anchor, $seen) => $this->approvals->reject($anchor, $seen, $validated['reason'], Auth::user()));

        return $this->severalDone($request, $outcome, 'rejected');
    }

    /**
     * Approve the entries of one bulk action that the page shows. Each is decided on its
     * own, as with "approve selected"; entries that are not part of the batch are left out.
     */
    public function approveBatch(Request $request, string $batch)
    {
        $this->assertCeo();
        $validated = $request->validate([
            'entries'        => 'required|array|min:1|max:200',
            'entries.*.id'   => 'nullable|integer',
            'entries.*.seen' => 'nullable|string|max:2000',
        ]);

        $entries = $this->entriesOfBatch($validated['entries'], $batch);
        $outcome = $this->decideSeveral($entries, fn ($anchor, $seen) => $this->approvals->approve($anchor, $seen, false, Auth::user()));

        return $this->severalDone($request, $outcome, 'approved');
    }

    public function rejectBatch(Request $request, string $batch)
    {
        $this->assertCeo();
        $validated = $request->validate([
            'entries'        => 'required|array|min:1|max:200',
            'entries.*.id'   => 'nullable|integer',
            'entries.*.seen' => 'nullable|string|max:2000',
            'reason'         => 'required|string|min:3|max:500',
        ]);

        $entries = $this->entriesOfBatch($validated['entries'], $batch);
        $outcome = $this->decideSeveral($entries, fn ($anchor, $seen) => $this->approvals->reject($anchor, $seen, $validated['reason'], Auth::user()));

        return $this->severalDone($request, $outcome, 'rejected');
    }

    /** Of the posted entries, those whose employee and date hold a pending request of the batch. */
    protected function entriesOfBatch(array $entries, string $batch): array
    {
        $inBatch = AttendanceChangeRequest::where('batch_id', $batch)
            ->where('status', AttendanceChangeRequest::STATUS_PENDING)
            ->get()
            ->mapWithKeys(fn ($row) => [$row->employee_id . '_' . $row->dateString() => true]);
        if ($inBatch->isEmpty()) {
            abort(404);
        }

        $anchors = AttendanceChangeRequest::whereIn('id', array_filter(array_column($entries, 'id')))->get()->keyBy('id');

        return array_values(array_filter($entries, function ($entry) use ($anchors, $inBatch) {
            $anchor = $anchors->get($entry['id'] ?? 0);

            return $anchor && $inBatch->has($anchor->employee_id . '_' . $anchor->dateString());
        }));
    }

    /**
     * @param array<int, array{id?: ?int, seen?: ?string}> $entries
     * @return array{done: int, failed: array<string, int>}
     */
    protected function decideSeveral(array $entries, callable $decide): array
    {
        $done = 0;
        $failed = [];  // short reason => count
        $results = []; // per posted entry, for the page: which left the list and why one stayed

        foreach ($entries as $entry) {
            if (empty($entry['id'])) {
                continue; // a row of the page that was not ticked
            }
            $anchor = AttendanceChangeRequest::find($entry['id']);
            try {
                if (!$anchor) {
                    throw new AttendanceChangeRefused('Not found.', 404, ['short' => 'not found']);
                }
                $decide($anchor, (string) ($entry['seen'] ?? ''));
                $done++;
                $results[] = ['id' => (int) $entry['id'], 'ok' => true];
            } catch (AttendanceChangeRefused $e) {
                $short = $e->details['short'] ?? $e->getMessage();
                $failed[$short] = ($failed[$short] ?? 0) + 1;
                $results[] = ['id' => (int) $entry['id'], 'ok' => false, 'message' => $e->getMessage()];
            } catch (\Throwable $e) {
                Log::error('Approving an attendance request failed: ' . get_class($e), ['request_id' => $entry['id']]);
                $failed['error'] = ($failed['error'] ?? 0) + 1;
                $results[] = ['id' => (int) $entry['id'], 'ok' => false, 'message' => 'It failed and nothing was changed.'];
            }
        }

        return ['done' => $done, 'failed' => $failed, 'results' => $results];
    }

    protected function severalDone(Request $request, array $outcome, string $verb)
    {
        $parts = [$outcome['done'] . ' ' . $verb];
        foreach ($outcome['failed'] as $short => $count) {
            $parts[] = $count . ' not ' . $verb . ': ' . $short;
        }
        $message = implode(', ', $parts);

        if ($request->expectsJson()) {
            return response()->json([
                'success'         => true,
                'message'         => $message,
                'pending_entries' => AttendanceChangeRequest::pendingEntryCount(),
            ] + $outcome);
        }

        return back()->with(empty($outcome['failed']) ? 'success' : 'warning', $message);
    }

    /**
     * The requester corrects a pending request of their own.
     */
    public function change(Request $request, AttendanceChangeRequest $changeRequest)
    {
        // A SIL or rest-day request holds an action, not a value that can be corrected.
        if (in_array($changeRequest->field, [AttendanceChangeRequest::FIELD_SIL, AttendanceChangeRequest::FIELD_REST_DAY], true)) {
            return response()->json([
                'success' => false,
                'message' => 'This kind of request cannot be changed. Withdraw it and ask again.',
            ], 422);
        }

        $rules = ['reason' => 'required|string|min:3|max:500'];
        $rules['new_value'] = match (true) {
            $changeRequest->isTimeField() => ['nullable', 'string', $this->timeRule()],
            $changeRequest->field === AttendanceChangeRequest::FIELD_OVERTIME => 'nullable|numeric|min:0|max:24',
            default => 'required|integer|exists:shifts,id',
        };
        $validated = $request->validate($rules);

        $value = $validated['new_value'] ?? null;
        if ($value !== null && $value !== '') {
            $value = match (true) {
                $changeRequest->isTimeField() => AttendanceChangeService::normalizeTime($value),
                $changeRequest->field === AttendanceChangeRequest::FIELD_OVERTIME => (string) AttendanceChangeService::overtimeMinutes($value),
                default => (string) (int) $value,
            };
        } else {
            $value = null;
        }

        try {
            $row = $this->requests->change($changeRequest, $value, $validated['reason'], Auth::user());
        } catch (AttendanceChangeRefused $e) {
            return $this->refusedResponse($e);
        }

        return response()->json([
            'success' => true, 'pending' => true, 'message' => 'Request changed. Pending approval.', 'id' => $row->id,
        ] + $this->dayRequests($row));
    }

    /**
     * The requester takes a pending request of their own back.
     */
    public function withdraw(AttendanceChangeRequest $changeRequest)
    {
        try {
            $row = $this->requests->withdraw($changeRequest, Auth::user());
        } catch (AttendanceChangeRefused $e) {
            return $this->refusedResponse($e);
        }

        return response()->json(['success' => true, 'message' => 'Request withdrawn.'] + $this->dayRequests($row));
    }

    protected function decisionDone(Request $request, string $message)
    {
        return $request->expectsJson()
            // The count of entries still waiting, for the nav badge of the page that asked.
            ? response()->json(['success' => true, 'message' => $message, 'pending_entries' => AttendanceChangeRequest::pendingEntryCount()])
            : back()->with('success', $message);
    }

    protected function decisionRefused(Request $request, AttendanceChangeRefused $e)
    {
        return $request->expectsJson()
            ? $this->refusedResponse($e)
            : back()->with('error', $e->getMessage());
    }

    /** Anything unexpected: the transaction has rolled back, the entry is still pending. */
    protected function decisionFailed(Request $request, \Throwable $e, AttendanceChangeRequest $changeRequest)
    {
        Log::error('Approving an attendance request failed: ' . get_class($e), ['request_id' => $changeRequest->id]);
        $message = 'The approval failed and nothing was changed. The request is still pending.';

        return $request->expectsJson()
            ? response()->json(['success' => false, 'message' => $message], 500)
            : back()->with('error', $message);
    }
}
