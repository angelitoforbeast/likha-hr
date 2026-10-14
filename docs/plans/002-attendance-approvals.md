# Plan: spec 002 The CEO approves attendance changes made by HR staff and admins

Status: plan only. No product code, test or migration is written until the reviewer says "go".
Base: `main` at `ac3534a`. Branch: `feat/002-attendance-approvals`.
Nothing was run in this plan run except reads of the code. `vendor/` is missing in this worktree, so
`composer install` and the base run of the suite are the first step of stage 0.

## 1. What the code does today (verified at `ac3534a`)

The spec's line numbers hold. What the plan depends on, and what the spec does not mention:

**Calendar (`AttendanceCalendarController`)**

- The four CEO-only refusals are at `createDay` 363, `saveAll` 512, `fillFromShift` 739 and
  `ensureAttendanceDayForBulk` 1005. Everything else has no role check.
- `saveAll` writes the `employee_shift_assignments` row (482-501) before it reaches the refusal at
  512, so a refused save by HR staff has already changed the shift (S-05.11).
- No entry point uses a database transaction. Each writes the day row, then one override row per
  field, then recomputes.
- The entry points do not write the same way:

  | Entry point | Skips a field whose value is unchanged | `new_value` in the override row | `old_value` | Recompute |
  |---|---|---|---|---|
  | `saveAll` times | yes | `H:i:s` | `H:i:s` | `recomputeDay`, once |
  | `saveAll` shift | yes (compared with `getShiftForDate`) | none (an assignment row, no override row) | — | `recomputeDay`, only if a day row exists |
  | `saveAll` approved overtime | yes | minutes as text, null = automatic | minutes | none |
  | `overrideTime` | no | as typed (`08:00`) | `H:i:s` | `recomputeDay` |
  | `fillFromShift`, bulk fill | no (always four rows) | `H:i:s` | `H:i:s` | `recomputeDay` |
  | bulk set times | no | as typed | `H:i:s` | `recomputeDay` |
  | `assignShiftForDate` | no | assignment row | — | `autoComputeSingleDay` (from punches, override-aware) |
  | `toggleDayOff`, bulk day off | no | `day_offs` row + `day_off_logs` row | — | `autoComputeSingleDay` |
  | `applySil` / `removeSil` | — | `sil_applications` row created / deleted; removal takes no reason | — | none |

- `autoComputeSingleDay` only acts when punches exist for that day, and swallows every error. So a
  direct rest-day change on a day with no punches creates no day row.
- A time such as `25:61` reaches `Carbon::parse` and ends as an error 500, not a 422 (S-05.10).
- `overrideTime` needs an `attendance_day_id`. For a day with no row the modal first calls
  `createDay` (`ensureAttendanceDay()` in the view, about 1960), which writes a blank day row, and
  only then posts the time. That blank row is what payroll would count as a worked day.
- The calendar lists only active employees with at least one day row in the visible range.
- The calendar view also posts to the older `/attendance/override` (its `editTimeModal`, about 2302).

**Older Attendance page (`AttendanceController`)**

- `override` (327): no role check, no lock check, no transaction. For `shift_id` it sets
  `attendance_days.shift_id` and writes an override row with field `shift_id`, then calls
  `recomputeDay`, which sets `shift_id` back to the employee's effective shift whenever that differs
  (`AttendanceComputeService::recomputeDay` 477-479). So today the older page's shift change undoes
  itself and leaves only the override row. See question 2.
- `createDay` (379): CEO-only, no lock check; it takes the employee's default shift, not the shift
  for the date.
- The page shows the edit cells of an absent row to the CEO only (view, about 274).

**Other ways to change a day that the spec does not name** (see question 1)

- `DayOffCalendarController::toggle` (`/day-off-calendar/toggle`): adds, cancels or removes a rest
  day for any role with the nav permission. No lock check, no `day_off_logs` row, no reason.
- `EmployeeController::addDayOff`, `updateDayOff`, `deleteDayOff`: the same on the Employee page.
- `EmployeeController::addSilApplication` / `deleteSilApplication` are already CEO-only.
- Schedule-level edits (weekly rest-day patterns, shift assignments over a range, the employee bulk
  action) change many dates at once. They are not "a change to a day" and stay out of this plan.

**Payroll**

- `PayrollController::finalize` has no role check and no screen of its own: the "finalize screen" is
  the payroll run page (`payroll/show.blade.php`) with a Finalize button and a browser confirm.
