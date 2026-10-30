# Result: spec 009 overnight time-out

**Status:** done (case .4 is an open question: the app has no night-differential computation)
**Date:** 2026-10-31
**Branch / PR:** `feat/009-overnight-timeout`, from main 89b9676. Not pushed; no PR.
**Preview or run link:** n/a. Nothing is deployed. The rendered HTML, test runs and repair output are in `docs/specs/009-evidence/`.

## Summary
- **Which punches a date uses** (`AttendanceComputeService::workDatePunches`). It walks an employee's dates in order.
  - A date takes the last of the next date's punches inside the overnight window as its time out, when its own punches give a time in and no time out.
  - Every punch of that window then leaves the next date. The next date's time in is its own first punch after the window.
  - The walk starts at the nearest earlier date with no punch before 06:00, at most 31 days back. A run of nights in a row is therefore decided the same whether computed as a range, one day at a time, or by the import.
  - The taken punch travels in the start date's punch list. `assignTimestamps` lets it fill only the time-out slot.
- **A time out typed by hand** (an override) that is before the time in and inside the window is stored on the next day.
  - The override itself keeps the typed "01:11:00".
  - This happens in `recomputeDay` and in the override-aware compute, so Save All, the cell edit, an approved request, the approval preview, Recompute and the recheck all treat it the same.
- **Minutes and pay.** `computeMetrics` is unchanged; with a next-day time out it already works out the right minutes.
  - Regular minutes stay capped at the shift. Late and undertime are 0. Overtime runs from the shift end to the time out.
  - Payroll pays overtime only from `approved_overtime_minutes` (1.25x), as today.
  - Rice allowance reads the start date's payable minutes.
- **Import.** An import that brings a punch the date before takes also computes that date, unless it is locked.
- **The old next-date day.** Recompute and the import remove a next-date day whose punches all went to the date before. They keep it when it has an edit, approved overtime, a request, a lock, or a locked date before.
- **Approvals preview.** It shows "1:11 AM (next day)" and no longer warns "Time out is before time in" for it.
- **Calendar and attendance viewer.** Both show "(next day)".
- **Repair command.** `php artisan attendance:repair-overnight` is a dry run unless `--apply` is given, and it lists every change.

## Case table
Output: `docs/specs/009-evidence/spec-009-tests.txt` (21 tests, 187 assertions, OK). Failing first: `docs/specs/009-evidence/tests-before-code.txt`.

| Case | Test | Result |
|---|---|---|
| .1 live day by Recompute | `OvernightComputeTest::test_s_009_1_recompute_makes_the_live_day_one_full_day` | pass ✔ (before: fail, time out null) |
| .1 live day by one import | `test_s_009_1_import_of_the_whole_day` | pass ✔ (before: fail) |
| .1 later file with only 01:11 | `test_s_009_1_import_of_only_the_next_day_punch_fixes_the_start_date` | pass ✔ (before: fail) |
| .1 locked start date and import | `test_s_009_1_import_leaves_a_locked_start_date` | pass ✔ (before: fail, locked_skipped "0 is identical to 1") |
| .1 typed 01:11 by the CEO | `OvernightApprovalsTest::test_s_009_1_typed_time_out_is_the_next_day` | pass ✔ (before: fail) |
| .1 typed 07:00 stays | `test_s_009_1_typed_time_out_outside_the_window_is_not_moved` | pass ✔ (regression; passed before too) |
| .2 next date's own time in | `OvernightComputeTest::test_s_009_2_next_date_keeps_its_own_punches` | pass ✔ (before: fail) |
| .2 six nights in a row | `test_s_009_2_a_run_of_nights_is_the_same_however_it_is_computed` | pass ✔ (added after review; before the fix: fail, "Day By Day 2026-10-08") |
| .2 old next-date day removed or kept | `test_s_009_2_recompute_removes_the_old_next_date_day` | pass ✔ (added after review; before the fix: fail) |
| .3 window edges 05:59/06:00, 04:59/05:00 | `test_s_009_3_window_edges` | pass ✔ (before: fail, "null is identical to '2026-10-13 05:59:00'") |
| .4 night differential | none | open question: no night-differential computation exists |
| .5 normal day | `test_s_009_5_normal_day_is_unchanged`, `test_s_009_5_early_punch_stays_with_its_date_when_not_a_time_out` | pass ✔ (regression; passed before too) |
| .6 payroll breakdown and totals | `OvernightPayrollTest::test_s_009_6_breakdown_and_totals` | pass ✔ (before: fail, time out null) |
| .6 approved overtime paid | `test_s_009_6_approved_overtime_is_paid` | pass ✔ (before: fail, "359 matches expected 718.0") |
| .7 time out request: preview and approve | `OvernightApprovalsTest::test_s_009_7_time_out_request_preview_and_approve` | pass ✔ (before: fail) |
| .7 overtime request preview | `test_s_009_7_overtime_request_preview` | pass ✔ (before: fail) |
| .8 dry run | `OvernightRepairTest::test_s_009_8_dry_run_lists_and_writes_nothing` | pass ✔ (before: command not found) |
| .8 apply, then a second run | `test_s_009_8_apply_writes_and_is_idempotent` | pass ✔ (before: command not found) |
| .8 next date with edits kept | `test_s_009_8_next_date_with_edits_is_kept` | pass ✔ (before: command not found) |
| .9 calendar cell | `OvernightDisplayTest::test_s_009_9_calendar_cell` | pass ✔ (before: fail) |
| .9 attendance viewer | `test_s_009_9_attendance_viewer` | pass ✔ (before: fail) |

