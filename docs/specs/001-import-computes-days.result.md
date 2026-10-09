# Result: spec 001 The import computes the attendance days

> Committed beside the spec: names no people and no decision ids.

**Status:** done
**Date:** 2026-10-09
**Branch / PR:** `fix/001-import-computes-days`; head is the last commit on the branch, the one that updates this result for amendment 1 (`docs: update the result of spec 001 for amendment 1`); the commit before it is `44f74cb`. No pull request for this spec.
**Preview or run link:** n/a (nothing is deployed; the tests run locally)

## Summary

The import job now computes the attendance days itself. `ZktecoParserService::parse` collects every
employee and date that has a valid, matched line in the file and, after the punches are saved and
before the run is marked done, hands that list to the new
`AttendanceComputeService::computeEmployeeDays`. That method skips locked dates and inactive
employees, leaves alone a day that already has a row and received no new punch (amendment 1), and
sends each remaining day through the existing override-aware compute, one day at a time, so nothing
outside the file is touched. In the same path, punches already stored now count as
duplicates (three-minute rule), impossible timestamps are invalid lines, the run's stats keep the
preview filter and gain a `compute` summary, and a compute problem can no longer turn a saved import
into a failed one. The Import page shows "Computed: N days, M locked skipped, E errors" in the Stats
cell, and the status poll receives the same text from the server.

## Case table

Output lines are from `php.bat vendor/phpunit/phpunit/phpunit --no-coverage --testdox`, last run
2026-10-09: `OK (41 tests, 196 assertions)`. Test classes are in `tests/Feature/Import/`.

