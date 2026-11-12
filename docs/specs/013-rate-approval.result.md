# Result: spec 013 rate approval

**Status:** done
**Date:** 2026-11-13
**Branch / PR:** `feat/013-rate-approval`, from main c2883a4. Pushed; no PR.
**Preview or run link:** n/a. Nothing is deployed. The test runs and the rendered pages are in `docs/specs/013-evidence/`.

## Summary
- **The rule** (`App\Services\RateChangeService::plan`). It is one rule for every save and every approval. A rate for [start, end] shortens each approved rate that starts before it and runs into it, to end the day before the new start. That always happened to an open-ended rate; now it also happens to a rate with a future end date (the "For Probi" rates of 2026-11-12). These are still refused, with "Rate overlaps with existing rate (effective …)" and a sentence on what to do:
  - a rate that starts on or after the new start and overlaps it (it starts before an existing rate and runs into it, or has the same start date);
  - a closed rate the new one would cut in two: the new rate starts inside it and ends before it does.
  - Editing a rate uses the same rule, leaving the edited rate itself out. The whole plan is checked before anything is written, so a refusal changes nothing.
- **The approval.**
  - Anyone but the CEO (role `ceo`, the same `mustRequest` rule as attendance and shift-assignment requests) saves a rate as a **pending** row: on the profile (add or edit) and in the bulk "set daily rate". Nothing is shortened when it is saved, but it is checked then, so a request that could not be saved is refused at once.
  - The CEO approves or rejects each request on **Rate Approvals**, `GET /approvals/rates` (route `approvals.rates`; `POST /approvals/rates/{id}/approve` and `/reject`, each with an optional note). The page is CEO-only on the role read from the database, like Approvals; no feature permission opens it. The nav has a "Rate Approvals" link with the pending count.
  - Each request shows the employee, old rate → new rate with dates, what approving will shorten (or why it cannot be approved now), the remarks, and who asked and when. A "Decided" tab lists the last 100 decisions.
  - Approving checks the rates again as they are now, does the shortening, and makes the rate count. An approved change of an existing rate is written onto that rate (same row, audited as an update), and the request row goes. Rejecting changes nothing else.
- **The CEO saves directly**, as today, with the new shortening rule.
- **The employee's rate list.** Requests are shown above the rates that count: "Waiting for approval" or "Rejected: note", with who asked, when, and a button to withdraw or remove the request. A rate that counts and has a change waiting shows "Change waiting for approval". For HR and admins the add button reads "Send for approval".
- **Removing.** HR and admins can withdraw a pending or rejected request, but not remove a rate that counts (see Rulings). The CEO removes rates as before.
- **The audit log** keeps its created / updated / deleted rows. It also records `submitted` (the requester, when), `approved` and `rejected` (the CEO, when, note, and what was shortened). The shortening at a save or an approval is an `updated` row of the shortened rate, with effective_until old → new. The Audit Logs filter and badges know the three new actions.
- **Payroll** is not recomputed by anything here. An approved rate is used the next time a payroll is computed or recomputed.

## How pending rates are kept out of payroll
`EmployeeRate` has a global scope, `approved`, that adds `status = 'approved'` to every query of the model. Every reader of rates goes through the model:
- `EmployeeRate::getActiveRate` (payroll per day, `Employee::getRateForDate` / `getCurrentRate`, the Employees list);
- `PayrollService`'s multiple-rates query;
- the `Employee::employeeRates` relation;
- the overlap plan.

There is no `DB::table('employee_rates')` read in `app/`. The pending and rejected rows are read only where code asks for them with `EmployeeRate::anyStatus()`: the rate list on the profile, Rate Approvals, the nav count, and route binding (so a request can be withdrawn by its URL). Test S-013.6 proves it. A pending new rate in the payroll period, a pending change of the rate that counts and a rejected rate leave `PayrollService::computePayroll` exactly at the baseline. Once approved, the payroll uses the new rate.