## Story changes
- Added Slice 009 to `qa/stories.md` (S-009.1–S-009.10). The cases are split per path (Recompute, import, typed) because each path enters the compute differently.
- S-009.10 is new: the existing suites pass.
- One owner check is new: run the repair dry run after deploy.

## Done-when checklist
- [x] **P1 pass with tests, first failing, then passing.**
  - First run (`tests-before-code.txt`): 16 failures or errors, and 3 regression cases passing.
  - Two review cases failed before their fix (same file).
  - Latest (`spec-009-tests.txt`): 21 tests OK.
  - Command: `php vendor/bin/phpunit tests/Feature/Overnight --testdox`.
- [x] **Full suite: no new failures vs 89b9676.**
  - Baseline (`baseline-89b9676.txt`): "OK (319 tests, 2928 assertions)".
  - Now (`full-suite.txt`): "OK (340 tests, 3115 assertions)".
  - No existing test was changed.
- [x] **Every changed computation is listed:** see "Changed computations" under Tests.
- [x] **Repair command dry-run output on seeded data:** `repair-command-output.txt`, also copied under Tests.
- [x] **Deploy notes:** below. No migration; the setting is a config file.
- [x] **Final line:** at the end of this file.
- [x] **Rendered HTML** (in `docs/specs/009-evidence/`):
  - `approvals-overtime-entry.html` (.7): Time out "1:11 AM (next day)" in both columns; no "Time out is before time in".
  - `calendar-cell.html` (.9): `data-time-out-display="01:11 AM (next day)"`, `data-time-out="01:11:00"`.
  - `attendance-viewer-row.html` (.9): "01:11 (next day)".

## How to run
```
git checkout feat/009-overnight-timeout
composer install
php vendor/bin/phpunit                                     # full suite (sqlite in memory)
php vendor/bin/phpunit tests/Feature/Overnight --testdox   # this spec
php artisan attendance:repair-overnight --from=2026-10-01 --to=2026-10-31          # dry run
php artisan attendance:repair-overnight --from=2026-10-01 --to=2026-10-31 --apply  # writes
```

