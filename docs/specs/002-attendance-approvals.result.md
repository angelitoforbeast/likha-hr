# Result: spec 002 The CEO approves attendance changes made by HR staff and admins

> Committed beside the spec: names no people and no decision ids.

**Status:** done, amendments 2 and 3 applied. The tester ran `9503814` on MySQL (pass with minors) and in a browser as HR staff and admin. What amendment 3 changed in the pages is run under `node` for the calendar's request script only; the Approvals page script has not run in a browser yet (see "After amendment 3")
**Date:** 2026-10-14
**Branch / PR:** `feat/002-attendance-approvals`; head is the last commit on the branch, the one that updates this result for amendment 3 (`docs: result of spec 002 after amendment 3`); the commit before it holds the changes of amendment 3. No pull request for this spec.
**Preview or run link:** n/a (nothing is deployed; the tests run locally)

## Summary

What an HR staff member or an admin enters on a day is now stored as a pending request in a new
table, `attendance_change_requests`, one row per employee, date and field, and nothing is written to
`attendance_days`, `attendance_overrides`, `day_offs`, `sil_applications` or
`employee_shift_assignments` until the CEO approves. The CEO's own edits stay direct.

- **One write path.** `AttendanceChangeService` holds every official write (times, approved
  overtime, a shift for a date, SIL apply and removal, a rest-day change, a blank day row). The
  CEO's direct edits in both attendance controllers and the approval call the same methods.
- **Requests.** `AttendanceChangeRequestService` stores, changes and withdraws them. A unique index
  on (`employee_id`, `work_date`, `field`, `pending_marker`) allows one pending row per employee,
  date and field.
- **Approval.** `AttendanceApprovalService::approve` is one transaction: lock the employee row
  (first statement), lock the entry's pending rows, then re-check that the entry is still pending,
  the role as stored now, the own-request rule, what the CEO saw (`id:version` of each row), the
  date lock (read for update), the state of the day, and whether an official value changed since
  the request (needs a confirm); apply; mark approved with a compare-and-set on status and version.
- **Pages.** A CEO-only Approvals page (`/approvals`) with one card per employee and date, filters,
  "selected" and batch actions, and a count in the nav. The calendar marks a requested day
  "Pending", shows the request beside the official values in the modal, shows "Rejected: reason",
  and lists a requester's rejected requests. The older Attendance page marks pending rows. The
  payroll run page warns about pending requests in its period.
- **Also closed.** The older Attendance page's missing role and lock checks; the blank day row the
  modal wrote before a time was entered; names and reasons written as HTML in the calendar's edit
  history and bulk result; the Day Off Calendar and the Employee page changing a rest day on a
  locked date or on a day with attendance (HR staff and admins).

`PayrollService` and `AttendanceComputeService` are unchanged.

## Case table

`php.bat vendor/phpunit/phpunit/phpunit --no-coverage`, last run 2026-10-14:
`OK (208 tests, 1608 assertions)`. Test classes are in `tests/Feature/Approvals/`. Every case of
Slice 002 passes; `qa/stories.md` has each case with its test and `Passed · 2026-10-14 · auto`
(164 cases: the 162 of the spec and S-07.12, S-07.13).

| Story | Cases | Test class | Result |
|---|---|---|---|
| S-05 | S-05.1 to S-05.16 | `RequestTimesTest` | pass |
| S-06 | S-06.1 to S-06.8 | `RequestOtherKindsTest` | pass |
| S-07 | S-07.1 to S-07.8, S-07.10 to S-07.13 | `RequestOtherKindsTest` | pass |
| S-07 | S-07.9 | `BulkAndBatchTest` | pass |
| S-08 | S-08.1, S-08.3 | `RequestTimesTest` | pass |
| S-08 | S-08.2, S-08.4, S-08.5 | `RequestOtherKindsTest` | pass |
| S-09 | S-09.1 to S-09.4, S-09.6 to S-09.9 | `ApprovalsPageTest` | pass |
| S-09 | S-09.5 | `OldAttendancePageTest` | pass |
| S-09 | S-09.10 | `RequestOtherKindsTest` | pass |
| S-10 | S-10.1 to S-10.11 | `ApprovalsPageTest` | pass |
| S-11 | S-11.1, S-11.2, S-11.4 to S-11.11 | `ApprovalFlowTest` | pass |
| S-11 | S-11.3 | `RequestOtherKindsTest` | pass |
| S-12 | S-12.1 to S-12.7 | `BulkAndBatchTest` | pass |
| S-13 | S-13.1 to S-13.8 | `ApprovalFlowTest` | pass |
| S-13 | S-13.9 | `ApprovalsPageTest` | pass |
| S-14 | S-14.1 to S-14.10 | `ApprovalFlowTest` | pass |
| S-15 | S-15.1 to S-15.6 | `RequestTimesTest` | pass |
| S-16 | S-16.1 (two tests) to S-16.6 | `ApprovalFlowTest` | pass |
| S-17 | S-17.1 to S-17.3, S-17.6 to S-17.8 | `ApprovalFlowTest` | pass |
| S-17 | S-17.4, S-17.5, S-17.9, S-17.10 | `RequestOtherKindsTest` | pass |
| S-18 | S-18.1 to S-18.7 | `LocksTest` | pass |
| S-19 | S-19.1 to S-19.3, S-19.5 to S-19.8 | `ApprovalSecurityTest` | pass |
| S-19 | S-19.4 | `RequestTimesTest` | pass |
| S-20 | S-20.1 to S-20.8 | `OldAttendancePageTest` | pass |
| S-21 | S-21.1, S-21.2 | `RequestTimesTest` | pass |
| S-21 | S-21.3 to S-21.8 | `PayrollUntouchedTest` | pass |
| S-22 | S-22.1 to S-22.11 | `BulkAndBatchTest` | pass |

Tests without a case id (both from the review, both pass):

- `ApprovalFlowTest::test_sil_and_rest_day_requests_cannot_be_changed` — a SIL or rest-day request cannot be emptied or altered through the change address.
- `RequestOtherKindsTest::test_rest_day_exception_is_decided_under_the_lock` — a day row that appears while HR staff set a rest day turns the change into a request.