| Case | Test | Result |
|---|---|---|
| S-01.1 | `ImportComputesDaysTest::test_s_01_1_import_creates_the_day_rows_of_the_file` | pass: `✔ S-01.1 a file with A on D1 and B on D1 and D2 gives three day rows linked to the run` |
| S-01.2 | `ImportComputesDaysTest::test_s_01_2_same_file_twice_inserts_nothing_again` | pass: `✔ S-01.2 importing the same file again stores no punch twice and computes the days again` |
| S-01.3 | `ImportComputesDaysTest::test_s_01_3_duplicates_inside_the_file_still_compute_the_day` | pass: `✔ S-01.3 lines within the duplicate window store one punch and still compute the day` |
| S-01.4 | `ImportComputesDaysTest::test_s_01_4_dates_outside_the_file_are_not_computed` | pass: `✔ S-01.4 only the dates in the file are computed, not other dates with stored punches` |
| S-01.5 | `ImportComputesDaysTest::test_s_01_5_employee_not_in_the_file_is_untouched` | pass: `✔ S-01.5 the day row of an employee who is not in the file is left untouched` |
| S-01.6 | `ImportComputesDaysTest::test_s_01_6_unmatched_id_adds_no_day_and_no_error` | pass: `✔ S-01.6 an unmatched device id adds no day row and no compute error` |
| S-01.7 | `ImportComputesDaysTest::test_s_01_7_file_without_valid_lines` | pass: `✔ S-01.7 a file with no valid line finishes done with nothing computed` |
| S-01.8 | `ImportComputesDaysTest::test_s_01_8_existing_day_row_with_new_punches_is_recomputed_in_place` | pass: `✔ S-01.8 an existing day row that receives new punches is recomputed in place, not duplicated` |
| S-01.9 | `ImportComputesDaysTest::test_s_01_9_stored_punch_makes_a_file_punch_a_duplicate` | pass: `✔ S-01.9 a file punch within three minutes of a stored punch is skipped as a duplicate` |
| S-01.10 | `ImportComputesDaysTest::test_s_01_10_impossible_timestamp_is_an_invalid_line` | pass: `✔ S-01.10 a line whose timestamp is not a real time is skipped and the run goes on` |
| S-01.11 | `ImportComputesDaysTest::test_s_01_11_date_without_a_stored_punch_is_not_counted` | pass: `✔ S-01.11 a date on which no punch is stored gets no row and is counted nowhere` |
| S-01.12 | `ImportComputesDaysTest::test_s_01_12_day_with_a_row_and_no_new_punch_is_untouched` | pass: `✔ S-01.12 a day that has a row and receives no new punch is left exactly as it is` |
| S-01.13 | `ImportComputesDaysTest::test_s_01_13_day_without_a_row_is_computed_even_without_new_punches` | pass: `✔ S-01.13 a day whose punches are all stored but that has no row yet is computed` |
| S-02.1 | `ImportRespectsLocksAndOverridesTest::test_s_02_1_overridden_field_is_kept` | pass: `✔ S-02.1 a time corrected by hand keeps its value when the day is imported again` |
| S-02.2 | `ImportRespectsLocksAndOverridesTest::test_s_02_2_locked_date_is_skipped` | pass: `✔ S-02.2 a locked date gets no day row, the other date is computed, the punches are saved` |
| S-02.3 | `ImportRespectsLocksAndOverridesTest::test_s_02_3_existing_row_on_a_locked_date_is_untouched` | pass: `✔ S-02.3 an existing day row on a locked date is not changed` |
| S-02.4 | `ImportRespectsLocksAndOverridesTest::test_s_02_4_inactive_employee_is_not_computed` | pass: `✔ S-02.4 an inactive employee is not computed and not counted, the punches are saved` |
| S-02.5 | `ImportRespectsLocksAndOverridesTest::test_s_02_5_locked_days_are_counted_per_employee_day` | pass: `✔ S-02.5 locked days are counted per employee and day` |
| S-02.6 | `ImportRespectsLocksAndOverridesTest::test_s_02_6_override_on_a_locked_date_is_untouched` | pass: `✔ S-02.6 an overridden day on a locked date counts as locked skipped and is not changed` |
| S-03.1 | `ImportSurvivesComputeFailureTest::test_s_03_1_one_failing_day_does_not_stop_the_others` | pass: `✔ S-03.1 one day that cannot be computed is counted and logged, the other days go on` |
| S-03.2 | `ImportSurvivesComputeFailureTest::test_s_03_2_failing_compute_step_does_not_fail_the_import` | pass: `✔ S-03.2 a compute step that fails as a whole leaves the run done with the punches saved` |
| S-03.3 | `ImportSurvivesComputeFailureTest::test_s_03_3_preview_filter_is_kept` | pass: `✔ S-03.3 the preview filter stored at commit is still in the stats when the import finishes` |
| S-03.4 | `ImportSurvivesComputeFailureTest::test_s_03_4_preview_filter_in_the_older_form_is_kept` | pass: `✔ S-03.4 a preview filter stored in the older string form is kept as well` |
| S-03.5 | `ImportSurvivesComputeFailureTest::test_s_03_5_failed_parse_keeps_the_preview_filter` | pass: `✔ S-03.5 a failed parse keeps the preview filter, sets the error and has no compute summary` |
| S-03.6 | `ImportSurvivesComputeFailureTest::test_s_03_6_summary_has_three_integer_keys` | pass: `✔ S-03.6 the compute summary always has its three integer keys` |
| S-04.1 | `ImportPageShowsComputeTest::test_s_04_1_cell_shows_computed_and_locked_skipped` | pass: `✔ S-04.1 the Stats cell keeps its text and adds the computed and locked skipped counts` |
| S-04.2 | `ImportPageShowsComputeTest::test_s_04_2_run_without_summary_is_unchanged` | pass: `✔ S-04.2 a run without a compute summary shows the cell exactly as before` |
| S-04.3 | `ImportPageShowsComputeTest::test_s_04_3_zero_summary` | pass: `✔ S-04.3 a summary of zeros shows only "Computed: 0 days"` |
| S-04.4 | `ImportPageShowsComputeTest::test_s_04_4_errors_without_locked_skipped` | pass: `✔ S-04.4 errors are shown and a zero locked skipped count is left out` |
| S-04.5 | `ImportPageShowsComputeTest::test_s_04_5_queued_run_with_only_the_preview_filter` | pass: `✔ S-04.5 a queued run with only the preview filter renders without "Computed", in both stored forms` |
| S-04.6 | `ImportPageShowsComputeTest::test_s_04_6_status_poll_carries_the_compute_text` | pass: `✔ S-04.6 the status poll receives the same "Computed" text when a run finishes` — the poll running in a browser is not run by a test |
| S-04.7 | `ImportPageShowsComputeTest::test_s_04_7_one_day_is_singular` | pass: `✔ S-04.7 one computed day is shown in the singular` |

