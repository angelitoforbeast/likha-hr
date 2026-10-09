# Spec 001: The import computes the attendance days

**Project:** Likha HR — attendance and payroll app: biometric (ZKTeco) punch imports, per-day attendance computed against shifts, an Attendance Calendar with manual overrides and date locks, payroll and payslips.
**Stack:** Laravel 10, PHP 8.4 locally, MySQL in production, Blade + Bootstrap, database queue (one worker runs `queue:work database`).
**Shape:** bug · **Weight:** bounded · **Risk tier:** medium

> Committed with the work as `docs/specs/001-import-computes-days.md`. Names no people and no decision ids: "the owner" decided, "the reviewer" checks and merges.

---

## Why

An admin uploads the biometric file (`attlog.dat`) on the Import page, previews it and commits. The punches are saved, the run shows DONE, and the Attendance Calendar stays empty for those dates. Admins expect the values to appear by themselves.

**The cause is already confirmed** (code at 572b73a, and read-only numbers from production): `ImportController::previewCommit` dispatches the queued job `ParseZktecoImport`, which calls `ZktecoParserService::parse`. That method inserts `attendance_logs` and marks the run done. Nothing in that path calls `AttendanceComputeService`, and git history shows nothing ever did. The calendar reads `attendance_days`, which are created only by the "Compute Attendance" buttons, the `attendance:compute` command, or after a per-cell edit. In production all 8 import runs are done with no error and the queue is healthy; the day rows for the last imports were created 2 hours 18 minutes after the import, by a manual compute, and one date has 9 employees with punches and 0 day rows.

Two more faults sit in the same path and are fixed with it:
- `parseAttlogDat` declares `$existingPunches` "for dedup" but never loads the stored punches, so duplicates are only caught inside one file: importing the same file twice inserts every punch twice (production: two runs of the same file each inserted the same 149 rows).
- `previewCommit` stores the preview filter in `stats_json` with `json_encode(...)` although the model casts that column to array, and `parse()` then overwrites `stats_json` with a fresh array, so the filter is lost.

**Phase 1 — red first.** The investigation is done; do not repeat it. Build the red command: a test that imports a small attlog file for a run and asserts the day rows exist (case S-01.1). Show it fail on the base code for the stated reason. Then the fix: one failing test per case first, smallest change, watch it pass. For S-01.1, revert the fix once to prove the test goes red, restore, re-run. State the confirmed cause in the commit message. Three failed attempts at the same fix = stop and report it as a design problem.

## Stories

Add these to `qa/stories.md` (create it, with the heading `## Slice 001 – Import computes the attendance days`) on this branch.

Fixtures used in every case. Employees: A (zkteco id 1, active), B (id 2, active), C (id 3, status inactive), D (id 4, active, not in the file); each has a shift. Dates: Mon 2026-10-05 (D1), Tue 2026-10-06 (D2), Wed 2026-10-07 (D3). A "full day" in a file means four lines at 08:00:00, 12:00:00, 13:00:00 and 17:00:00. "Day row" means one `attendance_days` row for that employee and `work_date`. "Processed" means the import job ran for that run. No case asserts minutes, lateness or pay: only whether a row exists, is kept, is skipped or is untouched, and the counts.

### S-01 Importing a file fills in the attendance days (P1)
As an admin, I want the days to be computed as soon as an import finishes, so that the Attendance Calendar shows the imported dates without anyone pressing "Compute Attendance".
Independent test: import a file with a full day for A on D1 and for B on D1 into an empty database; both day rows exist and the run is done.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-01.1 | happy | Given no day rows and a file with a full day for A on D1 and for B on D1 and D2, when processed, then 3 day rows exist (A/D1, B/D1, B/D2), each with `source_run_id` = the run id, the run status is `done`, and the compute summary is days computed 3, locked skipped 0, errors 0 | | Not run |
| S-01.2 | happy | Given the same file was already imported as run 1 (its 12 punches are stored) and the 3 day rows were deleted afterwards, when the same file is processed again as run 2, then `attendance_logs` still holds exactly 12 rows for A and B on D1–D2 (none inserted again; run 2 reports 12 duplicates skipped and 0 inserted), the 3 day rows exist again, and the summary says 3 computed | | Not run |
| S-01.3 | edge | Given a file where A has lines at 08:00:00, 08:01:00 and 08:02:00 on D1 (the last two fall within the 3-minute duplicate window) and nothing else, when processed, then one punch is stored, the A/D1 day row exists and the summary says 1 computed | | Not run |
| S-01.4 | edge | Given A has stored punches on D1 and D3 from an earlier run and no day rows, and the new file holds A's punches only on D1 and D2, when processed, then day rows exist for A/D1 and A/D2 only, A/D3 has no row, and the summary says 2 computed | | Not run |
| S-01.5 | edge | Given D has stored punches on D1 and a day row for D1, and the file has only A's and B's lines, when processed, then D's row is unchanged (same values, same `updated_at`, same `source_run_id`) | | Not run |
| S-01.6 | edge | Given the file has a line for zkteco id 99 that matches no employee, plus a full day for A on D1, when processed, then only the A/D1 row is created, `unmatched_zkteco_ids` is 1, and the unmatched id adds no day row and no compute error | | Not run |
| S-01.7 | edge | Given a file with zero valid lines (only blank lines and one line `garbage<TAB>not-a-date`), when processed, then the run is `done`, no day rows exist, and the summary is computed 0, locked skipped 0, errors 0 | | Not run |
| S-01.8 | negative | Given the file lists A/D1 and A already has a day row for D1 from an earlier run (no overrides), when processed, then there is still exactly one A/D1 row, with `source_run_id` = this run | | Not run |
| S-01.9 | edge | Given A has a stored punch on D1 at 08:00:00 from an earlier run, and the new file has A on D1 at 08:01:30, 12:00:00, 13:00:00 and 17:00:00, when processed, then 08:01:30 is skipped as a duplicate of the stored punch (duplicates skipped 1, inserted 3), and the A/D1 day row exists | | Not run |