- `PayrollService` reads `attendance_days`, `sil_applications`, `day_offs` (through `isDayOff`),
  `employee_shift_assignments` (through `getShiftForDate`) and nothing else that this spec touches.
  No change to it or to `AttendanceComputeService` is needed.

**Schema facts used below**

- `attendance_overrides.updated_by` is one NOT NULL user column; `day_off_logs.updated_by` and
  `sil_applications.applied_by` likewise hold one user. None can name a requester and an approver
  (question 3).
- `attendance_days` is unique on (`employee_id`, `work_date`); `sil_applications` on (`employee_id`,
  `sil_date`); `day_offs` on (`employee_id`, `off_date`).
- The app timezone is `Asia/Manila`, so "asked at" needs no conversion (S-09.8).

## 2. Where pending requests are stored

One new table, `attendance_change_requests`, one row per employee, date and field. An "entry" of the
Approvals page is not stored: it is the set of pending rows of one employee and date.

| Column | Type | Meaning |
|---|---|---|
| `id` | bigint | |
| `employee_id` | FK employees, cascade | |
| `work_date` | date | the day asked for, held as `Y-m-d` (the model does not cast it, so sqlite and MySQL hold the same text and the unique rule behaves the same on both) |
| `field` | string(40) | `time_in`, `lunch_out`, `lunch_in`, `time_out`, `approved_overtime_minutes`, `shift_id`, `sil`, `rest_day` |
| `new_value` | string(40), nullable | time `H:i:s`; minutes; shift id; `apply` / `remove` for SIL; `add_day_off` / `cancel_day_off` / `remove_override` for a rest day. Null means "clear" for a time and "automatic" for overtime |
| `old_value` | string(40), nullable | the official value the request saw, taken again when the requester changes the request |
| `day_state` | json | what the day was when asked: `has_day_row`, `is_rest_day`, `is_sil` |
| `reason` | text | the requester's reason (3 to 500) |
| `origin` | string(40) | the entry point: `save_all`, `override_time`, `fill_from_shift`, `assign_shift`, `apply_sil`, `remove_sil`, `toggle_day_off`, `bulk_*`, `attendance_override` |
| `batch_id` | char(36), nullable, indexed | set for requests made by one bulk action |
| `requested_by` | FK users, nullable, null on delete | always the signed-in user, never from the request body |
| `status` | string(20) | `pending`, `approved`, `rejected`, `withdrawn` |
| `pending_marker` | tinyint, nullable | 1 while pending, NULL as soon as the request is decided or withdrawn |
| `version` | unsigned int, default 1 | +1 on every change by the requester |
| `changed_at` | timestamp, nullable | last change by the requester |
| `decided_by` | FK users, nullable | approver or rejecter |
| `decided_at` | timestamp, nullable | |
| `decision_reason` | text, nullable | the CEO's rejection reason |
| `superseded_at` | timestamp, nullable | set on a rejected row when a newer request for the same employee, date and field is made; ends the "Rejected:" notice |
| `applied_type`, `applied_id` | string(40) / bigint, nullable | the official row an approval wrote (`attendance_override`, `day_off_log`, `sil_application`, `shift_assignment`), so the history can say who asked and who approved |
| timestamps | | `created_at` is "asked at" |

**The unique rule.** `UNIQUE (employee_id, work_date, field, pending_marker)`. MySQL and sqlite both
treat NULL as distinct in a unique index, so any number of decided rows may exist for one employee,
date and field, and at most one row with `pending_marker = 1`. Two inserts at the same instant: the
database refuses the second. The code catches that one error, reads the row that won and answers
"already pending" (the same requester) or the refusal naming who asked (another requester). The
check before the insert stays, for the readable message; the index is the guard.

Other indexes: (`status`, `work_date`), (`employee_id`, `work_date`), (`requested_by`, `status`).

**Migration.** One additive file, `2026_10_14_000001_create_attendance_change_requests_table.php`:
`up` creates the table, `down` drops it. No existing table is altered. It runs on sqlite (tests) and
MySQL with the same statements; no driver guard.

## 3. The one write path per kind of change