Tests without a case id (added while building, all pass):

- `ImportCommitStoresFilterTest::test_commit_stores_the_preview_filter_as_an_array` — the controller line of the fix; red against the base controller: `Failed asserting that '{"preview_filter":{"date_from":"2026-10-05",...}}' is identical to Array`.
- `ImportCommitStoresFilterTest::test_commit_runs_the_import_and_computes_the_days` — commit, job and compute end to end through `POST /import/preview/commit`.
- `ImportComputesDaysTest::test_duplicate_window_edges_against_stored_punches` — 179 s is a duplicate, 180 s is not, another employee's punch at the same time is not.
- `ImportComputesDaysTest::test_second_import_recomputes_only_rows_with_a_new_punch` — an overlapping second import updates in place the row that got a new punch and leaves the other row as it was.
- `ImportRespectsLocksAndOverridesTest::test_lock_is_checked_before_the_new_punch_rule` — a locked day with a row and no new punch counts as locked skipped.
- `ImportComputesDaysTest::test_far_future_timestamp_does_not_fail_the_run` — year 9999 and year 0999 lines.
- `ImportPageShowsComputeTest::test_malformed_stored_summary_does_not_break_the_page` — stored stats that are not a summary, have wrong types, or are not JSON.

## Story changes

- **S-01.10 added** (edge): a line whose timestamp has the right shape but is not a real time is skipped as an invalid line and the run goes on. Reason: the reviewer's answer to open question 5.
- **S-01.11 added** (edge): a date on which no punch is stored gets no row and is counted neither as computed nor as an error. Reason: the reviewer's answer to open question 6.
- **S-04.7 added** (edge): one computed day reads "Computed: 1 day". Reason: the reviewer's answer to open question 4.
- **S-03.2 Then tightened**: "records at least 1 error" became "is computed 0, locked skipped 0, errors 1". Reason: that is exactly what the code stores, and the looser wording would let a wrong count pass.
- **S-01.8 Given and Then changed** (amendment 1): A now has two stored punches on D1 besides the day row, and the file brings two new ones; Then adds "two punches are inserted and two skipped as duplicates" and "the summary says 1 computed". Reason: under the narrower rule a day with a row is recomputed only when it receives a new punch, so the case has to say that it does.
- **S-01.12 added** (negative, amendment 1): a day that has a row and whose punches are all already stored is left exactly as it is (values, `updated_at`, `source_run_id`) and the summary is 0 / 0 / 0. Reason: the reviewer's decision on what is computed.
- **S-01.13 added** (edge, amendment 1): a day whose punches are all already stored but that has no row is computed, next to an unchanged day of another employee. Reason: the same decision, second half.
- Checked and unchanged under the narrower rule: S-01.2 (the rows were deleted, so all three are computed), S-01.5, S-02.1 (the file brings new punches to the overridden day), S-02.3 and S-02.6 (locked, checked first). No other fixture needed a change.
- **S-04.6 Then reworded**: the note to the developer in brackets was replaced by "(the text comes from the status response; the poll running in a browser is checked by hand)". Reason: the reviewer's answer to open question 3; the instruction to the developer does not belong in the stories.

## Done-when checklist

