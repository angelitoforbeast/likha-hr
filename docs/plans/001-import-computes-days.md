# Plan: spec 001 The import computes the attendance days

Status: plan only. No product code is written until the reviewer says "go".
Base: `main` at 572b73a. Branch: `fix/001-import-computes-days`.

## 1. What was found while setting up

### Tests and the base state

- `composer install` ran in this worktree from `composer.lock` (PHPUnit 10.5.63, PHP 8.4.16).
- On the untouched base (no key, MySQL as the configured connection) the suite gives
  `Tests: 2, Assertions: 1, Errors: 1`: `Tests\Unit\ExampleTest` passes,
  `Tests\Feature\ExampleTest::test_the_application_returns_a_successful_response` errors with
  `MissingAppKeyException: No application encryption key has been specified.`
- With the test setup in `phpunit.xml` (sqlite in memory, a random key generated only for tests) it
  gives `Tests: 2, Assertions: 2, Failures: 1`: the same feature test now fails with
  `Expected response status code [200] but received 302.` The route `/` redirects to `/login`, so
  this example test has never matched the app. It is a failure that exists on the base; see open
  question 1.
- `phpunit.xml` is the only file changed so far besides `docs/`.

### Migrations on sqlite

All 44 migrations run on sqlite in memory without any change:
`exit=0 migrated=44 tables=38` (scratch test, since removed). No migration needs a driver guard, and
no migration file is touched by this plan.

### One sqlite difference that matters for the fixtures

The existing compute looks a day up with `where('work_date', '2026-10-05')`. Eloquent writes a
`date` cast as `2026-10-05 00:00:00`; a MySQL `DATE` column cuts that back to `2026-10-05`, a sqlite
column keeps the long form. Measured on sqlite with the base code: computing the same day twice
gives `{"processed":0,"errors":1}` the second time, with
`UNIQUE constraint failed: attendance_days.employee_id, attendance_days.work_date`. On MySQL the
second compute finds the row and updates it.

This is a property of the test database, not of production, and the code that does it
(`computeForEmployee`, `computeDay`) is not to be changed. Consequence for the tests: every day row
that a case says "already exists" is inserted by the test with the date-only value, the way MySQL
holds it. Measured: with such a fixture and an override row, the existing override-aware compute
gives `processed 1`, same row id, the overridden time kept, `source_run_id` set to the run. No case
in the spec needs the same day computed twice through Eloquent-created rows (S-01.2 deletes the day
rows before run 2). New code written for this spec compares dates with `whereDate`, which is right
on both databases.

### What the code does today (only what the plan depends on)

- `ZktecoParserService::parse` builds a fresh `$stats` array, runs `parseUserDat` and
  `parseAttlogDat`, then writes `status = done` and `stats_json = $stats`. Nothing calls the compute.
- `parseAttlogDat` keeps `$existingPunches` only for the lines of the current file. The duplicate
  test is `abs(difference) < 180 seconds`.
- `previewCommit` writes `stats_json` with `json_encode(...)` on a column cast to array. Measured:
  the column then holds `"{\"preview_filter\":{...}}"` and the model returns a **string**.
- The Import page (`resources/views/import/index.blade.php`) prints the Stats cell from
  `$run->stats_json[...] ?? 0`. With the older string form this prints zeros and no error.
- The page's poll calls `/import/{run}/status` every 3 seconds for queued and processing rows and
  **does** rebuild the Stats cell text in JavaScript from `data.stats_json`
  (Users / Logs / Dupes / Unmatched / seconds). So the stats text is refreshed today, but the
  JavaScript template is a second copy of the text and would not show "Computed" unless it is
  extended. See task 6 and open question 3.
- The Import page renders in a test for a signed-in user with role `ceo` (status 200). The layout
  does not use Vite.