### S-02 The automatic compute respects overrides, locks and inactive staff (P1)
As an admin, I want the import to leave alone what people have already fixed, locked or deactivated, so that importing a file never undoes manual work or changes a closed date.
Independent test: lock D1, import a file with A on D1 and D2; D2 has a row, D1 has none, and the summary says 1 day computed, 1 locked skipped.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-02.1 | happy | Given A/D1 has a day row with a manual override on one time field (value X, with its `attendance_overrides` row), and the file has a full day for A on D1, when processed, then that field still equals X, the row id is unchanged, and the summary counts it as computed (1) | | Not run |
| S-02.2 | happy | Given D1 is in `attendance_date_locks` and the file has a full day for A on D1 and on D2, when processed, then A/D2 has a row, A/D1 has no row, and the summary says 1 computed, 1 locked skipped. A's punches for D1 are still saved in `attendance_logs` | | Not run |
| S-02.3 | negative | Given D1 is locked and A/D1 already has a day row (values V, `updated_at` T), and the file has A on D1, when processed, then the A/D1 row still has values V and `updated_at` T, and the summary says 0 computed, 1 locked skipped | | Not run |
| S-02.4 | negative | Given C is inactive and the file has a full day for C on D1 and for A on D1, when processed, then A/D1 has a row and C/D1 has none, the run is `done`, C's punches are saved in `attendance_logs`, and the summary says 1 computed, 0 locked skipped, 0 errors (inactive staff are not counted anywhere) | | Not run |
| S-02.5 | edge | Given D1 is locked and the file has A and B on D1 plus A on D2, when processed, then locked skipped is 2 (counted per employee-day) and only A/D2 is computed | | Not run |
| S-02.6 | edge | Given A/D1 has an override and D1 is also locked, when processed, then the override row and the day row are unchanged and the day counts as locked skipped, not computed | | Not run |

### S-03 A compute problem never fails the import (P1)
As an admin, I want the punches to stay saved and the run to stay "done" even if a day can't be computed, so that one bad day doesn't force me to upload the whole file again.
Independent test: make the compute fail for A/D1, import a file with A/D1, A/D2 and B/D1; the run is done, 2 day rows exist, and the summary says errors 1.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-03.1 | happy | Given the compute is made to throw for A/D1 only, and the file has A on D1 and D2 and B on D1, when processed, then the run status is `done`, all punches are in `attendance_logs`, rows exist for A/D2 and B/D1 but not A/D1, the summary says 2 computed, 0 locked skipped, 1 error, and one log entry names the employee id and the date | | Not run |
| S-03.2 | negative | Given the whole compute step throws before any day is computed, when processed, then the run is `done` (not `failed`), the punches are saved, `stats_json` has no top-level `error` key, and the compute summary records at least 1 error | | Not run |
| S-03.3 | happy | Given the run's `stats_json` was set at commit by `previewCommit` (`preview_filter`: date_from 2026-10-05, date_to 2026-10-07, included_count 2, kept_lines 9, dropped_lines 3), when the import finishes, then `stats_json` read through the model is an array whose `preview_filter` still has those five values, next to the parse counts and the compute summary | | Not run |
| S-03.4 | edge | Given a run whose `stats_json` holds the preview filter in the older stored form (a JSON string inside the JSON column, as the code before this spec wrote it), when the import finishes, then `preview_filter` is kept the same way as in S-03.3 | | Not run |
| S-03.5 | edge | Given a run with a preview filter and the parse itself fails (`attlog.dat` missing), when processed, then the run is `failed`, `stats_json.error` is set, `preview_filter` is still there, and there is no compute summary | | Not run |
| S-03.6 | edge | Given a successful import, when the job finishes, then the compute summary has the three keys days computed, locked skipped and errors, each an integer (0 when nothing happened) | | Not run |