**Checked on the rendered HTML only** (no test renders a screen or runs the page's script):

- Phone width: S-05.16, S-09.9, S-10.9. The tests check the viewport tag, that the marker is text inside the cell, that the entries are stacked cards and not a table, and the wrap rule in the stylesheet. A real 390 × 844 screen is the owner check on staging.
- What a page shows: S-09.1, S-09.2, S-09.6, S-09.7, S-09.10, S-10.1, S-10.8, S-10.11, S-11.4, S-13.2, S-13.9, S-18.2. The calendar modal is filled by script from data the server puts on the cell; the tests read that data (and, for S-13.2, that the script holds the text "Rejected: "), not the opened modal.
- Not executed by any test: the script of the calendar modal and of the older page (the "Sent for approval" message, the pending panel, Withdraw and Change, the reason prompt for removing SIL, the bulk result). The server replies they act on are tested.

## Story changes

- **S-17.6 changed.** Found in the code: no entry point of the direct path checks the employee's status. The calendar lists only active employees, but `saveAll`, `overrideTime`, `fillFromShift`, the SIL, shift and rest-day routes and the bulk action accept any existing employee id, so the CEO can correct the day of an inactive employee today by posting it. The request therefore follows the direct edit: HR staff and admins can ask, the entry is flagged "Inactive employee", and Approve and Reject both work. Payroll leaves inactive employees out, so there is no pay effect.
- **S-12.5 and S-12.7 reworded** for the same reason: "deactivated" is no longer among the blocks; S-12.5's failing entry is a SIL request whose balance is gone.
- **S-20.3 changed** (reviewer's answer 2): "as today" dropped for a shift. A shift posted by the CEO to the older page is saved as a single-day shift assignment and the day is recomputed with it; no `shift_id` override row is written.
- **S-07.12 and S-07.13 added** (reviewer's answer 1): the guard on the Day Off Calendar and on the Employee page.
- **S-06.3 and S-06.4 reworded**: a request to remove SIL or to assign a shift needs a reason (422 without one), because every request carries a reason and those two screens had none.
- **Not changed, but narrower than its words:** S-09.3 names "any calendar print or export". The calendar has none; the test covers the present, absent and undertime counts of the row. The older page's CSV and print are S-09.5 and S-21.6.
- **Not changed:** S-16.2 and S-16.4 ("exactly one wins"). On the one connection of the test database the competing rejection or withdrawal is made inside the approval's transaction and is rolled back with it, so the tests prove that the approval notices it and keeps nothing; that the other action then stands is part of the two-tab check on MySQL below.

## Done-when checklist

- [x] Every case of Slice 002 passes, each with a test named after it, and `qa/stories.md` has the slice with "Test" and "Last run" filled. — 164 rows; a script compared the case ids with the test names: none missing.
- [x] The result has a red line for S-05.1, S-19.1, S-20.1 and S-21.1 on the base code, watched before the change. — product code at `ac3534a` (commit `71afeb3` holds only the test base class), `php.bat vendor/phpunit/phpunit/phpunit --no-coverage --filter <case>`:
  - S-05.1: `1) Tests\Feature\Approvals\RequestTimesTest::test_s_05_1_times_on_a_day_without_a_row_become_a_request` / `Expected response status code [200] but received 403.` / `Tests: 1, Assertions: 1, Failures: 1.`
  - S-19.1: `1) Tests\Feature\Approvals\ApprovalSecurityTest::test_s_19_1_hr_staff_cannot_approve` / `Expected response status code [200] but received 403.` / `Tests: 1, Assertions: 1, Failures: 1.` (on the base no request can be made at all, so the case fails at its Given; it stayed red through stage A and turned green with the approval routes of stage B.)
  - S-20.1: `1) Tests\Feature\Approvals\OldAttendancePageTest::test_s_20_1_old_page_time_edit_becomes_a_request` / `the day row must stay as it was` / `Failed asserting that two arrays are identical.` / `Tests: 1, Assertions: 2, Failures: 1.`
  - S-21.1: `1) Tests\Feature\Approvals\RequestTimesTest::test_s_21_1_pending_request_does_not_reach_payroll` / `Expected response status code [200] but received 403.` / `Tests: 1, Assertions: 2, Failures: 1.`
- [x] The whole suite runs and adds no new failures compared with the base; every Slice 001 test still passes. — base: `OK (41 tests, 196 assertions)`; now: `OK (208 tests, 1608 assertions)`.
- [x] At least ten mutations, each turning a named test red. — 19, run on the final code by a script that restores the file after each; `git status` was clean afterwards.

  | # | Mutation | Test that turned red | First failure line |
  |---|---|---|---|
  | M01 | the role read used by approve and reject always says "CEO" | `ApprovalSecurityTest::test_s_19_1_hr_staff_cannot_approve` | `Expected response status code [403] but received 200.` |
  | M02 | a request also writes a blank day row | `RequestTimesTest::test_s_05_1_…` | `a request must not create a day row` |
  | M03 | the lock re-check inside the approval removed | `ApprovalFlowTest::test_s_11_5_locked_at_approval_time` | `Expected response status code [423] but received 200.` |
  | M04 | the own-request rule removed | `ApprovalSecurityTest::test_s_19_5_own_request_cannot_be_approved` | `Expected response status code [403] but received 200.` |
  | M05 | the unique rule dropped from the migration | `RequestTimesTest::test_s_15_6_database_refuses_a_second_pending_row` | `the database accepted a second pending row` |
  | M06 | the row lock dropped | `ApprovalFlowTest::test_s_16_1_approval_locks_its_rows` | `the entry rows must be read for update` |
  | M07 | the stale-view check removed | `ApprovalFlowTest::test_s_16_3_changed_request_needs_a_new_look` | `Expected response status code [409] but received 200.` |
  | M08 | the older page saves directly for everyone | `OldAttendancePageTest::test_s_20_1_…` | `the day row must stay as it was` |
  | M09 | a rejected request is applied | `ApprovalFlowTest::test_s_13_1_rejection_changes_nothing_official` | `Failed asserting that two arrays are identical.` |
  | M10 | the approval writes times through code of its own, without the recompute | `ApprovalFlowTest::test_s_11_1_approval_equals_a_direct_entry` | `computed_work_minutes` |
  | M11 | the compare-and-set of the approval removed | `ApprovalFlowTest::test_s_16_4_withdraw_while_approving` | `Expected response status code [409] but received 200.` |
  | M12 | HR staff and admins write directly | `RequestTimesTest::test_s_21_1_pending_request_does_not_reach_payroll` | `Unable to find JSON:` (no "pending" in the reply) |
  | M13 | anyone can withdraw a request | `ApprovalFlowTest::test_s_14_3_other_users_cannot_change_or_withdraw` | `Expected response status code [403] but received 200.` |
  | M14 | a locked date takes a request | `RequestTimesTest::test_s_05_8_locked_date_takes_no_request` | `Expected response status code [423] but received 200.` |
  | M15 | a rest-day change on a worked day is direct for HR staff | `RequestOtherKindsTest::test_s_07_4_…` | `Unable to find JSON:` |
  | M16 | a changed official value needs no confirm | `ApprovalFlowTest::test_s_17_2_ceo_edit_of_the_same_field` | `Expected response status code [409] but received 200.` |
  | M17 | a reason rendered as HTML on the Approvals page | `ApprovalsPageTest::test_s_10_8_text_is_escaped` | the page contains `<script>alert(1)</script>` |
  | M18 | the rest-day exception not re-checked under the lock | `RequestOtherKindsTest::test_rest_day_exception_is_decided_under_the_lock` | `Unable to find JSON:` |
  | M19 | the approval reads the employee before it locks | `ApprovalFlowTest::test_s_16_1_approval_locks_its_rows` | `the first statement of the approval must be the locking read of the employee` |

- [x] `git diff main --stat` lists only the allowed files. — 32 files: the two attendance controllers; extracted from them `app/Services/AttendanceChangeService.php`, `AttendanceChangeRequestService.php`, `AttendanceApprovalService.php`, `AttendanceChangeRefused.php` and `app/Http/Controllers/Concerns/AnswersAttendanceChanges.php`; new `app/Models/AttendanceChangeRequest.php`, `app/Http/Controllers/AttendanceApprovalController.php`, `resources/views/approvals/index.blade.php` and the migration; `routes/web.php`; `resources/views/layouts/app.blade.php`; the calendar and Attendance views; `PayrollController.php` and `payroll/show.blade.php` for the warning; `DayOffCalendarController.php` and `EmployeeController.php` (allowed by the reviewer's answer 1); ten test files; `qa/stories.md`; `docs/`.
- [x] `git diff main -- app/Services/PayrollService.php app/Services/AttendanceComputeService.php` is empty. — 0 lines.
- [x] Nothing was pushed. — `git branch -r` prints `origin/HEAD -> origin/main` and `origin/main` only. No push, ssh or remote database command was run.
- [x] The result file is filled in, including "Merge danger" and what production needs. — this file.

## How to run

In Git Bash, from a fresh clone of the branch:

```
/c/Users/Forbeast/.config/herd/bin/composer.bat install
/c/Users/Forbeast/.config/herd/bin/php.bat vendor/phpunit/phpunit/phpunit
/c/Users/Forbeast/.config/herd/bin/php.bat vendor/phpunit/phpunit/phpunit --no-coverage --testdox
```

No `.env` is needed. One case: `--filter s_05_1_` (the trailing underscore keeps S-05.10 to S-05.16 out).

## Rulings

Answers of the reviewer (amendment 1), as built:

Ruling: the Day Off Calendar and the Employee page refuse HR staff and admins on a locked date (423) and on a date with a day row ("This day has attendance. Change it on the Attendance Calendar."); direct with no day row; the CEO unchanged — reviewer's answer 1 — HR staff who used those screens for a worked day now go to the calendar.

Ruling: the older page's shift change goes through the calendar's shift path for the CEO and for an approved request — reviewer's answer 2 — the CEO's shift change there now takes effect, where before its own recompute put the old shift back.

Ruling: the user column of an official row holds the approving CEO, the reason is the requester's, and the request row points to the row it produced (`applied_type`, `applied_id`), from which the history says "asked by, approved by" — reviewer's answer 3 — a report that reads `attendance_overrides` alone shows the CEO, not the requester.

Ruling: the two-at-once cases are proven by injected interleaving, the unique-index test and a test of the locking reads; the two-tab check on MySQL is in the release list below, for the tester — reviewer's answer 4 — until that check is run, the behaviour of real row locks is reasoned, not observed.

Ruling: asking again through the same form updates the request, and the modal's "Change" posts to the id-based address with the time and reason typed in the same form — reviewer's answer 5 — none known.

Ruling: a request for an inactive employee is accepted, flagged "Inactive employee" and can be approved — the direct path accepts such an employee today (see "Story changes") — a day of someone who left can be asked for and approved; it has no pay effect while the employee is inactive.

Choices made while building:

Ruling: status codes — 423 a locked date; 403 not the CEO, own request, another person's request; 409 already decided, "Request changed, review again", another person's pending field, official value changed and not confirmed; 422 validation and a day that blocks the approval — the page shows the message whatever the code — none.

Ruling: a rejection carries what the CEO saw as well — a field added after the page loaded would otherwise be rejected unseen — a stale Reject click answers "Request changed, review again".

Ruling: "Approve selected" and a batch approval never confirm a changed official value; such an entry stays pending with "official value changed" and is approved from its own card — a confirm must be given per entry — more clicks for a batch that an import overtook.

Ruling: a batch decision acts on the entries of the batch that the page shows (up to 50), each with what the CEO saw — an approval must carry what was seen — a bulk action over more than 50 employees takes more than one click.

Ruling: only a time, overtime or shift request can be changed by its requester; a SIL or rest-day request is withdrawn and asked again; the modal offers "Change" for times — a SIL or rest-day request holds an action, not a value — one more step for a mistaken SIL request.

Ruling: the approval of a field whose official value already equals the requested one still writes its override row — the rule "the requested fields are applied" stays simple and the field is then kept on a later import — a log row with equal old and new value.

Ruling: `approved_ot_hours` must be a number from 0 to 24 (before: any text, read as a number); a bulk "set times" without any time is refused (422) for every role (before: nothing written, days recomputed); clearing a time on a day without a row writes nothing (before, through Save All, a blank day row) — each closes a way to a blank row or a 500 — the CEO sees a refusal where before nothing visible happened.

Ruling: the older page's "create day" takes the shift for the date, as the calendar's does, not the employee's default shift — it now shares `createBlankDay` — a day created there for a date with a single-day shift gets that shift.

Ruling: the rebuild of a day from its punches after a shift or rest-day change runs after the commit of that change — it swallows its errors, and inside the transaction a swallowed database error could leave the change half-applied (review finding) — if that rebuild fails the change stands and the day is refreshed by the next Compute, as before.

Ruling: the test database gets triggers that cut date columns to the date, and the column `payroll_items.holiday_earnings_detail`, both created by the test base class only — sqlite keeps the time part of a date and the existing lookups then miss rows the code itself wrote (the finding of Slice 001); and `PayrollService` writes that column although no migration creates it — the tests model a MySQL `DATE` column instead of running on one.

Ruling: the request branches of `saveAll` for overtime and a shift were written with stage A, and the "several" routes with stage B, before their own cases — the method is one piece and S-19.1 needs the route — S-06.1, S-06.7 (first half) and S-12.1 to S-12.6 were not watched red; M12 shows the branch is what the tests depend on.

Ruling: the rejected notice in a day's modal is shown to everyone who opens the day; the list above the calendar holds only the signed-in user's own — the admin and a second HR staff member should not ask again for what was refused — none known.

Ruling: two new files hold shared pieces of the controllers: `AttendanceChangeRefused` (a refusal with its HTTP status) and the trait `AnswersAttendanceChanges` (the time rule and the two replies) — both attendance controllers and the approvals controller need them — none.

## Deferred minors

One review of the diff ran in a foreground subagent against the threat model. It reported one major, fixed in one loop together with four minors (see the commit `16e50ba`): the approval read the employee before taking the lock, so on MySQL its later reads could show the data as before its wait; a SIL or rest-day request could be emptied through the change address; the rest-day exception was decided outside the lock; a swallowed error inside the transaction; the nav count loading rows. A second review loop was not run. Accepted without a fix:

- **The confirm of a changed official value is a yes/no**, not tied to the values the CEO saw. If the official value changes a second time between the page load and the click, the confirm covers the newer value unseen. Only a CEO edit, another approval or an import can cause it, and the request's own value is protected by the version check.
- **The rest-day guard on the Day Off Calendar and the Employee page looks for the day row before the write, not under a lock.** A day row created in the same instant is missed. On the Attendance Calendar the same rule is decided under the lock.
- **The Approvals page runs a few queries per entry** (official values, blocks), up to a few hundred on a full page of 50. The calendar and the older page stay at one query per page for the pending state.
- **The date-lock read in the approval uses `whereDate`**, which on MySQL scans the lock table and holds its rows for the length of the approval. The table has one row per locked date.
- **`ensureAttendanceDay()` is left in the calendar script, unused.** The modal no longer creates a blank row before a time is saved.
- **The Approvals filters reset when the status tab is changed.**
- **Schedule-level edits stay direct for HR staff and admins**: weekly rest-day patterns, shift assignments over a range on the Employee and Department pages, the bulk action of the Employee page (it can set a rest day, among other things). Out of scope by the reviewer's answer 1; listed under "Proposed tasks".

## Merge danger

**Two-way door for the code, one-way for what people do with it.** Revert the merge to get the old
behaviour back; the new table can stay (nothing else reads it) or be dropped with
`php artisan migrate:rollback --step=1`. What does not come back: changes the CEO approved are
official attendance and stay; requests still pending at the moment of a revert are applied by
nobody.

**Blast radius if wrong:** every attendance edit. Edits by HR staff and admins stop taking effect
until approved, from the minute the code is live. Edits by the CEO, and approvals, go through the
new write path and are read by payroll for open dates. Locked dates are not written by any path.

**What happens to edits the minute the code is live:** an HR staff member or admin who saves a time,
overtime, SIL, a shift, a rest day on a worked day or a bulk action reads "Sent for approval", the
day shows "Pending", and payroll does not change. Nothing they entered before is touched. If the
CEO does not open Approvals, nothing they enter reaches payroll: the CEO and HR must be told before
the release, and the Approvals count should be looked at before each payroll run (the run page
warns).

**Behaviour the CEO may notice in their own edits:**

1. Times in the edit history are logged as `08:00:00` on every path (before: `08:00` on some).
2. Each edit is all-or-nothing: if the recompute fails, nothing of the edit is saved.
3. A time that is not a time is refused (422) instead of an error page.
4. On the older Attendance page a shift change now takes effect (a single-day assignment); both of its edit routes refuse a locked date.
5. Approved overtime must be a number from 0 to 24; a bulk "set times" with no time is refused.
6. A new nav link "Approvals" with a count; a "Pending" marker on calendar cells; a warning on the payroll run page.

**Not run on MySQL.** All tests run on sqlite in memory. The request table's unique index, the row
locks and the transaction were written for MySQL and checked by reading and by the injected tests,
not by a run on MySQL. The release list has the checks.

**What production needs after the code lands**

1. `php artisan migrate` — one new table, `attendance_change_requests`; no existing table changes.
2. No queue worker restart is needed for this change: the import job and `AttendanceComputeService` are untouched. (A restart does no harm.)
3. Before release, on a private MySQL copy of the database (the tester, not the owner):
   1. Run the migration. `SHOW INDEX FROM attendance_change_requests` lists `acr_one_pending_unique` on `employee_id`, `work_date`, `field`, `pending_marker`.
   2. As HR staff, enter four times with a reason for an employee on an unlocked day without a row. Expected: "Sent for approval"; `attendance_days` has no row for that employee and date; four rows in `attendance_change_requests` with `status = 'pending'`, `pending_marker = 1`.
   3. In an SQL console insert a copy of one of those four rows. Expected: error 1062 (duplicate entry for `acr_one_pending_unique`).
   4. As the CEO open `/approvals` in two tabs, so that both show that entry. Click Approve in the first tab and at once in the second. Expected: one tab says "Approved.", the other "Already approved."; exactly one `attendance_days` row for that employee and date; exactly four `attendance_overrides` rows for it; the four requests `approved` with `pending_marker` NULL.
   5. Make two requests again (two employees, or two dates). Open both tabs. In the first tab reject one entry with a reason; in the second approve the same entry. Expected: the second answers "Already rejected."; no day row was written.
   6. Give an employee one remaining SIL day and, as HR staff, ask for SIL on two dates. Approve both in two tabs at once. Expected: one applied; the other refused with "No remaining SIL days"; one row in `sil_applications`.
   7. As HR staff ask for a time; as the CEO load `/approvals`; as HR staff change the request; as the CEO click Approve on the page loaded before. Expected: "Request changed, review again", nothing written.
4. One read-only check: `SHOW COLUMNS FROM payroll_items LIKE 'holiday_earnings_detail'` must return a row. `PayrollService` writes this column and no migration creates it; a database built from the migrations alone cannot compute payroll. (Not caused by this change; found while writing the tests.)
5. In a browser, once, as HR staff and as the CEO (the three owner checks in `qa/stories.md`): the modal says "Sent for approval" and the cell shows "Pending" after closing; Withdraw and Change work from the modal; the CEO's nav shows the count; approving on a phone; the requester reads "Rejected: reason".
6. Tell the CEO and HR what changes (above) before the release.

**How to switch back:** revert the merge and deploy; then either leave the table or run
`php artisan migrate:rollback --step=1`. Pending requests should be approved or rejected first, or
re-entered by the CEO afterwards.

## After amendment 2

The tester ran `3483f31` on MySQL with real parallel requests and found one major (F1) and four
minors. All are fixed in `67eb411`. What follows replaces the older text of this file where the two
differ: "Not run on MySQL" under "Merge danger" described the first result, and S-19.6 changed.

**Suite:** `OK (221 tests, 1694 assertions)` (208 before; 13 new in `LockConflictAndGuardsTest`).

### What changed

- **F1, the lock order.** Every operation that asks for, changes, withdraws, approves or rejects a
  request takes its locks in one order: the employee row first, then the request rows of that
  employee by id ascending. The order is stated once, at
  `AttendanceChangeService::lockEmployeeById`. Before, `reject`, `change` and `withdraw` took the
  request rows without the employee row, so a rejection could hold the rows while waiting for
  nothing and an approval could hold the employee while waiting for the rows.
- **F1, the retry.** `AttendanceChangeService::withLockRetry` runs the transaction; on MySQL error
  1213 (deadlock, SQLSTATE 40001) or 1205 (lock wait timeout) it runs it once more, and if that
  fails as well the answer is 409 "Someone else is changing this request. Try again." with nothing
  written. Approve, reject, change, withdraw and the storing of a request go through it; "selected"
  and batch decisions call approve and reject per entry and report such an entry as "not approved:
  someone else is changing it". Two places that caught a database error to handle a duplicate key
  now let a lock conflict through instead of going on in a transaction the database has ended.
- **F2.** `removeSil`: HR staff and admins never reach the direct removal. Whether SIL is applied
  is read under the employee lock; no SIL gives the existing message and no request, SIL gives a
  request.
- **F3.** The approve and reject addresses check the role before they look the id up: anyone but
  the CEO gets 403 whether the id exists or not; the CEO gets 404 for an id that does not exist.
  Not changed: the requester's change and withdraw addresses still answer 404 for an id that does
  not exist and 403 for someone else's request (S-14.3 asks for that 403); HR staff see the pending
  requests of a day on the calendar anyway.
- **F4.** An approved request made through the older page's override creates the day with the note
  "Manually created by CEO from absent row.", the note the CEO's own edit through that address
  writes.
- **F6.** The migration is `2026_10_14_000001_create_attendance_change_requests_table.php`. A
  database that already ran it under the old name (the tester's copy) lists the old name in its
  `migrations` table: rename that row or drop the table before migrating again. Production has not
  run it.
- **A flaky test fixed.** S-09.5 and S-21.6 compare the print view before and after, and that view
  prints the minute it was generated; the tests now hold the clock still. Seen once, when a run
  crossed a minute.

### Rulings

Ruling: a requester who was made CEO since may reject or withdraw their own old request, and may not approve or change it — reviewer's ruling on the tester's F7: nothing becomes official by a rejection or a withdrawal — a CEO can clear their own old requests alone; another CEO is needed only to approve them. `withdraw` now checks whose the request is, not the role; the CEO still cannot withdraw anyone else's request (S-14.10).

Ruling: storing a request goes through the same retry as the decisions — it takes the same locks — a save that meets two deadlocks in a row reads "Someone else is changing this request. Try again."

Ruling: inside an outer transaction nothing is retried — after a deadlock the database has rolled back the outer transaction too, so only the outermost caller can start again — none today: every caller is the outermost.

Story change: **S-19.6** now reads "HR staff posting approve or reject with an id that does not exist get 403, the same as for an id that exists; the CEO gets 404; never an error 500" (reviewer's F3).

### Tests added (all red before the fix, except the two marked)

`LockConflictAndGuardsTest`, 13 tests. A deadlock is injected by a hook that makes the statement
which changes the request table fail with a `QueryException` of SQLSTATE 40001 once or twice; that
statement is the last of a decision, so everything written before it is in the transaction that
must roll back.

- rejection: one deadlock, retried, 200; two deadlocks, 409, nothing written, still pending
- approval: one deadlock, retried, 200, one day row and four override rows (none left from the rolled back attempt); two deadlocks, 409, no day row, still pending
- "approve selected": the entry that met two deadlocks is named, the other is approved
- change and withdraw: 409 after two deadlocks, 200 after one, the request changed once
- reject, change, withdraw and approve each lock `employees` first and `attendance_change_requests` second
- SIL applied while HR staff remove it: the SIL stays and a request is made
- no SIL applied: the message and no request (green before: the rule held)
- approve and reject as HR staff or admin: 403 for a real id and for an unknown id; 404 for the CEO
- batch approve, batch reject and "reject selected" as HR staff or admin: 403 (green before: the rule held; the test now pins the controller's check)
- a requester who became CEO: approve 403, change 403, reject 200, withdraw 200
- an approved request from the older page carries the older page's note

### Mutations after amendment 2

All 27 mutations of the script were run on `67eb411` and each turned its test red; the tree was
clean afterwards. The eight new ones:

| # | Mutation | Test that turned red | First failure line |
|---|---|---|---|
| M20 | a rejection takes the request rows without the employee lock first | `LockConflictAndGuardsTest::test_every_decision_locks_in_one_order` | `reject` / `Failed asserting that two arrays are identical.` |
| M21 | a deadlock is not retried | `…::test_reject_is_retried_once_after_a_deadlock` | `Expected response status code [200] but received 409.` |
| M22 | a second deadlock is shown raw | `…::test_reject_answers_409_after_two_deadlocks` | `Expected response status code [409] but received 500.` |
| M23 | HR staff fall through to the direct SIL removal when no SIL was seen | `…::test_sil_removal_by_hr_staff_never_reaches_the_direct_path` | `the SIL the CEO applied is still there` |
| M24 | the CEO check of the batch rejection removed | `…::test_batch_addresses_are_ceo_only` | `Expected response status code [403] but received 200.` |
| M25 | a requester who is CEO now can change their old request | `…::test_requester_who_became_ceo` | `Expected response status code [403] but received 200.` |
| M26 | the id is looked up before the role check | `…::test_approval_addresses_do_not_tell_which_ids_exist` | `Expected response status code [403] but received 404.` |
| M27 | an approved request of the older page gets the calendar note | `…::test_old_page_request_carries_the_old_page_note` | `Failed asserting that two strings are identical.` |

### The calendar modal's script and the time fields

Read, not changed. The four time fields are native `<input type="time" step="1">` elements. The
script keeps no state of its own for them: their `oninput` handler (`markAllDirty`) is an empty
function, and both save paths read each field's `.value` at the moment of the save ("Save All
Changes" in `saveAllChanges()`, and the per-field save handler). So a value set in any way is sent,
as long as the browser reports it.

What explains "typing did not register, the save said No changes to save": a native time field
reports an empty `.value` until every part of it is filled. With `step="1"` the field has hours,
minutes, seconds and, on a 12-hour system, AM/PM; typing `0800` and leaving seconds or AM/PM empty
leaves `.value` empty, the script sends "not changed" for that field, and the server answers "No
changes to save." A tool that types into the field without completing all parts, or that sets the
text without the browser's own input handling, gets exactly this. It is the behaviour of the page
before this spec as well, and a person can meet it by leaving the seconds blank. No change was made
(the amendment allows one only if the script noticed values through one kind of event, which is not
the case). Proposed task: tell the user "this time is incomplete" when a time field is partly
filled, instead of "No changes to save."

For the tester: set the field through the browser's own typing with all parts (for example
`08:00:00 AM`), or set `.value = '08:00:00'` on the element; either is read by the save.

### MySQL checks for the tester, added to the release list

8. **Approve against reject at the same moment (F1).** Two processes with the CEO's session, one
   pending entry. Hold the approval inside its transaction (the tester's 900 ms hold, or a
   `SELECT SLEEP(1)` after the employee lock) and post the rejection of the same entry meanwhile.
   Expected: the rejection waits for the approval and then answers 409 "Already approved."; the
   approval answers 200; never a 500; the rows are official once. Run it ten times, and ten times
   the other way round (rejection held, approval posted): then the approval answers 409 "Already
   rejected." and nothing official is written.
9. **Change or withdraw against approve.** The same with the requester's withdrawal, and with a
   change, posted while the approval is held. Expected: 200 for the approval and 409 "This request
   was already decided (approved)." for the other; never a 500.
10. **A deadlock that still happens.** If a 409 "Someone else is changing this request. Try again."
    is seen in these runs, the request must still be pending and nothing official written; a second
    click must then work. (With one lock order it should not appear at all; the retry is the net
    under it.)
11. **SIL removal (F2).** As HR staff post the removal of SIL for a date without SIL while the CEO
    applies SIL for that date at the same moment. Expected: the SIL row is still there afterwards;
    HR staff got either "No SIL was applied on this date." or "Sent for approval".
12. **F3.** As HR staff post `/approvals/999999/approve`: 403. As the CEO: 404.

### Not proven

- The fixes of amendment 2 have not run on MySQL. That one lock order removes the deadlock the
  tester saw is reasoned from the order of the statements and shown on sqlite only as "employees is
  locked first"; checks 8 and 9 above are the proof.
- The retry is tested with an injected error, not with a deadlock MySQL raised itself.
- The page scripts are still not executed by any test.
## After amendment 3

The tester passed `9503814` on MySQL with minors, and a browser run found that the CEO's page,
Withdraw and three everyday moments could not be used or trusted as they were. This section is what
changed on top of `9503814`.

**Suite:** `OK (229 tests, 1778 assertions)` (221 before; 2 new in `LockConflictAndGuardsTest`, 6 in
the new `PageUpdatesTest`). All 32 mutations of the script turn their test red.

### Found while writing the tests: a deadlock inside a nested transaction

The new test "storing a request goes through the retry" was red for a reason of its own. When a
deadlock or a lock-wait timeout happens inside a nested transaction, the framework does not pass on
the query's exception: it throws `Illuminate\Database\DeadlockException`
(`ManagesTransactions::handleTransactionException`). The retry of amendment 2 caught only
`QueryException`, so such a conflict would still have answered 500. On MySQL this is the case for
the statements of an approval's own writes (each write method opens a nested transaction) and for
the insert of a request. `withLockRetry` and `isLockConflict` now treat both forms as a lock
conflict. The tester's runs on `9503814` did not meet it; check 13 below asks for it.

### 1. Controls changed, and controls left

Changed, all added by this spec, now without `confirm()`, `alert()` or `prompt()`:

| Control | Before | Now |
|---|---|---|
| Withdraw (calendar modal, pending panel) | `confirm('Withdraw this request?')`, then a reload | the row of the request shows "Withdraw this request?" with **Yes, withdraw** and **Cancel**; the answer is written in the panel |
| Change (calendar modal, pending panel) | messages, then a reload | the panel shows the new value and the server's message, no reload |
| Approve, Reject (Approvals page, one entry) | a page load with a banner | sent in the background; the entry leaves the list; a refusal is written under the form (for Reject: beside the reason field, which is in the page, required, 3 to 500 characters) |
| Approve selected, Reject selected | `confirm(...)` on Approve selected | a row under the bar asks "Approve the selected entries?" / "Reject the selected entries with this reason?" with **Yes, approve** / **Yes, reject** and **Cancel** |
| Approve batch, Reject batch | `confirm(...)` on Approve batch | the same row under the batch heading |
| The reason for removing SIL, asked of HR staff and admins | `prompt(...)` (added by this spec) | the message "Type the reason in the SIL reason box above, then press Remove SIL again." and the cursor in that box |
| "Sent for approval" on the older Attendance page | `alert(...)` (added by this spec) | a notice at the top of the page, then the reload that marks the row |

Left as they were, because they existed before this spec: on the calendar Lock, Unlock, Lock
range, Revert OT, Remove SIL's own question "Remove SIL for this date?", Delete time and Bulk apply;
the older Attendance page's own dialogs; the payroll page's Recompute and Finalize questions (the
Finalize question carries the pending-requests warning since the first result; it is still the
browser's dialog). A test counts the native dialogs of these three views and holds them at the
numbers of `main`: calendar 15 `alert`, 8 `confirm`, 2 `prompt`; older page 2 and 2; payroll 2
`confirm`.

### 2. What the page shows after each save, without a reload

Every reply that stores, changes or withdraws a request now carries `day_requests`: the requests
of that day in the form the calendar shows them (built by one method,
`AttendanceChangeRequest::toDayItem`, also used when the page is built). The calendar script puts
that list on the cell and in the panel (`applyDayRequests`).

- **After "Sent for approval."** (Save All Changes, the per-field save, Delete time, Fill from
  Shift, approved overtime, SIL apply and removal, a rest-day change): the cell gets the "Pending"
  marker and its orange frame at once, and the pending panel at the top of the modal lists the
  request.
- **After Change:** the panel shows the new value at once.
- **After Withdraw:** the marker leaves the cell and the panel disappears (or keeps the other
  requests of the day); "Request withdrawn." is written by the Save button.
- **After Save All Changes for a request the modal stays open**, as it does after the CEO's direct
  save. The message is in the pending panel at the top of the modal ("Sent for approval.", it
  stays until the next action) and, for two seconds, by the Save button as before. The page still
  reloads when the modal is closed, as after a direct save.
- **A shift change asked for approval** reloads the page after its message, as the shift control
  has always done; the reloaded page shows the marker.
- **On the Approvals page** a decided entry leaves the list and the nav count is set to the number
  the server answers with (the badge goes when it reaches 0). After "selected" and batch actions
  the entries that were decided leave; one that was not keeps its card with the reason written on
  it. When the last entry of the page leaves, the list says "Nothing is left on this page."

### 3. The two tests the tester's mutations asked for

- `test_lock_wait_timeout_is_a_lock_conflict`: a `QueryException` with SQLSTATE HY000 and error
  1205, once (200) and twice (409, nothing written). Dropping 1205 turns it red (M28).
- `test_storing_a_request_goes_through_the_retry`: the insert of a request fails with 40001 once
  (stored once, 200) and twice (409, no request row). A plain transaction in `submit()` turns it red
  (M29).

### Tests and what they prove

- **(a) No native dialogs.** `PageUpdatesTest::test_new_controls_use_no_native_dialogs` scans the
  source of the Approvals view (new as a whole) and of the calendar's request script (between its
  two markers) for `confirm(`, `alert(`, `prompt(`: none. It holds the three older views at the
  counts of `main` named above, and scans the Approvals page as the CEO gets it, with entries and a
  batch on it.
- **(b) A script test, without a download.** `node` is installed on this PC (v24.13.1), so
  `test_request_script_updates_the_cell_and_the_panel` cuts the request script out of the calendar
  view and runs it under `node` against a small stand-in for the page: after "Sent for approval"
  the cell has its count, class and marker and the panel lists the request; a second save adds no
  second marker; Change posts the typed value and the panel shows the new one; Withdraw asks first
  and posts nothing, Cancel takes the question away, "Yes, withdraw" clears the cell and hides the
  panel; a refused withdrawal leaves the request and shows the reason; a reason with markup comes
  out as text. The test is skipped on a machine without `node`.
- `test_page_scripts_parse`: every inline script of the calendar (as HR staff and as the CEO), the
  Approvals page and the older Attendance page, as the server sends it, passes `node --check`.
- Three tests on the replies: `day_requests` after every way of asking, after Change and after
  Withdraw; `pending_entries` and per-entry `results` after decisions; the validation message of a
  refused rejection.
- New mutations, each red: M28 and M29 above; M30 a saved request is not put on the cell (the
  script test); M31 a native confirm back on the Approvals page; M32 the reply without the day.

### For the tester's browser run: the exact clicks

Before: as HR staff, on the Attendance Calendar, click an absent cell of an employee, type a full
time into Time In (hours, minutes, seconds and AM/PM: the field reports nothing until all parts are
filled), type a reason of 3 characters or more in "Reason", click **Save All Changes**. Expected at
once, with the modal still open: "Sent for approval." in the yellow panel at the top of the modal,
the request listed there with **Change** and **Withdraw**, and behind the modal the cell framed
orange with the word "Pending".

1. **Withdraw.** In that panel click **Withdraw**. Expected: the two buttons are replaced by
   "Withdraw this request?" **Yes, withdraw** **Cancel**; nothing is sent. Click **Cancel**: the
   two buttons are back. Click **Withdraw**, then **Yes, withdraw**. Expected: the panel
   disappears, "Request withdrawn." shows by the Save button, the cell loses "Pending" and its
   frame. No browser dialog at any point.
2. **Change.** Make a request again. Type another time in Time In and a reason in "Reason", then
   click **Change** in the panel (not Save All Changes). Expected: the panel shows the new time and
   "Request changed. Pending approval."; no reload.
3. **Approve.** As the CEO open **Approvals** in the nav (the badge shows the count). On one entry
   click **Approve**. Expected: no dialog; the card leaves the list; a green line above the list
   says "Approved."; the badge shows one less.
4. **Reject with a reason.** On another entry click **Reject** with the reason field empty.
   Expected: the browser marks the field as required and nothing is sent. Type `ab` and click
   **Reject**: the browser's own length hint, or under the form "A reason of 3 to 500 characters is
   required to reject."; the card stays. Type a real reason and click **Reject**: the card leaves,
   "Rejected." above the list, the badge one less.
5. **Approve selected.** With at least two entries left, tick two (or "Select all on this page")
   and click **Approve selected**. Expected: under the bar "Approve the selected entries?" with
   **Yes, approve** and **Cancel**; nothing is sent yet. Click **Yes, approve**: the approved cards
   leave, above the list "2 approved" (or "1 approved, 1 not approved: date locked", in which case
   that card stays with the lock message on it), the badge follows. With nothing ticked the bar
   answers "Select at least one entry."
6. **Reject selected.** Tick entries, leave the reason of the bar empty, click **Reject selected**:
   "A reason of 3 to 500 characters is required to reject." Type a reason, click again: the
   question row, then **Yes, reject**: the cards leave and "N rejected" shows.
7. **A batch.** As HR staff run a bulk action on a date column for two or more employees; as the
   CEO the entries have a heading "Bulk action by …". **Approve batch** asks in a row under the
   heading; **Yes, approve** removes the entries and the heading.
8. **Removing SIL as HR staff** on a day with SIL: click Remove SIL (its own old question appears,
   answer it), with the SIL reason box empty. Expected: "Type the reason in the SIL reason box
   above, then press Remove SIL again." and no browser prompt. With a reason: "Sent for approval."
   and the cell marked.

### One more MySQL check for the tester

13. **A lock conflict inside a nested write.** Hold a lock on the `attendance_days` row (or the
    `attendance_overrides` table) that an approval is about to write, from a second connection,
    long enough for a lock-wait timeout (or shorten `innodb_lock_wait_timeout` for the session of
    the app). Approve. Expected: the approval is retried once, and if the lock is still held it
    answers 409 "Someone else is changing this request. Try again." with the request still pending
    and nothing official written; never a 500.

### Not proven

- The Approvals page script has run in no browser and under no test beyond `node --check` and the
  source scan: that the card leaves, the badge follows and the question rows work is read from the
  code. Steps 3 to 7 above are its proof.
- The calendar's request script is run under `node` against a stand-in for the page, not in a
  browser; steps 1, 2 and 8 are its proof. The other save handlers of the modal (overtime, SIL,
  rest day) call the same function but are not run by a test.
- The handling of `DeadlockException` is tested with an injected error on sqlite; check 13 is its
  proof on MySQL.
## Conflicts with CLAUDE.md

none (the repo has no CLAUDE.md).

## Tests

- 208 tests, 1608 assertions, all pass: `OK (208 tests, 1608 assertions)`. 41 are the tests of before this spec; 167 are new (165 named after the 164 cases, S-16.1 having two; 2 without a case id).
- Every case is an HTTP request as a signed-in user of the stated role against the route, then a read of the tables and, where the case says so, of what `PayrollService` computes for the employee in a draft run of 2026-10-01 to 2026-10-15. Cases that say "as if the CEO had entered it" compare with a control employee for whom the CEO enters the same thing directly.
- Two-at-once cases: the second call right after the first (S-16.1, S-14.8); a competing rejection or withdrawal injected in the middle of the approval through a compute double (S-16.2, S-16.4); a day row created in the middle of the approval's own insert (S-16.6); the index refusing a second pending row and the code answering "already pending" when its own check is blinded (S-15.6); the approval's reads carrying a lock clause, the first of them on the employee, made visible on sqlite by a grammar that writes the clause as a comment (S-16.1).
- S-11.6 binds a compute service whose recompute throws; S-19.8 switches the CSRF check back on, which the framework skips under test.
- Not covered by a test: MySQL itself; the scripts of the pages; a real phone screen; the payroll PDF as a file (the stored payroll items it is rendered from are compared instead).
- All 45 migrations run on sqlite unchanged.

## Open questions

None open. The five questions of the plan are answered and recorded under "Rulings".

## Process suggestions

- The spec's story for a deactivated employee (S-17.6) and its rule "exactly as the CEO's direct edit" pointed in two directions; a line per story saying which existing behaviour it leans on would have saved the round.
- For this repo, a line in the test setup of later specs: the test database needs date columns cut to the date (a trigger does it) and the column `payroll_items.holiday_earnings_detail`; both are in `ApprovalsTestCase`.
- The stage plan with tests first per stage worked; the two places where code came a stage early are recorded as a ruling.

## Proposed tasks

- A migration for `payroll_items.holiday_earnings_detail` — `PayrollService` writes it and no migration creates it; a fresh database cannot compute payroll — normal.
- A role check on `PayrollController::finalize` — any role with the payroll nav permission can finalize a run and lock its dates — normal (out of scope here by the reviewer's decision).
- Schedule-level edits by HR staff and admins (weekly rest-day patterns, range shift assignments on the Employee and Department pages, the employee bulk action) — they change many days at once, with no lock check and no approval — normal (the owner's call).
- Tie the confirm of a changed official value to the values shown — see "Deferred minors" — low.
- Remove `ensureAttendanceDay()` from the calendar script and keep the Approvals filters across status tabs — low.
- A run of the suite on MySQL (a local or CI database) — the row locks and the `DATE` behaviour are modelled, not run — normal.
- Say "this time is incomplete" in the calendar modal when a time field is partly filled — today the save answers "No changes to save." — low (see "After amendment 2").

## Suggested next steps

1. Review the branch; run the suite.
2. Run the MySQL checks of the release list on a private copy.
3. Tell the CEO and HR what changes; merge locally; migrate; do the browser checks on staging.