- Manual edits go through endpoints that write an `attendance_overrides` row (time fields, shift,
  approved overtime); a manually created day starts blank and gets its times through those same
  endpoints. So the override-aware compute covers hand-corrected days, and locked dates are skipped
  before the compute is called at all.

## 2. Design in short

```
ParseZktecoImport::handle
  └─ ZktecoParserService::parse($run)
       ├─ read the stored stats tolerantly (array, older JSON string, null) → keep preview_filter
       ├─ parseUserDat (unchanged)
       ├─ parseAttlogDat
       │     pass 1: read the file once, collect the matched employees and the first/last punch time
       │     load the stored punches of those employees in that span (± the 3-minute window)
       │     pass 2: the existing loop; duplicates now also match stored punches;
       │             every valid, employee-matched line adds (employee, date) to the list of
       │             employee-days, duplicates included
       ├─ compute step, in its own try/catch
       │     AttendanceComputeService::computeEmployeeDays($employeeDays, $run->id)
       │     → ['days_computed' => n, 'locked_skipped' => n, 'errors' => n]
       │     a throw here is logged and becomes days_computed 0, locked_skipped 0, errors 1
       └─ status = done, stats_json = parse counts + preview_filter + compute
     (parse failure: status = failed, stats_json = parse counts + error + preview_filter, no compute)
```

New public method on `AttendanceComputeService`, added below the existing ones, nothing existing
edited:

```php
/**
 * @param array<int, string[]> $employeeDays  employee id => list of 'Y-m-d'
 * @return array{days_computed:int, locked_skipped:int, errors:int}
 */
public function computeEmployeeDays(array $employeeDays, ?int $sourceRunId = null): array
```

It loads the employees of the list that have status `active` (the others are dropped and counted
nowhere), loads the lock dates inside the span of the list once, and then for each employee-day:
locked → `locked_skipped++`, nothing else happens; otherwise it calls the existing
`computeForEmployee($employee, startOfDay, endOfDay, $sourceRunId, $stats, false)` for that single
day. That existing method already holds the override check, the per-day try/catch, the error count
and the log line `Error computing day for employee #<id> on <date>: ...` (id and date, no name).
`processed` becomes `days_computed`, `errors` becomes `errors`. Reusing it means the import computes
a day exactly as the "Compute Attendance" button does, with one query set per employee-day.

`ZktecoParserService` receives the compute service through its constructor. It is only ever
resolved by the container (the job and the controller methods type-hint it; there is no
`new ZktecoParserService` in the code), so no caller changes.

## 3. Tasks, in order

Each task is: write the failing tests, watch them fail for the stated reason, make the smallest
change, watch them pass, commit.