## Migration
`2026_11_13_000001_add_approval_to_employee_rates`. This is the smallest change that keeps the request beside the rate it becomes: no new table, seven columns on `employee_rates`.
| Column | Why |
|---|---|
| `status` (default `approved`) | pending / approved / rejected; every existing row is approved |
| `approved_marker` (1 or NULL, default 1) | the old unique index (employee, start date) would refuse a request that keeps the start date of the rate it changes, or a request asked again after a rejection. It is replaced by unique (employee, start date, marker): still one approved rate per employee and start date, any number of requests beside it. It is the same device as `pending_marker` on `attendance_change_requests`, and the model keeps it in step with the status |
| `replaces_rate_id` | a request to change an approved rate: the rate it changes (NULL for a new rate) |
| `requested_by`, `decided_by`, `decided_at`, `decision_note` | who asked; who decided, when, and the note. The names follow `attendance_change_requests` (`decided_by` / `decided_at`) rather than the spec's "approved_by / approved_at", because a rejection is recorded in them too. A direct CEO save sets the CEO as both |

The new unique index is created before the old one is dropped, so `employee_id` keeps an index for its foreign key on MySQL. `down()` deletes pending and rejected rows (they never counted), restores the old unique index and drops the columns. Up, down and up were run on SQLite. MySQL was not available here (see Open questions).

Why not the existing `attendance_change_requests` table (the spec 007 precedent for shift assignments)? Its rows are built around a work date, its period filters, the day cards and the nav count of the attendance Approvals page. A rate request there would touch `ApprovalCards`, `pendingEntryCount` and the attendance approval service. On the rates table, a request is one row that becomes the rate itself when approved.

## Case table
| Case | Test (`tests/Feature/Rates/`) | Result |
|---|---|---|
| .1 | `RateOverlapTest::test_s_013_1_new_rate_shortens_a_rate_with_a_future_end` | pass: `✔ S-013.1 a raise from Nov 16 on a "For Probi" rate ending Jun 30, 2027 ends that rate on Nov 15 and is saved` |
| .1 audit | `RateOverlapTest::test_s_013_1_audit_records_the_shortened_end` | pass: `✔ S-013.1 the audit log records the shortened rate's end date, old and new` |
| .2 | `RateOverlapTest::test_s_013_2_open_ended_rate_is_still_shortened` | pass |
| .3 (4) | `RateOverlapTest::test_s_013_3_*` | pass: before-and-overlapping, same start, inside a closed rate (refused); past the closed end (saved) |
| .4 (2) | `RateOverlapTest::test_s_013_4_*` | pass: edit into the rate before shortens it; edit into a later rate refused; amount only |
| .5 (2) | `RateApprovalTest::test_s_013_5_*` | pass: HR and admin pending, current rate 520.00; CEO approved at once |
| .6 | `RateApprovalTest::test_s_013_6_pending_rates_never_reach_payroll` | pass: `✔ S-013.6 pending, rejected and pending-edit rates never reach PayrollService; the approved rate does` |
| .7 (2) | `RateApprovalTest::test_s_013_7_*` | pass: the list; CEO only (403 for HR and admin on list, approve, reject) |
| .8 (3) | `RateApprovalTest::test_s_013_8_*` | pass: approve applies and shortens; re-check at approval; refused when asked |
| .9 | `RateApprovalTest::test_s_013_9_reject` | pass: "Waiting for approval", then "Rejected: Hintayin ang evaluation" |
| .10 | `RateApprovalTest::test_s_013_10_edit_by_hr` | pass |
| .11 (2) | `RateApprovalTest::test_s_013_11_*` | pass |
| .12 | `RateApprovalTest::test_s_013_12_bulk` | pass |
| .13 | `RateApprovalTest::test_s_013_13_audit` | pass |
| .14 | `RateApprovalTest::test_s_013_14_payroll_is_not_recomputed` | pass |