- [x] Cases S-01.1–S-01.9, S-02.1–S-02.6, S-03.1–S-03.6 and S-04.1–S-04.6 pass, each with a test named after it. — see the case table (plus S-01.10 to S-01.13 and S-04.7); `OK (41 tests, 196 assertions)`. For S-04.6 the poll running in a browser is not run by a test.
- [x] The result has a red line for S-01.1 on the base code, watched before the fix, and the revert-once proof.
  - Red on the base (product code at 572b73a, commit `85618e9` holds only the test): `php.bat vendor/phpunit/phpunit/phpunit --no-coverage --filter s_01_1` → `1) Tests\Feature\Import\ImportComputesDaysTest::test_s_01_1_import_creates_the_day_rows_of_the_file` / `the import must create the day rows of the employee-days in the file` / `Failed asserting that two arrays are identical.` (expected the three employee-days, actual `Array &0 []`) / `Tests: 1, Assertions: 1, Failures: 1.`
  - Revert once (after commit `ac4eb51`, the call `$stats['compute'] = $this->computeImportedDays(...)` in `ZktecoParserService::parse` commented out): same command → the same failure, `Tests: 1, Assertions: 1, Failures: 1.`
  - Restored with `git checkout -- app/Services/ZktecoParserService.php`, same command → `OK (1 test, 6 assertions)`.