| # | Task | Files touched | Cases |
|---|---|---|---|
| 1 | Test base: a `Tests\Feature\Import\ImportTestCase` with `RefreshDatabase`, `Storage::fake('local')`, the fixtures (shift, employees A–D, uploader with role `ceo`, dates D1–D3), helpers `attlog(array $lines)`, `fullDay($zk, $date)`, `makeRun($lines, $stats = null)`, `process($run)` (`ParseZktecoImport::dispatchSync`), `storedPunch(...)`, `dayRow(...)` (raw insert, date-only), `lock($date)`, `summary($run)` | `tests/Feature/Import/ImportTestCase.php` | — |
| 2 | Red command: S-01.1 written and run on the base code; the failure line goes into the result file | `tests/Feature/Import/ImportComputesDaysTest.php` | S-01.1 (red) |
| 3 | Compute in the import: `computeEmployeeDays` in the service; the parser collects the employee-days, calls it inside its own try/catch, stores the summary under `compute`. Then the revert-once proof for S-01.1 | `app/Services/AttendanceComputeService.php` (addition only), `app/Services/ZktecoParserService.php`, same test file | S-01.1, S-01.3, S-01.4, S-01.5, S-01.6, S-01.7, S-01.8, S-03.6 |
| 4 | Locks, overrides, inactive staff (the lock and status filters of `computeEmployeeDays`) | `app/Services/AttendanceComputeService.php`, `tests/Feature/Import/ImportRespectsLocksAndOverridesTest.php` | S-02.1 – S-02.6 |
| 5 | Stored punches count as duplicates: pass 1 and the preload in `parseAttlogDat` | `app/Services/ZktecoParserService.php`, `tests/Feature/Import/ImportComputesDaysTest.php` | S-01.2, S-01.9 |
| 6 | Failure never fails the import; stats keep the preview filter: tolerant read of the stored stats in `parse()`, `previewCommit` stores an array | `app/Services/ZktecoParserService.php`, `app/Http/Controllers/ImportController.php` (the `json_encode` call), `tests/Feature/Import/ImportSurvivesComputeFailureTest.php` | S-03.1 – S-03.5 |
| 7 | Import page: the Stats cell shows the compute text; the poll shows the same text | `resources/views/import/index.blade.php`, `app/Http/Controllers/ImportController.php` (`status()`), `app/Models/AttendanceImportRun.php` if approved (open question 2), `tests/Feature/Import/ImportPageShowsComputeTest.php` | S-04.1 – S-04.6 |
| 8 | `qa/stories.md` with the stories, "Test" and "Last run" filled; result file filled with evidence for every done-when item | `qa/stories.md`, `docs/specs/001-import-computes-days.result.md` | all |
| 9 | Review of the diff in a foreground subagent against the threat model (the automatic compute overwriting a hand-corrected or locked day; anything in `attlog.dat` or a stored `stats_json` breaking the job). At most two fix loops; the rest goes to "Deferred minors" | whatever the findings need, within the allowed files | — |

Order reason: task 3 gives the red-to-green for S-01.1 first, as the spec asks. Locks come straight
after (task 4), before anything else, because that is where the data-loss risk sits.

## 4. The test for each case

All import cases run the real job for a run (`ParseZktecoImport::dispatchSync($run->id)`) against a
file written to the faked `local` disk at `imports/{run}/attlog.dat`, then read `attendance_days`,
`attendance_logs` and the run's `stats_json`. Expected numbers are the literals from the case.
Test methods are named with the case id through `#[TestDox('S-01.1 ...')]` and a method name
`test_s_01_1_...`, so `--filter s_01_1` and `--testdox` both find them.