New `App\Services\AttendanceChangeService` (the code extracted from the two controllers). Its
constructor takes `AttendanceComputeService` from the container, so a test can bind a compute double
(S-11.6) the way Slice 001 did. Every method runs inside a transaction of its own (joined into the
approval's transaction when called from there) and starts by locking the employee row
(`lockForUpdate`), which makes "create the day row", "check the SIL balance" and "write" one step
per employee (S-06.6, S-16.6). Every method takes the acting user and the reason as arguments;
nothing inside reads `Auth` or the request.

| Method | What it holds | Callers that move onto it |
|---|---|---|
| `applyTimes(Employee, date, array $fields, User $actor, array $opts)` — `$fields` is field => [`value` `H:i:s` or null, `reason`]; `$opts['day_note']` is the note for a newly created row | find the day row (`whereDate`) or create it with the shift for the date; for each given field set the value, write one override row (`old_value` and `new_value` as `H:i:s`, `updated_by` = actor); save; `recomputeDay` once. If creating the row hits the unique key, read the row that exists instead | `saveAll` times (CEO), `overrideTime` (CEO), `fillFromShift` (CEO), `bulkApplyFillFromShift`, `bulkApplySetTimes`, `AttendanceController::override` time fields (CEO), and the approval. `ensureAttendanceDayForBulk` is removed |
| `setApprovedOvertime(Employee, date, ?int $minutes, reason, actor)` | needs a day row (as today); sets the minutes or null, writes the override row for `approved_overtime_minutes`, no recompute (as today) | `saveAll` overtime (CEO), the approval |
| `assignShiftForDate(Employee, date, shiftId, remarks, actor, string $recompute)` — `$recompute` is `day` or `punches` | delete the single-day assignment of that date, create the new one, then recompute the way the entry point does today | `assignShiftForDate` (`punches`), `saveAll` shift (`day`), the older page's shift change if question 2 is answered yes, the approval (the flavour of the request's `origin`) |
| `applySil(Employee, date, reason, actor)` / `removeSil(Employee, date, actor)` | the three existing checks (eligible, balance, not already applied) with their existing messages, under the employee lock; create or delete the `sil_applications` row | `applySil` and `removeSil` (CEO), the approval |
| `changeRestDay(Employee, date, action, reason, actor, remarks)` | the `day_offs` upsert or delete, one `day_off_logs` row (`updated_by` = actor), `autoComputeSingleDay` | `toggleDayOff`, `bulkApplyDayOffAction` (both for the CEO, and for HR staff and admins when there is no day row), the approval |
| `createBlankDay(Employee, date, note)` | today's `createDay` body | both `createDay` methods, CEO only |

Which fields a save holds is decided before the write path, by one resolver per entry point
(`saveAll`: only the fields that differ; `fillFromShift`: all four; `overrideTime`: the one field).
The same resolver output goes either to the write path (CEO) or to the request store (HR staff,
admin). An approval hands the stored fields to the same methods with the approver as actor, the
requester's reason and the stored `origin`, so the rows, the log, the recompute and the rounding
cannot differ. A mutation test swaps one path and must turn a test red.

Visible differences for the CEO's own edits, kept as small as the single path allows:

- Override rows written by `overrideTime`, bulk set times and the older page now hold `H:i:s` in
  `new_value` (and the older page in `old_value`), where today they hold what was typed (`08:00`).
  Shown only in the edit history text.
- Each CEO edit becomes all-or-nothing (a transaction), where today a failure in the recompute
  leaves the override row written.
- A time that is not a time is refused with 422 for every role, where today it is an error 500.

## 4. Direct or request: how each entry point decides

One helper, `mustRequest(User)`: true when the role read from the signed-in user at this call is not
`ceo`. Never from the page, never from the body. Order in every entry point: validate (422), lock
check on the work date (423), resolve the fields, then:

| Entry point | CEO | HR staff, admin |
|---|---|---|
| `saveAll` | write path: shift, times, overtime, as today | nothing official is written, not even the shift row. No field differs: "No changes to save." Otherwise one request per changed field (times, `shift_id`, overtime). Overtime on a day with no row is ignored, as today |
| `overrideTime` | write path | one request. Now also accepts `employee_id` + `date` in place of `attendance_day_id`, so a day with no row needs no blank row. The same value as the official one: "No changes to save." |
| `createDay` (calendar and older page) | blank row as today; the older page now checks the lock | 200, `attendance_day_id: null`, `pending: true`, nothing written, no request (S-05.4, S-20.5). The page then posts the time with employee and date |
| `fillFromShift` | write path | four requests holding the shift's times as they are now. No shift: 422 as today |
| `assignShiftForDate` | write path | one `shift_id` request; the same shift as now: "No changes to save." |
| `applySil` | write path | the three checks first (422 with the existing messages), then one `sil` = `apply` request |
| `removeSil` | write path | no SIL on the date: today's message, no request. Otherwise one `sil` = `remove` request; a reason is required for the request |
| `toggleDayOff` | write path | no day row for that employee and date: write path, direct, logged as today. A day row exists: one `rest_day` request |
| `bulkAction` | each employee through the write path, as today | each employee through the rule above; all requests of the action share one `batch_id`; one employee failing never stops the others. Reply: "N done, M sent for approval" with the failures listed |
| `AttendanceController::override` | lock check added; write path | lock check; one request per posted field; accepts `employee_id` + `work_date` for a day with no row |

Storing a request (`AttendanceChangeRequestService::submit`), one transaction per save:

- Refused for an inactive employee (422): such a request could never be approved.
- For each field: a pending row of the same requester is updated in place (same id, new value, new
  `old_value` snapshot, `version` + 1, `changed_at`); a pending row of another requester refuses the
  whole save (409) with "Already requested by <name>" and nothing is stored; otherwise a row is
  inserted. A rejected row for the same key gets `superseded_at`.
- Only `employee_id`, date, the fields and the reason are read from the body. `status`,
  `requested_by`, `approved_by` and anything else are never read (S-19.4).
- Replies: `{success: true, pending: true, message: "Sent for approval"}`; the page marks the cell.

A times request on a date that is already a rest day or SIL is accepted, as such an edit is possible
today (work on a rest day). `day_state` records it, and section 5 blocks an approval only when the
day *became* a rest day or SIL after the request.

## 5. Approving: the transaction, the lock and what is re-checked

`AttendanceApprovalService::approve(AttendanceChangeRequest $anchor, string $seen, bool $confirmed, User $approver)`.
The address carries the id of any request of the entry; the entry is all pending rows of that
request's employee and date. `$seen` is what the page showed: the sorted list `id:version` of the
entry's pending rows.

One `DB::transaction`:

1. Lock the employee row (`lockForUpdate`): the same lock the write path takes, so an approval and a
   direct edit of the same employee run one after the other.
2. Select the pending rows of the employee and date `lockForUpdate`, ordered by id. On MySQL this
   also holds off a request being added to that day until the approval ends.
3. The anchor row is no longer pending: stop with "Already approved" / "Already rejected" /
   "Withdrawn" (409). Nothing is written (S-11.9, S-16.1).
4. The approver's role is read again from the database: not `ceo`, stop (403).
5. Any row of the entry was asked by the approver: stop, "You cannot approve your own request"
   (S-19.5).
6. The `id:version` list of the locked rows differs from `$seen` (a value changed, a field was
   added or withdrawn): stop, "Request changed, review again" (409) (S-16.3, S-16.5).
7. The work date is locked: stop with the lock message (423) (S-11.5, S-18.2).
8. State of the day: the employee is inactive; for a times, overtime or shift request the date is a
   rest day now and was not when asked, or is SIL now and was not when asked; for a SIL request the
   existing checks (balance, already SIL): stop with the reason (422). A rest-day request is blocked
   only by the lock and by an inactive employee (S-17.10).
9. For each field the official value now is compared with `old_value`. If any differs and
   `$confirmed` is false: stop, "Official value changed" with the "was X, now Y" pairs (409). The
   page shows the same pairs beforehand and sends `confirmed` only after the CEO's confirm.