Run: `OK (24 tests, 161 assertions)` (`013-evidence/case-tests-green.txt`).

## Story changes
- Added Slice 013 to `qa/stories.md`: S-013.1–.14 as above, plus S-013.15, the regression row for the full suite.
- Added two owner checks: Rate Approvals on the CEO's account (staging), and the three raises of 2026-11-12 entered again after deploy (production).

## Done-when checklist
- [x] The stories have tests that failed first and then passed.
  - Failing first: `013-evidence/red-tests-before-code.txt` (`Tests: 24, Assertions: 45, Failures: 19.`, with the migration and before any code). The 5 that passed there are the regressions: open-ended still shortened, the two refusals, refused when asked, and edit amount only.
  - Passing: `case-tests-green.txt`.
- [x] Full suite has no new failures vs c2883a4.
  - Baseline: `Tests: 412, Assertions: 3965, Failures: 1` (`baseline-c2883a4.txt`).
  - After: `Tests: 436, Assertions: 4126, Failures: 1` (`full-suite-after.txt`).
  - The one failure is the same in both: `ApprovalsPageTest::test_s_10_1_nav_count_and_list` asserts "Asked … Oct dd, 2026" on a request created now. It is date-bound and was already failing at ad139cc (spec 012 result).
- [x] A test proves pending rates never reach PayrollService (S-013.6).
- [x] The rendered Rate Approvals page and the HR rate list are in `013-evidence/rate-approvals-page.html` and `employee-rate-list-hr.html`.
- [x] Spec saved as `docs/specs/013-rate-approval.md`; this result; the final line.

## How to run
```
git checkout feat/013-rate-approval
composer install
php vendor/bin/phpunit tests/Feature/Rates --testdox
php vendor/bin/phpunit
```
SQLite runs in memory, set by phpunit.xml. The test base adds a test-only trigger to keep `employee_rates` dates as the MySQL DATE column does: the same device as the other date columns in `ApprovalsTestCase::datesAsInProduction`.

## Rulings
- Ruling: a new rate that starts inside a **closed** rate and ends before that rate ends is refused, not shortened. Ending the old rate the day before would drop the rest of it (e.g. Dec 01, 2026 – Jun 30, 2027). And `getActiveRate`, which ignores end dates, would keep paying the short new rate after its end. An **open-ended** rate is still shortened in that case, exactly as before — why: the spec keeps refused "a new rate that would sit entirely inside another closed range". A raise is open-ended or runs past the old end, so the 2026-11-12 case is saved — cost if wrong: a short temporary rate inside a Probi rate needs the Probi rate edited first.
- Ruling: a request is checked when it is saved, as well as at approval — why: the person who asks learns at once that it can never be approved; the approval check is the one that counts — cost if wrong: none; both use the same plan.
- Ruling: HR and admins cannot remove a rate that counts. They can withdraw or remove a request (pending or rejected); the CEO removes rates as before — why: removing a rate changes pay as much as adding one, and the spec makes every staff rate change wait for the CEO. A removal request was not asked for, so it is refused with a message — cost if wrong: HR asks the CEO to remove a wrong rate, or asks for a corrected one.
- Ruling: the bulk "set daily rate" now needs the `daily_rates` edit permission, and follows the same rules: pending for staff, the shortening for the CEO, each employee that cannot be saved skipped with its reason — why: without it the bulk action was a way around both the approval and the overlap check (it had no permission check and no overlap check) — cost if wrong: a role with Employees but without daily_rates edit loses the bulk rate (it could not edit a single rate either).
- Ruling: only one pending change per approved rate. A second change is refused until the first is decided or withdrawn — why: two waiting changes of the same row would overwrite each other on approval — cost if wrong: one more step for HR.
- Ruling: an approved change of a rate is written onto the same rate row, and the request row is removed (without its own "deleted" audit row). Its history is in the `submitted` and `approved` audit rows — why: the rate keeps its id and its update trail, and the list shows one row per rate — cost if wrong: the decided list shows the rate as it is now, not the request.
- Ruling: the old value of a date in a rate's `updated` audit row is now the stored date ("2027-06-30"). Through the trait it was written as UTC, a day early ("2027-06-29T16:00:00Z" for Manila). The fix is opt-in (`$auditRawDates` on `EmployeeRate`); other models are unchanged — why: S1 asks the audit to record the old → new end date, and the existing output showed the wrong old date — cost if wrong: none for rates; the other models still show UTC (Proposed tasks).
- Ruling: the CEO's direct save sets requested_by and decided_by to the CEO. The "Decided" tab lists only rows where they differ — why: it records who saved the rate that counts, and keeps the CEO's own saves out of the decision list — cost if wrong: none.
- Ruling: Pint was not run. The repo has no pint.json, and its files are not Pint-clean (`pint --test` fails on EmployeeController and BiometricFeedService at c2883a4), so Pint would rewrite unrelated lines — why: smallest diff — cost if wrong: style only.

