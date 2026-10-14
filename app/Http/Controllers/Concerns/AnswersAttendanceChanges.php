<?php

namespace App\Http\Controllers\Concerns;

use App\Models\AttendanceChangeRequest;
use App\Models\Employee;
use App\Services\AttendanceChangeRefused;
use App\Services\AttendanceChangeRequestService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\JsonResponse;

/**
 * Replies shared by the screens that change attendance: the reply to a change
 * that was stored as a request, and the reply to a refusal.
 */
trait AnswersAttendanceChanges
{
    /** A time typed by a person: HH:MM or HH:MM:SS on a 24-hour clock. */
    protected function timeRule(): string
    {
        return 'regex:/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/';
    }

    /**
     * @param array{rows: array, already: bool} $result what the request store returned
     */
    protected function pendingResponse(array $result, array $extra = []): JsonResponse
    {
        return response()->json(array_merge([
            'success'          => true,
            'pending'          => true,
            'message'          => $result['already'] ? 'Already pending approval.' : 'Sent for approval.',
            'requested_fields' => array_map(fn ($row) => $row->field, $result['rows']),
        ], $this->dayRequests($result['rows'][0] ?? null), $extra));
    }

    /**
     * The requests of the day a request belongs to, as the calendar shows them, for the
     * page to put on the cell and in the modal at once.
     */
    protected function dayRequests(?AttendanceChangeRequest $row): array
    {
        if (!$row) {
            return [];
        }
        $employee = Employee::find($row->employee_id);

        return $employee ? [
            'day_requests' => app(AttendanceChangeRequestService::class)->dayItems($employee, $row->dateString(), Auth::id()),
        ] : [];
    }

    protected function refusedResponse(AttendanceChangeRefused $e): JsonResponse
    {
        return response()->json(array_merge([
            'success' => false,
            'message' => $e->getMessage(),
        ], $e->details), $e->status);
    }
}