### S-04 The Import page shows what the compute did (P2)
As an admin, I want the run's Stats cell to say how many days were computed and how many were skipped, so that I can see at a glance whether the calendar is complete.
Independent test: render the Import page for a done run with a compute summary of 37 computed and 2 locked skipped; the Stats cell contains "Computed: 37 days, 2 locked skipped".

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-04.1 | happy | Given a done run with parse counts and a summary of computed 37, locked skipped 2, errors 0, when the Import page renders for a signed-in user who may see it, then that run's Stats cell still shows the existing "Users … Logs … Dupes … Unmatched …" text and also "Computed: 37 days, 2 locked skipped" | | Not run |
| S-04.2 | negative | Given an older done run whose stats have no compute summary, when the page renders, then the cell is exactly as today, with no "Computed" text and no error | | Not run |
| S-04.3 | edge | Given a summary of computed 0, locked skipped 0, errors 0, when the page renders, then the cell shows "Computed: 0 days" and no "locked skipped" and no "errors" text | | Not run |
| S-04.4 | edge | Given a summary of computed 5, locked skipped 0, errors 3, when the page renders, then the cell shows "Computed: 5 days, 3 errors" | | Not run |
| S-04.5 | edge | Given a queued run whose `stats_json` holds only the preview filter (in the new array form, and again in the older string form), when the page renders, then it renders without error and shows no "Computed" text | | Not run |
| S-04.6 | edge | Given a run that is still processing and then finishes while the page is open, when the page's status poll receives the finished run, then the Stats cell shows the same "Computed: …" text without a reload (the existing poll already refreshes the status badge; if it does not refresh the stats text today, say so in the plan and propose the smallest way) | | Not run |

**Tests:** one failing test per case first, named with its case ID (`S-01.1 …`), at the seam the case describes: run the import job for a run with a given attlog file and database state, then read `attendance_days`, `attendance_logs` and the run's stats; for S-04, an HTTP test that renders the Import page. Expected values come from the case, never recomputed the way the code does it. If the code shows a gap no case covers, put a numbered question in the result file before building it.

## Constraints