## Rulings
- Ruling: "no later punch" means the start date's own punches, assigned as today, leave the time-out slot empty. A start date that already has a time out never takes a next-day punch — the plainest reading, and it keeps every normal day exactly as today — cost if wrong: a day whose stray afternoon punch became its time out keeps that punch instead of the 01:11.
- Ruling: when several punches fall in the window, the last one is the time out, and all of them leave the next date — a second tap on the way out is the time of leaving, and no punch counts twice — cost if wrong: up to a few minutes more overtime than the first tap.
- Ruling: the window uses the shift `getShiftForDate` gives for the next date, rest day or not — one rule, no rest-day lookup — cost if wrong: on a rest-day next date with an early shift, the window ends before 06:00 (05:00 for an 08:00 shift).
- Ruling: the window end is exclusive (05:59:59 is inside, 06:00:00 is outside) — "until 06:00" — cost if wrong: one second at the edge.
- Ruling: whether a date takes the punch depends on the punches only; locks only decide which rows are written. A next-date day is also kept while the date before is locked — the same punches give the same days, and a closed date is never changed — cost if wrong: see Deferred minors.
- Ruling: a typed time out is moved to the next day only when it is an override, it is before the time in (with no time in: before lunch in, then lunch out), and it falls inside the window — a time out made from the punches already carries its own date — cost if wrong: a time out typed inside the window for the same morning is moved, and that time out was before the time in anyway.
- Ruling: the override row keeps the typed "01:11:00"; only the day row's time out carries the next date — the edit log shows what was typed, and the calendar's raw value stays a time of day — cost if wrong: none to pay.
- Ruling: the setting is `config/attendance.php` (`overnight.until` 06:00, `overnight.hours_before_next_shift` 3), each readable from an env variable — there is no settings table, so no migration — cost if wrong: changing it needs a config edit or an env value, plus `config:cache`.
- Ruling: no new "worked" column. The spec's "≈13 h" is the span: 11:12→01:11 less 59 m lunch = 13 h 0 m, asserted in the test. Regular stays 8 h (capped), the 4 h 11 m of overtime is recorded, and the 48 m before the 12:00 shift start count nowhere, as today — the spec keeps regular pay capped at the shift — cost if wrong: none to pay.
- Ruling: Recompute, the import and the repair remove a next-date day whose punches all went to the date before. They keep it when it has an override, approved overtime, a request (any status), a lock, or a locked date before — a compute of the same punches makes no such day, and payroll counts any row as a worked day (on a regular holiday, as holiday worked) — cost if wrong: such a row is deleted. It was made from the punch alone, and Force Recompute deletes rows the same way.
- Ruling: when a date that got punches has one taken by the date before, the import also computes that date before, counted in `days_computed` or `locked_skipped` — otherwise a file that brings only the 01:11 leaves the start date at half pay — cost if wrong: a start date whose shift changed since its last compute gets the new shift's minutes.
- Ruling: the repair covers active employees only, as the Recompute button does — cost if wrong: a past day of someone now inactive is not fixed.
- Ruling: the Recompute button keeps its existing behaviour on locked dates (it never checked locks). The new paths skip locked dates: the import's extra date, the repair, and the next-date removal — changing the button is out of scope — cost if wrong: a Recompute over a locked range rewrites it, as today.

## Deferred minors
- **Shift change on the next date.** It moves that date's window end but does not recompute the start date. Rare, and the next Recompute fixes it.
- **Locks.** With the start date open and the next date locked, the locked next date keeps 01:11 as its time in, so the punch counts on both days. With the start date locked and the next date not yet computed, the punch counts on no day until the start date is unlocked and recomputed. No warning is shown, because locked dates are closed on purpose.
- **Repair dry run.** Each candidate is rolled back on its own, so a next date can show up again as a start date of its own. The dry-run counts can then differ slightly from what `--apply` reports. When the repair deletes a start date's own row, that row is counted as "changed", not "removed".
- **Payroll and exports.** The payroll breakdown, payslip, print view and CSV export show the time out as "01:11" without "(next day)". This is display only; the spec's P2 named the calendar and the viewer.
- **Repair on a next date with its own punches.** The repair recomputes it, which can bring in differences unrelated to the 01:11, such as a shift changed since. The dry run lists them.
- **Walk-back cost.** It is one query per compute call. For staff who punch before 06:00 every day, each walk covers about 33 days and resolves a shift per day. This is only a cost, not a wrong result.

## Merge danger
- **Two-way door.** No migration and no schema change. Reverting the branch's commits brings back the old compute. Rows written after deploy keep their next-day time outs until the old code recomputes them.
- **Blast radius.** Every compute goes through the punch walk. A normal day changes only when the date before has a time in and no time out and this date has a punch inside the window. S-009.5 and the 319 existing tests cover unchanged days.
- **Branch `fix/008-recompute-on-shift-change`** (read with git only):
  - It adds `recomputeDayWithoutPunches`, which calls `computeDayWithOverrides` with 8 arguments. That signature is unchanged here.
  - Its edit to `AttendanceApprovalService::blocks` touches other lines than this branch.
  - Expect at most a text conflict near `recheckDay` in `AttendanceComputeService.php`. Keep both sides.
  - Its `ShiftChangeRecompute` computes per day and gets the overnight rule automatically.

## Deploy notes
- **No migration.**
- **New config file** `config/attendance.php`. Run `php artisan config:cache` if the server caches config.
- **Queue worker.** Restart it after the code deploy, because the import job runs the new compute.
- **Repair dry run first.** After deploy, run `php artisan attendance:repair-overnight --from=<first open date> --to=<yesterday>`.
  - It lists every day it would change; the live 2026-10-16 day should be among them.
  - The owner decides whether to run `--apply`. This spec did not run it on production.
- **Locked dates** are skipped and named. To fix one: unlock it, run the repair for that range, then lock it again.