## Deferred minors
- The rate edit row's button still reads "Save" for HR (the add form reads "Send for approval" and the success message says it was sent).
- A rejected request stays on the employee's list until someone removes it. There is no automatic clean-up.

## Merge danger
Two-way door with a migration.
- **Schema.** Seven columns and one swapped unique index on `employee_rates`. Existing rows become approved, so pay is unchanged on deploy.
- **Behaviour change for staff.** From deploy, HR and admin rate saves no longer count until the CEO approves them. The CEO sees them on Rate Approvals.
- **Blast radius if wrong.** Rate saves and approvals. Pay reads only approved rows: a bug in the scope would hide rates (payroll skips an employee with no rate) rather than add unapproved ones. S-013.6 and the whole payroll suite pass.
- **Revert.** `git revert` of the commit and `php artisan migrate:rollback --step=1`. The rollback deletes pending and rejected requests.

## Deploy notes
- `php artisan migrate` (one migration, `2026_11_13_000001_add_approval_to_employee_rates`).
- Then `php artisan view:clear` and `route:clear` (or `optimize:clear`), since there is a new route and new views.
- No config or env change, and no queue or worker restart.
- Tell HR that rate changes now wait for the CEO, and show the CEO the "Rate Approvals" link.

## Tests
- `tests/Feature/Rates/RatesTestCase.php` (fixtures), `RateOverlapTest.php` (8 tests, S1/S3), `RateApprovalTest.php` (16 tests, S2–S5).
- Latest: `OK (24 tests, 161 assertions)`. Full suite: `Tests: 436, Assertions: 4126, Failures: 1` (the date-bound S-10.1, failing at c2883a4 too) vs `Tests: 412, Assertions: 3965, Failures: 1` at c2883a4.

## Open questions
- The migration was run up, down and up on SQLite only; no MySQL server was available here. The statements are plain Laravel Schema (add columns, add a unique index, then drop the old one), the same kind as earlier migrations. Running it on staging first is recommended.
- Should a removal of a rate by HR become a request too, instead of being refused? Today it is refused (Rulings).

## Process suggestions
- Fix or freeze the clock in `ApprovalsPageTest::test_s_10_1_nav_count_and_list`. It fails on any day outside October 2026, so every spec now reports "1 known failure" and a real failure there would be missed.

## Proposed tasks
- Audit log: write the old value of every date field as stored, not as UTC (turn `$auditRawDates` on for all models, or make it the trait's default) — why: today an old date of a shift assignment, status, SIL or day off shows a day early in the audit log — normal.
- Fix the clock of the S-10.1 test (Process suggestions) — why: it hides real failures — normal.

013: done — staff rate changes wait for the CEO on Rate Approvals and count nowhere until approved; a new rate ends the rate it starts in, including one with a future end date