**Decisions already made** (settled with the owner or by the reviewer; don't reopen them unless something is actually broken):

| Topic | Decision |
|---|---|
| Where it runs | Inside the same queued import job, right after the punches are saved and before the run is marked done. No new job, no schedule, no new button |
| What is computed | The employee-days that appear in the imported file: every distinct (employee, date) with at least one valid, employee-matched punch line, including lines skipped as duplicates. Dates and employees not in the file are never touched |
| How | The existing override-aware compute, as the "Compute Attendance" button uses it (force = false). The way minutes are calculated is not part of this work |
| Locked dates | A date in `attendance_date_locks` is skipped whole by the automatic compute: no day row created or changed, counted per employee-day |
| Inactive employees | Only employees with status `active` are computed, as the button does; inactive ones are not counted in the summary |
| Run link | Days computed by the import carry the run id in `source_run_id`; for a day with overrides, whatever the existing override-aware compute does with that column stays |
| Failure | A compute failure never makes a successful import `failed`. Per-day errors are counted and logged (employee id and date only, never a name), and the other days go on |
| Stored punches | The parser treats a punch already stored for that employee within the existing 3-minute window as a duplicate, the same rule it applies inside a file. Load only the stored punches of the employees and date span in the file |
| Stats | `stats_json` keeps `preview_filter` and gains the compute summary under one key (suggested: `compute` with `days_computed`, `locked_skipped`, `errors`). `previewCommit` stores an array, not a JSON string; reading tolerates the older string form |
| Page text | English, as the page is. "Computed: N days", then ", M locked skipped" only when M > 0, then ", E errors" only when E > 0 |
| Buttons | The "Compute Attendance" and "Force Recompute" buttons and the `attendance:compute` command keep their behaviour exactly, including that they do not check locks today |
| Schema | No migration is expected. If you think one is needed, stop and ask |

**Test setup.** The repo has only the two example tests, no `vendor/`, no `.env`. Run `composer install` in this worktree (the one download this spec allows; it follows `composer.lock`). Tests use sqlite in memory: enable the two commented `DB_CONNECTION` / `DB_DATABASE` lines in `phpunit.xml` and give the test run an application key that exists only for tests (generated by you, in `phpunit.xml`; never a real key). 12 of the 44 migrations contain MySQL-specific statements (`DB::statement`, raw `MODIFY`, enums). First find out whether the full set migrates on sqlite. If it does not, do not rewrite migrations on your own: list the failing ones in the plan with the smallest proposal (for example a driver guard around a MySQL-only statement that changes nothing on MySQL) and wait for the "go". Production data and the production schema are never part of a test.

**Threat model.** Untrusted input (validate, never trust, treat as data): the contents of the uploaded `attlog.dat` (any line, any id, any timestamp), and the `stats_json` already stored on a run (it may be a string, an array or null). Trusted (no hardening loops; a finding here is a minor unless it can lose data): files in this repo, config, rows written by signed-in admins (shifts, locks, overrides, employee status). The data-loss risk to watch: the automatic compute overwriting a day that someone corrected by hand or that is locked. Medium = business logic on trusted paths. A major needs a one-line realistic scenario. Fix loops stop after two; remaining findings are accepted with a reason in the result's "Deferred minors" unless they are a security or data-loss major, which goes to the reviewer as a question.

**Rules**
- Never `git push`. Never ssh. No remote database. No real `.env` or key read, created or printed.
- Don't start other Claude Code sessions; use subagents inside this session, in the foreground.
- Plan first, then build after the reviewer's "go".
- No `Co-Authored-By`, `Claude-Session` or "Generated with Claude Code" lines in commit messages.
- Code comments explain the reason itself. Comments, commit messages and branch names never name people or decision ids; the spec number may appear in branch names and commits.
- No commands that start with an environment variable (`FOO=1 php ...`): put the setting in `phpunit.xml`.
- Downloads: `composer install` in this worktree only. No npm install is needed (Blade only, no asset build).
- Build for the least skilled user: nothing new to click, nothing new to remember; the page says in a few words what happened.
- Keep the existing public behaviour of `AttendanceComputeService::computeForDateRange`, `forceRecomputeForDateRange`, `recomputeDay` and the controllers that call them. Add to the service; don't change what the buttons do. `computeMetrics` and `assignTimestamps` are not edited.
- Employee names, pay and punch times of real people never appear in tests, fixtures, logs or the result; use invented employees.

## Budget

- Attempts: at most 2 tries at the same step; then stop and report what you tried and what you need.
- Size: small job (one service path, one controller line, one Blade cell, tests). If it is turning out much bigger (for example the migrations do not run on sqlite without touching many files), stop and report before going on.

## Done when

- [ ] Cases S-01.1–S-01.9, S-02.1–S-02.6, S-03.1–S-03.6 and S-04.1–S-04.6 pass, each with a test named after it.
- [ ] The result has a red line (test name, first failure line) for S-01.1 on the base code, watched before the fix, and the revert-once proof for S-01.1.
- [ ] The whole suite runs with `php.bat vendor/phpunit/phpunit/phpunit` and adds no new failures compared with the base (state how the two example tests stand on the base).
- [ ] `git diff main -- app/Services/AttendanceComputeService.php` shows no change inside `computeMetrics`, `assignTimestamps`, `computeDay`, `computeDayWithOverrides`, `recomputeDay`, `computeForDateRange` or `forceRecomputeForDateRange` (additions elsewhere in the file are fine); if a change there turns out to be unavoidable, it is a question first.
- [ ] `git diff main --stat` lists only: the parser service, the compute service, the import controller, the import index view, `phpunit.xml`, tests, `qa/stories.md`, `docs/`, and anything the reviewer approved in the plan.
- [ ] Nothing was pushed: `git branch -r` shows no branch for this work and `git log origin/main..main` is unchanged from the start.
- [ ] `qa/stories.md` has this spec's stories with "Test" and "Last run" filled.
- [ ] The result file is filled in, including "Merge danger" and what production needs after the code lands (for example that the queue worker must be restarted to load the new code, and that no migration runs).

## Out of scope

- Removing the duplicate punches already stored in production.
- Computing the days of earlier imports (a one-time catch-up is the owner's call).
- Making the Compute buttons or the command respect date locks.
- Any change to the Attendance Calendar page, the preview page, payroll or how minutes are calculated.
- The deploy itself, the server, the queue worker's configuration.
- Installing tooling, linters or CI.

## Report back

Fill in `docs/specs/001-import-computes-days.result.md` from its template and commit it with the work, including every ruling you made (`Ruling: <decision> — <why> — <cost if wrong>`; an unrecorded deviation is a secret decision) and the deferred minors. There is no pull request for this spec: the reviewer reads the branch, merges it locally and deploys. In "Branch / PR" give the branch and its head commit. "Merge danger" says: one-way or two-way door, blast radius, how to revert.