| Case | Given (fixture) | Asserted |
|---|---|---|
| S-01.1 | file: full day A/D1, B/D1, B/D2 | 3 day rows (A/D1, B/D1, B/D2), each `source_run_id` = run id; status `done`; `compute` = 3 / 0 / 0 |
| S-01.2 | run 1 processed with that file, the 3 day rows deleted, run 2 with the same file | 12 log rows for A and B on D1–D2; run 2: `total_duplicates_skipped` 12, `total_logs_inserted` 0; 3 day rows; `days_computed` 3 |
| S-01.3 | file: A at 08:00:00, 08:01:00, 08:02:00 on D1 | 1 log row; A/D1 row exists; `days_computed` 1 |
| S-01.4 | stored punches A/D1 and A/D3 (earlier run), no day rows; file: A on D1 and D2 | rows for A/D1 and A/D2 only; none for A/D3; `days_computed` 2 |
| S-01.5 | stored punches D/D1 and a day row D/D1 (earlier run id, fixed `updated_at`); file: A and B only | D's row: every column equal to before, same `updated_at`, same `source_run_id` |
| S-01.6 | file: one line for zkteco id 99, full day A/D1 | exactly 1 day row (A/D1); `unmatched_zkteco_ids` 1; `compute.errors` 0 |
| S-01.7 | file: blank lines and `garbage<TAB>not-a-date` | status `done`; 0 day rows; `compute` = 0 / 0 / 0 |
| S-01.8 | day row A/D1 from an earlier run, no overrides; file: full day A/D1 | exactly 1 A/D1 row; `source_run_id` = this run |
| S-01.9 | stored punch A/D1 08:00:00 (earlier run); file: A/D1 08:01:30, 12:00:00, 13:00:00, 17:00:00 | `total_duplicates_skipped` 1, `total_logs_inserted` 3; no log row at 08:01:30; A/D1 row exists |
| S-02.1 | day row A/D1 with `time_in` = 08:30:00 (X) and its `attendance_overrides` row on `time_in`; file: full day A/D1 | `time_in` still X; row id unchanged; `days_computed` 1 |
| S-02.2 | D1 locked; file: full day A/D1 and A/D2 | A/D2 row exists; no A/D1 row; `compute` = 1 computed, 1 locked skipped; 4 log rows for A on D1 |
| S-02.3 | D1 locked; day row A/D1 with values V and `updated_at` T; file: A/D1 | row equals V, `updated_at` = T; `compute` = 0 computed, 1 locked skipped |
| S-02.4 | C inactive; file: full day C/D1 and A/D1 | A/D1 row; no C/D1 row; status `done`; 4 log rows for C; `compute` = 1 / 0 / 0 |
| S-02.5 | D1 locked; file: A/D1, B/D1, A/D2 | `locked_skipped` 2; `days_computed` 1; only A/D2 has a row |
| S-02.6 | day row A/D1 with an override, D1 locked; file: A/D1 | day row and override row unchanged (all columns, `updated_at`); `compute` = 0 computed, 1 locked skipped |
| S-03.1 | compute made to throw for A/D1 (see section 5); file: A/D1, A/D2, B/D1 | status `done`; 12 log rows; rows for A/D2 and B/D1, none for A/D1; `compute` = 2 / 0 / 1; exactly one `Log::error` call whose message holds `#<A's id>` and `2026-10-05` |
| S-03.2 | whole compute step made to throw (see section 5); file: full day A/D1 | status `done`; 4 log rows; no `error` key in `stats_json`; `compute.errors` ≥ 1 |
| S-03.3 | run created with `stats_json` = array `preview_filter` (2026-10-05, 2026-10-07, 2, 9, 3) | after the job: `stats_json` is an array; the five values equal; `total_logs_parsed` and `compute` present |
| S-03.4 | the same, stored in the older form (model `update` with `json_encode(...)`, exactly as the code before this spec did) | as S-03.3 |
| S-03.5 | run with a preview filter, no `attlog.dat` written | status `failed`; `stats_json.error` set; the five `preview_filter` values equal; no `compute` key |
| S-03.6 | a successful import (full day A/D1) and an empty one | `compute` has exactly `days_computed`, `locked_skipped`, `errors`, each `is_int` |
| S-04.1 | done run: parse counts + `compute` 37 / 2 / 0; `GET /import` as the uploader (role `ceo`) | 200; the row's Stats cell contains the Users / Logs / Dupes / Unmatched text and `Computed: 37 days, 2 locked skipped` |
| S-04.2 | done run with parse counts only | 200; the cell text equals what the base view prints for that run; no `Computed` |
| S-04.3 | `compute` 0 / 0 / 0 | cell contains `Computed: 0 days`; no `locked skipped`; no `errors` |
| S-04.4 | `compute` 5 / 0 / 3 | cell contains `Computed: 5 days, 3 errors` |
| S-04.5 | queued run with only `preview_filter`, once as array and once in the older string form | 200 both times; no `Computed` |
| S-04.6 | processing run that then gets status `done` and a `compute` summary; `GET /import/{run}/status` | the JSON holds the same `Computed: …` text the page prints; the page's poll script writes that field into the Stats cell (see open question 3 for what this does and does not prove) |

For the S-04 cell assertions the test cuts the one `<td class="run-stats ...">` of the row with
that `data-run-id` out of the response and compares its text with whitespace collapsed, so a
"Computed" in another row cannot make a test pass.