10. Apply through section 3, in a fixed order: rest day, SIL removal, shift, times (all time fields
    of the entry in one `applyTimes` call, each with its own requester's reason, one recompute),
    overtime, SIL apply. Each call returns the official row it wrote; its id goes to `applied_type`
    / `applied_id`.
11. `UPDATE ... SET status = 'approved', pending_marker = NULL, decided_by, decided_at WHERE id IN
    (...) AND status = 'pending' AND version = <seen>`. Fewer rows changed than the entry holds:
    throw. This compare-and-set is a second guard that does not depend on the row lock.

Any stop or exception rolls everything back: no day row, no override, the request still pending, the
message shown (S-11.6). Reject (`reject($anchor, $seen, $reason, $rejecter)`) is the same
transaction with steps 2 to 6 without the own-request rule, then the status update with the reason;
it does not check the lock or the state of the day (S-13.5). Withdraw and change are for the
requester only (`requested_by` equals the signed-in user, role not `ceo`), lock the one row, and
answer 409 "Already decided" on a decided row; a second withdraw of a withdrawn row answers 200
(S-14.8). Change is refused on a locked date (423), withdraw is not.

Several at once (selected entries, or a batch): a loop of single approvals, each in its own
transaction with its own `seen`; a failure is collected with its reason and the loop goes on.
The reply counts: "2 approved, 1 not approved: date locked".

## 6. Routes and pages

In the `auth` group, outside every `feature:` group. `AttendanceApprovalController` begins every
method with `assertCeo()` (403), like `AuditLogController`. Because the id is bound from the address
before the method runs, an id that does not exist is 404 for every caller (S-19.6) and a real id is
403 for everyone but the CEO.

| Route | Who | Does |
|---|---|---|
| `GET /approvals` | CEO | the list; filters status, employee, requester, date range, kind; default pending, oldest first; 50 entries per page |
| `POST /approvals/{changeRequest}/approve` | CEO | approve the entry; body `seen`, `confirmed` |
| `POST /approvals/{changeRequest}/reject` | CEO | reject the entry; body `seen`, `reason` |
| `POST /approvals/approve-several` | CEO | body `entries[]` (`id`, `seen`), `confirmed` |
| `POST /approvals/reject-several` | CEO | body `entries[]`, `reason` |
| `POST /approvals/batch/{batch}/approve`, `.../reject` | CEO | every pending entry of the batch, each on its own |
| `POST /attendance-requests/{changeRequest}/change` | the requester | body `new_value`, `reason` |
| `POST /attendance-requests/{changeRequest}/withdraw` | the requester | |

Pages and views:

- `resources/views/approvals/index.blade.php` (new): one card per entry (stacked on a phone, no wide
  table): employee, date, each field with "asked" beside "official now", "was X, now Y" when they
  drifted, the reason, who asked and when; flags "Date locked", "Now a rest day", "SIL applied",
  "Employee inactive" with Approve disabled; Approve and Reject; a checkbox per entry with "Approve
  selected" / "Reject selected"; a batch heading with "Approve batch" / "Reject batch". All text
  through `{{ }}`; long words wrap (`overflow-wrap: anywhere`).
- `layouts/app.blade.php`: `@if($userRole === 'ceo')` a link "Approvals" and, when above zero, a
  badge with the count of pending entries (distinct employee and date). The count is queried inside
  that `@if`, so no other role's page contains it.
- Calendar view: a "Pending" marker on the cell (a class and a small label, next to the existing
  lock and edited marks); in the modal a "Pending approval" panel listing each requested field
  beside the official value, the reason, who asked and when, with Withdraw and Change on the user's
  own rows; "Rejected: <reason>" with the values asked; the modal's save flows post employee and
  date when there is no day row and show "Sent for approval"; a short list of the signed-in user's
  rejected entries not yet replaced (S-13.9).
- Older Attendance page: a "Pending" badge on the row; its script follows the `pending` reply. The
  absent row's edit cells stay CEO-only in the view, as today.
- `payroll/show.blade.php` and `PayrollController::show`: when the run is not final and pending
  entries exist inside its period, a warning with the count above the Finalize button and in its
  confirm text. `finalize` itself is unchanged.

## 7. How the pages learn which days are pending

One query per page, never one per cell:

- Calendar `index`: `attendance_change_requests` for the listed employees and the visible range
  where `status = 'pending'`, or `status = 'rejected'` and `superseded_at` is null, with the
  requester loaded; grouped in PHP by `employee_id` + `_` + `work_date`. The cell's status, counts
  and totals are computed exactly as today from the official rows; the pending data is added beside
  them (`pending` => list) and only the marker and the modal read it. A second query loads the
  decided requests whose `applied_id` belongs to the override and day-off log rows already loaded,
  to add "asked by / approved by" to the edit history.
- The calendar's employee list (employees with a day row in the range) is widened by the employees
  with a pending or rejected request in the range, so a day that is only requested can be seen.
- Older Attendance page `index`: the same one query for its date range and employees, keyed the same
  way; the row template looks its key up. `exportCsv` and `printView` are not touched.
- Nav: one count query, for the CEO only.
- Payroll run page: one count query for the run's period.

## 8. Stages

Each stage: the failing tests first, named `test_s_NN_M_...`, watched red, then the change, then the
whole suite, then a commit.

| Stage | Work | Cases |
|---|---|---|
| 0 | `composer install`; the base run of the suite recorded; `tests/Feature/Approvals/ApprovalsTestCase.php` (users C, H, H2, M; employees E1, E2, E3; the shift; a daily rate and a payroll run for the period; Dn, Dr, Dl; helpers to post as a role, read the tables and compute E1's payroll); the red lines for S-05.1, S-19.1, S-20.1 and S-21.1 on the base code | — |
| A | the migration and the model; `AttendanceChangeService::applyTimes` and `createBlankDay` with the CEO callers moved onto them; the request store; `saveAll` (times), `overrideTime`, `createDay`, `fillFromShift` | S-05, S-08.1, S-08.3, S-15, S-19.4, S-21.1, S-21.2 |
| B | the approval service and controller, the routes, the Approvals page and the nav count; approve, reject, change, withdraw; the calendar marker and modal panel | S-09 (calendar), S-10 (without the P2 filters), S-11 (times), S-13, S-14, S-16, S-17.1 to S-17.3 and S-17.6 to S-17.8, S-19 |
| C | overtime, shift, SIL and the rest-day rule, each with its write path and its approval | S-06, S-07, S-08.2, S-11.3, S-17.4, S-17.5, S-17.9, S-17.10, S-21.3 to S-21.8 |
| D | bulk and batches; approve and reject several; the older Attendance page; locks end to end; the finalize warning; the P2 filters and the rejected list | S-12, S-18, S-20, S-22, S-09.5, S-10.7, S-13.9 |
| E | at least ten mutations, each with the test it turns red; a review of the diff against the threat model in a foreground subagent (two fix loops at most); `qa/stories.md`; the result file | all |

## 9. Tests: what can and cannot be shown

- Every case is an HTTP request as a signed-in user of the stated role, then a read of the tables
  and, where the case says so, of what `PayrollService` computes for E1 in a run of the period.
  Expected values are the case's literals.
- Page cases (S-05.16, S-09.9, S-10.9 and the other "shows" cases) are checked on the rendered HTML:
  the marker, the text, the control, the viewport meta tag and that the entry is a stacked card and
  not a wide table. A real 390 × 844 screen is not rendered by any test; the result will list these
  cases as "HTML only", and the three owner checks cover a real phone.
- Two requests "at the same instant" cannot run in parallel on sqlite in memory (question 4). What
  the tests do instead: the second call right after the first (S-16.1, S-14.8); the competing action
  injected between the approval's read and its write through a compute double bound in the
  container (S-16.2 to S-16.5); a second pending row inserted directly to prove the index refuses it
  and the code answers "already pending" (S-15.6); the day row created by a double in the middle of
  an approval (S-16.6).
- sqlite keeps a time part on `date` casts (Slice 001's finding). Where a case runs an import after
  an approval created the day row (S-11.8), the test first rewrites that row's `work_date` to the
  date-only text MySQL holds, as Slice 001's fixtures do; the existing compute is not edited.

## 10. Rulings made in this plan (recorded again in the result)

Ruling: an entry is not a table; it is the pending rows of one employee and date — the unique rule and the per-field requester need rows per field, and a second table would have to be kept in step — the Approvals list groups in a query instead of reading a parent row.

Ruling: approve and reject are addressed by the id of one request of the entry and act on the whole entry — the spec asks for ids in the address and a 404 for an unknown id — a CEO cannot approve one field of a day alone, which the spec also forbids.

Ruling: a save by HR staff or an admin that meets another person's pending request for one of its fields is refused whole — half a save stored is harder to explain than a refusal naming who asked — the user has to leave that field out and save again.

Ruling: a request for an inactive employee is refused when made (422) — it could never be approved — none known.

Ruling: removing SIL as HR staff or admin needs a reason; the CEO's direct removal stays without one — the CEO must be able to read why — one more field to fill for HR staff.

Ruling: override rows hold times as `H:i:s` on every path — one write path writes one form — the edit history of older rows shows `08:00` and of new rows `08:00:00`.

Ruling: "Approve selected" acts on the entries shown on the page (50), each with what the CEO saw; there is no approve-all across pages — an approval must carry what was seen — a batch of more than 50 needs the batch button or a second page.

Ruling: a rejected notice ends when a newer request for the same employee, date and field is made, whatever becomes of that newer request — "until a new request replaces it" — none known.

Ruling: the older page keeps its absent-row edit cells CEO-only in the view; the server applies the rule to whatever is posted — HR staff do there what they do today — HR staff enter a missing day on the calendar, not on the older page.

## 11. Risks

- **Payroll.** The request table is read by no payroll, compute or export code. Stage A's first
  tests assert no day row, no override row and equal payroll for a pending request.
- **The CEO's own edits change code path.** Section 3 lists every difference. S-08 and the Slice 001
  suite run after each extraction.
- **The minute the code is live.** From then on an edit by HR staff or an admin is a request, and
  nothing they enter changes pay until the CEO approves. Edits already official stay as they are.
  The CEO needs to know before the deploy; the result's "Merge danger" will say so.
- **Switching back.** Revert the merge; the table can stay (nothing reads it) or be dropped by the
  migration's `down`. Requests still pending at that moment are not applied by a revert.
- **MySQL only behaviour.** Row locks and the two-at-once cases are proven on sqlite only by
  injected interleaving; see question 4.

## 12. Questions for the reviewer

1. **Rest-day changes on two other screens.** The Day Off Calendar (`/day-off-calendar/toggle`) and
   the Employee page (add, update, delete a day-off override) let HR staff and admins change a rest
   day on a day that has a day row, with no lock check and no request. Both controllers are outside
   the allowed files. Recommended: allow `DayOffCalendarController` and `EmployeeController` for one
   small guard each: for HR staff and admins a locked date is refused (423) and a date with a day
   row is refused with "This day has attendance. Change it on the Attendance Calendar."; with no day
   row it stays direct as today; the CEO is unchanged. No request is created from those screens.
   Weekly rest-day patterns and shift assignments over a range stay as they are.
2. **The older page's shift change.** Today `/attendance/override` with `shift_id` sets the day
   row's shift and then its own recompute sets it back to the employee's effective shift; only the
   override row remains. Recommended: the older page's shift change, for the CEO and for an approved
   request, goes through the calendar's shift path (a single-day `employee_shift_assignments` row,
   then recompute), so S-20.2 and S-20.7 hold and the change takes effect. S-20.3 then reads "saved
   and recomputed" for a shift without "as today", and no `shift_id` override row is written.
3. **Naming the requester on official rows.** The override, day-off log and SIL rows have one user
   column each. Recommended, with no change to existing tables: that column holds the CEO who
   approved (as if the CEO had entered it), the reason is the requester's, and the request row
   points to the official row it produced, from which the edit history and the Approvals "decided"
   view show "asked by H, approved by C". S-11.11's "each override row names the right requester" is
   then met through that link, not by a column on the override row. The alternative is a nullable
   `requested_by` column on three existing tables.
4. **Proof of the two-at-once cases.** The suite runs on sqlite in memory, one connection: no real
   parallel requests and no real row lock. Recommended: the injected-interleaving tests of section
   9, the unique index test, a test that the approval's select is a locking read, and one hand check
   on a MySQL copy before release (two tabs approving the same entry), listed under "Owner checks".
   No local MySQL test run is planned.
5. **Asking again while a request waits.** Saving the same field again as the same requester updates
   the request (S-15.1), and the modal also offers "Change" on a pending row, which posts to the
   id-based address S-14 describes. Recommended: build both; "Change" fills the modal's inputs with
   the requested value and saves through the id-based address, so HR staff see one form and learn
   nothing new.

## 13. Amendment 1 (the "go")

The plan was approved and the five questions answered as recommended, with one change: the two-tab check on MySQL is a check for the tester before release, written into the result's release list, not an owner check. One ruling was not accepted as written: a request for an inactive employee is not refused when made. The rule is "exactly as the CEO's direct edit"; the direct path accepts an inactive employee today, so the request is accepted, the entry is flagged "Inactive employee" and it can be approved. Step 8 of section 5 and the first bullet under "Storing a request" in section 4 therefore no longer hold for an inactive employee. `DayOffCalendarController` and `EmployeeController` joined the allowed files for one guard each. What was built, and every ruling made while building, is in `docs/specs/002-attendance-approvals.result.md`.