## Tests
**Changed computations**
1. **Which punches a date uses** (`workDatePunches`, used by `computeForEmployee`). It is used by Recompute, the import, the per-day rebuild after a calendar edit or shift change, and the approval preview's rebuild. The next date's last punch inside the window joins the start date when the start date has a time in and no time out. The window's punches then leave the next date.
2. **Timestamp assignment** (`assignTimestamps`). A punch dated after the work date can fill only the time-out slot. "Only 1 punch recorded" is not noted when that punch completes the day.
3. **Typed time out** (`recomputeDay`, `computeDayWithOverrides`). It moves to the next day as in the rulings, and the row's time out is written with it.
4. **Worked, late, undertime, overtime, payable minutes.** The formula is unchanged; the results now follow from a next-day time out.
   - Live day now: 480 / 0 / 0 / 251 / 480.
   - Before, from punches: 240 / 0 / 0 / 0 / 240.
   - Before, typed: 240 / 0 / 1129 / 0 / 240.
5. **Payroll** (no code change). The breakdown amount is 718.00 instead of 359, and undertime is 0. Overtime is paid only once approved (251 m = 469.32). Rice allowance counts a full day.
6. **Import and Recompute.** The import also computes the date before, when that date takes a punch. A next-date day made only from taken punches is removed, except as kept in the rulings.
7. **Approvals preview.** A new `time_out_next_day` flag. "Time out is before time in" is not raised for a next-day time out.

**Night differential.** There is no computation. `grep -rni "night\|differential" app/Services resources/views/payroll` finds nothing; only the `night_differential_eligible` flag and its screens exist.

**Repair dry run on seeded data** (`docs/specs/009-evidence/repair-command-output.txt`). The data is the fixtures of `OvernightRepairTest`, with rows as the old compute left them:
```
Start dates 2026-10-01 to 2026-10-15.
Dry run: nothing is written. Run again with --apply to write.
Locked One (#6) 2026-10-07: skipped, the date is locked
Warehouse One (#4) 2026-10-12: time out none -> 2026-10-13 01:11; worked 4 h -> 8 h; overtime 0 m -> 4 h 11 m; payable 4 h -> 8 h; needs review yes -> no
  next date 2026-10-13: day removed (its only punch is the time out above)
Typed One (#5) 2026-10-12: time out 2026-10-12 01:11 -> 2026-10-13 01:11; worked 4 h -> 8 h; undertime 18 h 49 m -> 0 m; overtime 0 m -> 4 h 11 m; payable 4 h -> 8 h; needs review yes -> no
2 days would change, 1 next-date day would be removed, 1 locked date skipped.
```
The same file has the `--apply` run and a second `--apply`, which reports "0 days changed, 0 next-date days removed, 1 locked date skipped."

**Latest results**
- `spec-009-tests.txt`: OK (21 tests, 187 assertions).
- `full-suite.txt`: OK (340 tests, 3115 assertions).
- Baseline: OK (319 tests, 2928 assertions).

**Skeptic review** (compute, payroll and repair), two rounds:
- **Round 1.** One blocker: a run of four or more nights computed one day at a time came out wrong, because the lookback was a fixed 2 days. One major: the old next-date day was left in place. Both are fixed, and their tests failed first. Minors are fixed (fewer extra recomputes in the import, a cheap test before resolving the next date's shift) or listed under Deferred minors.
- **Round 2.** No blockers. Two minors, both fixed: keep the next-date day while the date before is locked, and read the walk-back's early punches in one query.

## Open questions
1. **Night differential (case .4).** The app has no night-differential computation. Only the `night_differential_eligible` flag and its edit screens exist, and payroll pays nothing for 22:00–06:00. Nothing was invented. Should a night-differential pay rule be specified (rate, which minutes count, whether approved overtime is included)?
2. **Repair on production.** Should `--apply` be run after reading the dry run (see Deploy notes)? This spec did not run it.

## Process suggestions
- Name the locked date Oct 07 (`DL`) next to the other dates in the docblock of `ApprovalsTestCase`. A new multi-day test ran into it, and the first run showed `locked_skipped: 1` without a hint why.

## Proposed tasks
- Night-differential pay rule — the flag exists but pays nothing, and warehouse staff work 22:00–01:11 — normal.
- Show "(next day)" on the payslip breakdown, print view and CSV export — the data is there; only the display is missing — low.

009: done — a time out after midnight counts on the day it started (import, Recompute, typed edits, approvals), with a dry-run repair command; night differential is an open question (no ND logic exists)