- [x] Amendment 1, red first for S-01.8 (new form), S-01.12 and S-01.13. — on the code before the change (commit `6196d05` holds only the tests): `Tests: 40, Assertions: 182, Failures: 3.` S-01.12: `✘ S-01.12 a day that has a row and receives no new punch is left exactly as it is` / `Failed asserting that two arrays are identical.` (the row was recomputed: `time_in` 08:10:00 → 08:00:00, `notes` 'kept as it was' → null, `source_run_id` 1 → 2, `updated_at` changed). S-01.13: red for the same reason (A's row was recomputed). The third failure was the test of a second overlapping import, which expected the old rule and was rewritten with the amendment. S-01.8 in its new form passed before the change and passes after: its day receives new punches, which both rules compute, so it cannot be made red without breaking the code; it is the guard that the narrower rule still recomputes such a day. After the change (commit `771eb89`): `OK (41 tests, 196 assertions)`.
- [x] The whole suite runs with `php.bat vendor/phpunit/phpunit/phpunit` and adds no new failures compared with the base. — now `OK (41 tests, 196 assertions)`. On the base the suite was red: `Tests: 2, Assertions: 1, Errors: 1.` (`Tests\Feature\ExampleTest` → `MissingAppKeyException`, no key), and with a test key `Tests: 2, Assertions: 2, Failures: 1.` (`Expected response status code [200] but received 302.`, because `/` has always redirected to `/login`). The example test was corrected in its own commit (`7f43920`), as the reviewer decided.
- [x] `git diff main -- app/Services/AttendanceComputeService.php` shows no change inside the seven named methods. — `git diff main --numstat` for the file: `85	0` (85 lines added, none removed). Two hunks: `@@ -2,6 +2,7 @@` (one `use` line) and `@@ -525,6 +526,90 @@ public function countOverridesInRange` (the new `computeEmployeeDays` at line 548 and its helper `dayRowExists` at line 605, after `countOverridesInRange` and before `computeMetrics`).
- [x] `git diff main --stat` lists only the allowed files. — `app/Http/Controllers/ImportController.php`, `app/Models/AttendanceImportRun.php` (approved in the plan review), `app/Services/AttendanceComputeService.php`, `app/Services/ZktecoParserService.php`, `docs/plans/…`, `docs/specs/…` (2), `phpunit.xml`, `qa/stories.md`, `resources/views/import/index.blade.php`, `tests/Feature/ExampleTest.php`, `tests/Feature/Import/…` (6). 17 files, nothing else.
- [x] Nothing was pushed. — `git branch -r` prints only `origin/HEAD -> origin/main` and `origin/main`; `git log origin/main..main` prints nothing, as at the start. No push, ssh or remote database command was run.
- [x] `qa/stories.md` has this spec's stories with "Test" and "Last run" filled. — 32 rows, each with its test and `Passed · 2026-10-09 · auto`; the summary line reads `Slice 001: 32 passed, 0 failed, 0 blocked, 0 skipped, 0 not run`; the owner check for S-04.6 is listed under "Owner checks".
- [x] The result file is filled in, including "Merge danger" and what production needs. — this file.

## How to run

In Git Bash, from a fresh clone of the branch:

```
/c/Users/Forbeast/.config/herd/bin/composer.bat install
/c/Users/Forbeast/.config/herd/bin/php.bat vendor/phpunit/phpunit/phpunit
/c/Users/Forbeast/.config/herd/bin/php.bat vendor/phpunit/phpunit/phpunit --no-coverage --testdox
```

No `.env` is needed: the database (sqlite in memory), the test-only key and the log channel come
from `phpunit.xml`. One case: add `--filter s_01_1_` (the trailing underscore keeps S-01.10 and
S-01.11 out).

## Rulings

Answers of the reviewer to the open questions of the plan run:

Ruling: the example feature test is corrected to expect the redirect to `/login`, in its own commit — reviewer's answer to question 1; it was red on the base because `/` has always redirected — none.

Ruling: `app/Models/AttendanceImportRun.php` may be touched for two read-only methods, `statsArray()` and `computeSummaryText()`, and nothing else — reviewer's answer to question 2; one reader and one text for the parser, the page and the status endpoint — none; the file joins the allowed files.

Ruling: for S-04.6 the status endpoint returns the compute text built by the same function as the page, the poll appends that field, and the test proves the endpoint text and that the page script reads the field — reviewer's answer to question 3 — the poll in a real browser is unproven until it is checked by hand after release.

Ruling: one computed day reads "Computed: 1 day"; case S-04.7 added — reviewer's answer to question 4 — none.

Ruling: a line whose timestamp is not a real time is an invalid line: skipped, counted in "parsed" only, the run goes on; case S-01.10 added — reviewer's answer to question 5 — a file that used to fail halfway now finishes without that line.

Ruling: a date on which no punch is stored gets no row and is counted neither as computed nor as an error; case S-01.11 added — reviewer's answer to question 6 — none.

Decision of the reviewer after the first result (amendment 1):

Ruling: an employee-day named in the file is computed only when this run stored at least one new punch for that employee on that date, or when that employee has no day row for that date; a day whose punches were all already stored and that already has a row is left exactly as it is and counted nowhere. Order of the checks: inactive employee, locked date, then this rule — reviewer's decision, replacing "every employee-day in the file": the import runs unattended and a file usually covers the last 30 days, so recomputing unchanged days gains nothing for the calendar and would silently change earlier days whenever a shift assignment or the calculation changed in between; refreshing them stays a deliberate act, the "Compute Attendance" button — a day with a stale row and no new punch stays stale until someone presses the button.

Ruling: the log entry for a failed compute step holds the run id and the exception class, not the exception message — reviewer's fix list: the message of a database error can carry query text — a failure is harder to diagnose from the log alone; the class and the run id point to where to look.

Choices made while building:

Ruling: whether a day received a new punch is decided per employee and calendar date of the punch, in the parser, at the moment a punch is accepted for insertion; the day-row check uses `whereDate` and runs only for days without a new punch — the parser is the only place that knows which lines were duplicates; the check is right on a MySQL `DATE` column and on sqlite — one extra query per unchanged employee-day.

Ruling: `computeEmployeeDays` now takes, per employee, the dates with a flag for "received a new punch" instead of a plain list of dates — the service needs the flag to apply the rule in the decided order (lock first) — callers other than the import do not exist.

Ruling: the application key in `phpunit.xml` is 32 random bytes generated on this PC for the tests — the spec asks for a key that exists only for tests — none: it protects nothing and is used nowhere else.

Ruling: `LOG_CHANNEL=null` in `phpunit.xml` — test runs otherwise append error traces to `storage/logs/laravel.log` — none for production.

Ruling: day rows that a case says "already exist" are inserted by the tests with the date-only value, the way a MySQL `DATE` column holds it — on sqlite the model stores a time part and the existing compute, which must not be edited, then does not find the row — a test could pass for a row shape production never has; the shape chosen is the production one.

Ruling: all case tests were written and watched red against the base product code in one run (`Tests: 33, Assertions: 83, Errors: 5, Failures: 24.`), then the fix followed in the plan's order — one red run for all cases is cheaper than one per task and still shows each case failing first — S-04.2 and S-04.5 pass on the base as well, because they describe behaviour that must stay; they were never red.

Ruling: the lock and inactive filters (S-02) landed in the same commit as the compute call (S-01), not in a separate one — they are a few lines of the one new method, and an intermediate commit without the lock check would be the data-loss version of the feature — the history shows one commit for tasks 3 and 4.

Ruling: one error reads "1 error", two or more "N errors" — follows the singular decided for "1 day"; the decision table only says ", E errors" — a reader who expects "1 errors" sees "1 error".

Ruling: the compute text sits on a second line of the Stats cell, after the existing counts — the existing line stays readable and unchanged — purely visual.

Ruling: the real-timestamp check is applied in the import only, not in the preview summary or in the filter that writes the run's file — the preview page is out of scope — a line dated 30 February can still be shown by the preview as 2 March and reach the run's file; the import then drops it as invalid, so no punch and no day come from it.

Ruling: a year before 1000 is an invalid line, and the end of the stored-punch lookup is capped at `9999-12-31 23:59:59` — the `DATETIME` column of the punches cannot hold or compare values outside that range (review finding) — a device with such a clock loses those lines; before, they failed the whole run on MySQL.

Ruling: "days computed" is the number of days the existing compute reports as processed — reuses its own count instead of counting again — a day whose compute fails is in "errors", never in both.

Ruling: tests beyond the cases were added (the commit step, the window edges, a second import over the same rows, extreme years, malformed stored stats) — each pins a line of the fix or a review finding that no case covers — more tests to maintain.

## Deferred minors

One review of the diff ran in a subagent against the threat model; it reported no major. Three findings were fixed in one loop (stored punches read one row at a time, the year range, the loose tests). Accepted without a fix:

- **A job that is killed (time limit or memory) leaves the run `processing` with no stats.** This existed before for the parse; the compute adds about five queries per employee-day to the same job. The punches saved so far stay. Not fixed because the natural fix is a `failed()` hook on the job class, which is outside the allowed files; the commit step also limits the file to the chosen dates and employees. Listed under "Proposed tasks".
- **An override row without `attendance_day_id` is not seen by the override-aware compute.** The existing compute finds overrides by that column only; it was added by a later migration without a backfill. Every code path today sets it, so only rows older than that migration could be affected. The "Compute Attendance" button has the same behaviour; the method must not be edited. It matters more now because the import computes unattended, so it is a check before release (see "Merge danger").
- **The duplicate check is a linear scan per employee**, as before, now over stored and file punches of the file's span. Fine for a pay period; slow only for a file spanning years.
- **The preview still treats 30 February as 2 March** (see the ruling above). No data is stored from such a line.
- **`GET /import/{run}/status` has no check on the uploader's role**, unlike the page. Not new; it now also returns the compute text (three counts, no names). Listed under "Proposed tasks".

## Merge danger

**Two-way door for the code**: no migration, no schema change, no config change. Revert by reverting
the merge (or resetting `main` to 572b73a) and restarting the queue worker.

**What does not come back with a revert**: day rows that imports wrote while the new code was live.
They are the rows the "Compute Attendance" button would have written for the same days, so they are
correct data, but they are not removed by reverting the code.

**Blast radius if wrong**: every import that runs after the worker loads the new code, but only two
kinds of days in its file, for active employees on dates that are not locked: a day that received at
least one new punch from this run, and a day that has no day row yet. Such a day is created or
recomputed from the stored punches; with override rows it keeps its overridden fields (existing
behaviour), without them it is recomputed in full, exactly as the button does. A day that already
has a row and received no new punch is not written at all, so importing a file that overlaps earlier
imports cannot change days computed before. Locked dates and days outside the file are never
written. Payroll reads these rows, so a wrong compute would show up in payroll for open dates that
got new punches or had no row.

**What production needs after the code lands**

1. No migration runs. No new environment setting.
2. **Restart the queue worker** (`php artisan queue:restart`, or restart the service that runs `queue:work database`). The worker keeps the old code in memory; until it restarts, imports behave as before and the page shows no "Computed" text for them.
3. Before the first import, one read-only check: count the rows of `attendance_overrides` where `attendance_day_id` is null. Expected 0. If there are any, those hand corrections are invisible to the compute (button and import alike) and should be linked to their day rows first.
4. In a browser, once: start an import and watch the Import page without reloading; when the badge turns DONE the Stats cell must show the "Computed: …" line (S-04.6, the part no test runs).
5. Not done by this change, as the spec says: earlier imports are not computed, and duplicate punches already stored are not removed. Importing a file again no longer adds punches; it computes the days of that file that have no day row yet and leaves the days that already have one as they are.

## Conflicts with CLAUDE.md

none (the repo has no CLAUDE.md).

## Tests

- 41 tests, 196 assertions, all pass: `OK (41 tests, 196 assertions)`.
- 32 case tests (S-01.1–S-01.13, S-02.1–S-02.6, S-03.1–S-03.6, S-04.1–S-04.7), 7 tests without a case id, 2 example tests.
- The import cases run the real job (`ParseZktecoImport::dispatchSync`) against a file on a faked disk and a migrated sqlite database in memory, then read `attendance_days`, `attendance_logs` and the run's stats. The page cases render `GET /import` as a signed-in user and compare the text of that run's Stats cell. S-03.1 and S-03.2 bind a subclass of the compute service that throws, as planned.
- Red before the fix, all case tests against the base product code: `Tests: 33, Assertions: 83, Errors: 5, Failures: 24.` Every case failed except S-04.2 and S-04.5.
- Not covered by a test: the poll script running in a browser; MySQL itself (all tests run on sqlite; the new queries use `whereDate` and plain datetime strings, which behave the same on both).
- All 44 migrations run on sqlite unchanged; no migration was touched.

## Open questions

None open. The six questions of the plan run are answered; the answers are recorded under "Rulings".

## Process suggestions

- The spec expects that some of the 12 migrations with MySQL-specific statements may fail on sqlite; none do. The real sqlite trap is that `date` casts are stored with a time part, so the existing compute cannot find a row it created itself (`UNIQUE constraint failed: attendance_days.employee_id, attendance_days.work_date`). Worth one line in the test setup of later specs for this repo.
- The allowed-file list had no place for a shared reader of `stats_json` (the model) or for the job class; the first became an open question, the second left the "stuck in processing" finding without a fix. Listing "files that may be touched if the plan argues for it" would save a round.
- The red-first rule per case and the frozen-method list worked well together: the diff of the compute service is additions only, which made the fourth done-when item a one-line check.

## Proposed tasks

- Mark a run failed when its job is killed (a `failed()` hook on `ParseZktecoImport`, and the parse counts saved before the compute step) — a run that hits the time limit stays "processing" for ever — normal.
- Check for override rows without a day id and link them — they are invisible to the override-aware compute, which now also runs unattended — normal (a one-line read-only check first; nothing to do if the count is 0).
- One-time compute of the days of earlier imports — the calendar is still empty for dates imported before this change — normal (the owner's call, out of scope here).
- Remove the duplicate punches already stored — earlier double imports left every punch twice; the compute ignores exact duplicates but the punch lists show them — low (out of scope here).
- Role check on `GET /import/{run}/status` — the page hides runs by the uploader's role, the endpoint does not — low.
- Use the real-timestamp rule in the preview and in the commit filter too — the preview can show a 30 February line as 2 March — low.

## Suggested next steps

1. Review the branch and merge it locally.
2. Run the read-only override check from "Merge danger" before release.
3. Deploy, restart the queue worker, and do the one browser check for S-04.6.
4. Decide on the one-time compute for earlier imports.
