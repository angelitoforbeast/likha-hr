# Plan 007: one approval per shift change, requests that change nothing close themselves

Includes amendment 007-1 (story .10: the profile shift assignment by a user who must request
becomes one pending approval for its whole range).

## Findings that shape the plan
- No code path creates a shift request with a `batch_id`: the calendar bulk action
  (`AttendanceCalendarController::bulkAction`) only asks for rest days and times. Shift requests
  come from single-date calls (`assignShiftForDate` origin `assign_shift`, `saveAll` origin
  `save_all`, the old Attendance page origin `attendance_override`), one request per call.
  Twelve requests by one person in one minute are twelve single-date calls without a batch id,
  so grouping must also work by "same minute".
- The profile shift assignment (`EmployeeController::assignShift`/`updateShift`/delete) writes
  `employee_shift_assignments` directly and recomputes no day: days computed before keep the
  minutes of the old shift. That is why "official now" can already equal a request.
- No scheduler runs in this app (`Console\Kernel::schedule` is empty).
- `status` is a `string(20)`: a new value needs no migration. The assignment request needs its
  range (from, until, the assignment it edits or removes), which no column holds.

## Design
1. Model: status `no_longer_needed`; field `shift_assignment` (kind `assignment`); a nullable
   `details` JSON column (the one additive migration) for the assignment request's range.
2. `ApprovalCards::build(rows)`: one pure function that turns pending rows into cards. A day
   whose only pending row is a shift request joins a group keyed by employee, shift asked,
   requester, and batch id or (no batch) the minute it was asked. Groups of 2+ days are one
   card; every other day is today's per-day card; an assignment request is its own card.
   Used by the page (list + paging), the sidebar badge and the JSON counts.
3. Page: the pending tab lists cards oldest first, paged by at most 50 days of previews per
   page. The shift card shows dates as ranges, totals of worked/late/undertime now vs if
   approved, and "See each day" with each day's punches, preview and its own Approve/Reject.
4. Group approve/reject: `POST /approvals/shift-group/{approve|reject}` with the key and the
   days shown. The server recomputes every posted day's key (same employee, shift field, same
   value, pending, same requester, same batch/minute) and refuses the whole post when one
   differs; then each day goes through the existing `approve()`/`reject()` one by one, so the
   computation, change log and date-lock rules are the ones of today. Locked or blocked days
   stay pending and are named on the card.
5. Auto-close: `AttendanceApprovalService::closeUnneeded()` closes pending time, overtime, shift
   and SIL requests whose asked value equals the official value (never on a locked date), under
   the employee lock, compare-and-set on the version. Runs when the Approvals page loads and
   inside `approve()` (a group or a day whose value is already official). A closed shift
   request whose day row was computed with another shift is recomputed through the same path
   the approval of that request would run (the recompute of `assignShiftForDate`, then the
   recheck), without writing a shift assignment.
6. Story .10: `EmployeeShiftAssignments` service holds today's profile logic (auto-close of the
   previous open assignment, overlap check, schedule mode to Manual; update; delete). The CEO
   path calls it directly as today; for a user who must request, the controller stores one
   request (reason required) instead. Approve applies it with the same service, then recomputes
   the computed days in the affected range except locked dates; reject changes nothing. The
   card previews the affected days in one dry run. The profile page lists the pending request.
7. Assignment requests are kept out of every per-day reading (calendar markers, day items,
   per-day entries).

## Tests first (tests/Feature/Approvals/ShiftGroupsTest.php, AutoCloseTest.php, AssignmentRequestTest.php)
Grouping keys; approve-all equals one-by-one (rows, change log, payroll); reject-all; a locked
day inside a group; select-all with a card; badge counts cards; other kinds stay per day;
auto-close on load and on act; partial group; recompute of closed days; tampered group posts;
.10 cases from the amendment. Then the full suite against the baseline (290 tests, all green).

## Evidence
Rendered Approvals page (grouped card, assignment card, "No longer needed" tab) saved under
`docs/specs/007-evidence/`.