## 5. How the compute is made to fail in S-03.1 and S-03.2

Both use a small subclass of the real service that lives in the test file and is bound in the
container for that one test (`$this->app->bind(AttendanceComputeService::class, ...)`). The job
resolves the parser from the container, and the parser gets the compute service through its
constructor, so the double is picked up with no test hook in product code.

- **S-03.1 (one day fails).** The subclass overrides the public `computeDay(...)` and throws a
  `RuntimeException` when the employee is A and the date is D1; every other call goes to the parent.
  Everything around it is real: `computeEmployeeDays`, the lock and status filters, and the existing
  per-day try/catch in `computeForEmployee`, which counts the error and writes the log line. The log
  is checked with `Log::spy()` and `shouldHaveReceived('error')->once()` with a message that holds
  A's employee id and `2026-10-05`.
- **S-03.2 (the whole step fails).** The subclass overrides `computeEmployeeDays(...)` and throws at
  once. This exercises the try/catch that the parser puts around the compute step: the run must end
  `done`, the punches stay, `stats_json` gets no `error` key, and `compute` is
  `days_computed 0, locked_skipped 0, errors 1`.

Considered and not chosen for S-03.1: a shift whose start time is not a time, effective only on D1,
so that the real code throws by itself. It needs no double, but it depends on which internal line
happens to throw, and that line is in code this spec must not edit.

## 6. Changes per product file

- `app/Services/AttendanceComputeService.php` — one new public method `computeEmployeeDays` (about
  45 lines) placed after `countOverridesInRange`. No line of an existing method changes.
- `app/Services/ZktecoParserService.php` — constructor taking the compute service; `parse()` keeps
  `preview_filter`, runs the compute step and stores `compute`; `parseAttlogDat` gets pass 1, the
  preload of stored punches, and the list of employee-days; one private helper that reads stored
  stats in any of the three forms.
- `app/Http/Controllers/ImportController.php` — `previewCommit`: drop the `json_encode` so an array
  is stored. `status()`: add the compute text to the JSON (task 7).
- `resources/views/import/index.blade.php` — the Stats cell prints the compute text when a summary
  exists; the poll script puts the text from the status response into the cell.
- `phpunit.xml` — sqlite in memory, the test-only key (done), and `LOG_CHANNEL=null` so test runs
  do not write to `storage/logs/laravel.log`.
- Not touched: migrations, `computeMetrics`, `assignTimestamps`, `computeDay`,
  `computeDayWithOverrides`, `recomputeDay`, `computeForDateRange`, `forceRecomputeForDateRange`,
  `computeForEmployee`, the attendance controllers, the job class, the preview page.

## 7. Risks

- **Data loss (the one to watch).** A locked date never reaches the compute: the lock test sits in
  the new method before any call into the existing code, and S-02.3 / S-02.6 assert the row and its
  `updated_at` are untouched. A hand-corrected day reaches the existing override-aware path, as with
  the button (S-02.1).
- **Dates not in the file.** The compute is called for single days from the list only, never for a
  range (S-01.4, S-01.5).
- **Cost.** About five queries per employee-day inside the queued job (timeout 600 s). A month for
  50 employees is about 1,500 employee-days. The duplicate test stays a linear scan per employee, as
  today, now over stored plus file punches of the file's span.
- **Deploy.** The queue worker keeps the old code in memory until it is restarted; until then
  imports behave as before. No migration.

## 8. Amendment 1 (after the first result)

The rule for what is computed became narrower: an employee-day named in the file is computed only when the run stored at least one new punch on it or when it has no day row yet. A day that already has a row and received no new punch is left as it is and counted nowhere. Order of the checks: inactive employee, locked date, then this rule. `computeEmployeeDays` therefore receives, per employee, the dates with a flag for a new punch instead of a plain list. Cases S-01.12 and S-01.13 were added and S-01.8 was rewritten; see the result file.
