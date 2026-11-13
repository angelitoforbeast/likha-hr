# Result: spec 015 half-day SIL

**Status:** done
**Date:** 2026-11-14
**Branch / PR:** `feat/015-half-day-sil`, from cdb67ca (production). Committed; not pushed; no PR.
**Preview or run link:** n/a. Nothing is deployed. The test runs are in `docs/specs/015-evidence/`.

## Summary
- **Data.** `sil_applications.days` (decimal(3,1), default 1.0). Every existing row is a whole day. One row per employee and date, as before. `SilApplication` has `WHOLE_DAY` / `HALF_DAY`, `daysValue()`, `isHalfDay()` and `label()` ("SIL" or "SIL ½").
- **Filing.** Each path takes `days` = 1 (default) or 0.5; any other value is a 422.
  - Calendar modal: a "Whole day / Half day" select above the reason. Apply is enabled only when the remaining balance covers the choice. An applied half shows "Half-day SIL (½) is applied", and the modal title reads "SIL ½".
  - Profile add-SIL (CEO): a "Length" select.
  - Staff request: the value of the SIL request says which: `apply` (whole) or `apply_half` (half). It goes through approval unchanged, and the approval applies 0.5. The card reads "Apply SIL (half day)". The preview's SIL line reads "No → Yes (half day)". The official value of a date with a half applied is `applied_half` ("SIL applied (half day)").
- **Balance.** `Employee::getSilUsedDays` returns `SUM(days)` as a float. The profile sums days instead of counting rows. `assertSilCanBeApplied` and the profile refuse when remaining < the days asked, with the existing "No remaining SIL days for YYYY." So a half is allowed at 0.5 remaining, and a whole day is refused there.
- **Pay** (`PayrollService`):
  - SIL pay = the day's rate × days.
  - Half SIL on a required day without attendance: rate × 0.5 paid and 0.5 absent day. The absence is deducted at half the day's rate, in the single-rate and the multi-rate path.
  - Half SIL on a special holiday not worked (Xander): rate × 0.5, no absence.
  - Half SIL on a regular working day with attendance: the worked half is paid at rate × 0.5. Only the minutes short of half the shift's required minutes are deducted (240 of 480): late first, the rest as undertime; leaving early is not counted. The SIL half is paid on top, so the day is at most one day's rate. Approved OT is paid as on any day.
  - A whole SIL takes exactly the old path.
- **absent_days** is decimal(4,1) on MySQL, so a payroll item can hold 14.5. `PayrollItem` casts it to float.
- **Display.**
  - Breakdown: a half SIL date is one row, type `sil`, status "SIL ½", `sil_days` 0.5, amount = what that day pays. With attendance, the row keeps the punches and the late/undertime counted against the half. Whole SIL rows now carry `sil_days` 1 too.
  - Payslip: "SIL ½" in the breakdown. SIL days are summed (0.5), and the "Absences (14.5 days)" line shows the half.
  - Calendar cell: "SIL ½" (with `data-sil-days`). Profile list: a "½ day" badge. Used shows 0.5, remaining 4.50.
- **Audit.** Unchanged: `SilApplication` keeps the `Auditable` trait, so `days` is in the created row.

## Worked numbers (rate 520.00, period Oct 1–15, 15 required days)
| Case | Absent days | Basic pay | Day amount |
|---|---|---|---|
| Half SIL, no punches | 14.5 | 260.00 | 260.00 |
| Half SIL on special holiday, rate 645.00 | 14 (holiday not required) | 322.50 | 322.50 |
| Half SIL + 08:00–12:00 | 14 | 520.00 | 520.00 |
| Half SIL + 08:00–11:00 | 14 | 455.00 (60 min UT = 65.00) | 455.00 |
| Half SIL + 08:30–12:00 | 14 | 487.50 (30 min late) | 487.50 |
| Half SIL + full 8 h | 14 | 520.00 (not 780.00) | 520.00 |
| Whole SIL, no punches | 14 | 520.00 (as before) | 520.00 |

## Migrations
- `2026_11_14_000001_add_days_to_sil_applications`: adds `days` decimal(3,1) not null default 1.0 after `sil_date`. `down()` drops it.
- `2026_11_14_000002_make_payroll_items_absent_days_decimal`: on MySQL, `ALTER TABLE payroll_items MODIFY absent_days DECIMAL(4,1) NOT NULL DEFAULT 0`. `down()` goes back to `INT NOT NULL DEFAULT 0`, and MySQL rounds any stored half.
  - The repo is Laravel 10 without doctrine/dbal, so `->change()` cannot be used. This is plain SQL, as in `2026_11_10_000002`.
  - On SQLite it does nothing: an INTEGER column keeps 14.5 as is.

## Case table
| Case | Test (`tests/Feature/HalfSil/HalfDaySilTest.php`) | Result |
|---|---|---|
| .1 | `test_s_015_1_existing_rows_stay_whole_days`: migration down, a row inserted without days, up → 1.0; a new SIL defaults to whole | pass |
| .2 | `test_s_015_2_balance_sums_halves` (used 1.5 as float, remaining 3.5) | pass |
| .3 | `test_s_015_3_refuses_what_the_balance_cannot_cover` (half at 0.5 ok; whole at 0.5 and half at 0 refused; staff refused, no request) | pass |
| .4 | `test_s_015_4_days_must_be_whole_or_half` (0.25 → 422) | pass |
| .5 | `test_s_015_5_half_sil_on_required_day_without_punches` | pass |
| .6 | `test_s_015_6_half_sil_on_special_holiday` (Xander, 645.00 → 322.50) | pass |
| .7 | `test_s_015_7_half_sil_plus_four_hours` | pass |
| .8 | `test_s_015_8_half_sil_plus_three_hours` | pass |
| .9 | `test_s_015_9_half_sil_with_late` | pass |
| .10 | `test_s_015_10_never_above_one_day` | pass |
| .11 | `test_s_015_11_multiple_rates` (absent half at 600.00 × 0.5) | pass |
| .12 | `test_s_015_12_whole_sil_unchanged` | pass |
| .13 | `test_s_015_13_request_carries_the_half` (HR → `apply_half` → card and preview → CEO approves → 0.5) | pass |
| .14 | `test_s_015_14_half_request_blocked_without_balance` | pass |
| .15 | `test_s_015_15_one_row_per_date` (whole over a half refused; remove and apply again) | pass |
| .16 | `test_s_015_16_calendar_cell` | pass |
| .17 | `test_s_015_17_profile` | pass |
| .18 | `test_s_015_18_payslip` | pass |

Run: `OK (18 tests, 134 assertions)` (`015-evidence/case-tests-green.txt`).

## Done-when checklist
- [x] The new tests failed first, then passed.
  - Red: `015-evidence/red-tests-before-code.txt` (`Tests: 18, Assertions: 47, Errors: 2, Failures: 15`). The one that passed there, S-015.14, also passes on the old code: without `days` the request was a whole day, refused the same way.
  - After the red run, two assertions were added or changed: S-015.16 moved to E1, because the calendar lists only employees with attendance in the range; S-015.13 gained the preview line.
- [x] Full suite has no new failures vs cdb67ca.
  - Baseline: `Tests: 439, Assertions: 4131, Failures: 1`.
  - After: `Tests: 457, Assertions: 4265, Failures: 1`.
  - The one failure is the same date-bound `ApprovalsPageTest::test_s_10_1_nav_count_and_list`.
- [x] Spec `docs/specs/015-half-day-sil.md` and this result.

## Rulings
- Ruling: on a day with attendance, the worked part is measured against **half the shift's required minutes** (240 for the usual 480), not a fixed 240 — why: the same rule on a shift of another length; on every 8-hour shift it is the plan's 240 — cost if wrong: a non-8-hour shift's half day is measured against its own half instead of 4 h.
- Ruling: the shortfall of the worked half is split as late first (up to the computed late), the rest as undertime; computed early-out is not counted — why: on a half day leaving early (or arriving for the afternoon) is the plan, and the plan's 3 h case is "1 h undertime only"; 30 minutes late on a morning half shows as late — cost if wrong: the label of a deduction, not its amount.
- Ruling: the half-day work rule applies only on a **regular working day** (not a rest day, not a holiday the employee is eligible for). A half SIL on a holiday or rest day, worked or not, follows the whole-SIL path with the SIL pay scaled — why: the plan says to keep those combinations as a whole SIL does, scaled by days — cost if wrong: a worked holiday with a half SIL is paid as a whole SIL on a worked holiday is today, with half the SIL.
- Ruling: a date with a half SIL and attendance counts as one day worked in `days_worked`, as any attendance row does today — why: the absence count and the allowances that read days worked stay as they are for a half day worked without SIL — cost if wrong: the payslip reads "1 days worked + 0.5 SIL" for such a date; the pay is right either way.
- Ruling: a half SIL on a required day with a day row but no payable minutes is paid as rate × 0.5 with 240 minutes of undertime, not as 0.5 absent — why: a day row counts as attended everywhere in payroll today — cost if wrong: the same amount shows as undertime instead of absence.
- Ruling: the request carries the half in its value (`apply_half`), and the official value of a half is `applied_half`; a half asked where a whole is applied (or the other way) is refused as "SIL is already applied for this date" rather than closed as no longer needed — why: no new column; the value already travels with the request and is shown by `displayValue`; old requests (`apply`/`applied`) read as before — cost if wrong: none for existing rows.
- Ruling: the staff request path checks the balance for the days asked when the request is saved and again at approval — why: the existing rule, now with days — cost if wrong: none.
- Ruling: the profile's add-SIL keeps its own checks and messages, now with days. It was not moved onto `AttendanceChangeService::applySil` — why: smallest change; its messages differ from the calendar's — cost if wrong: it still has no employee lock (as before).
- Ruling: the schedule feed (`ScheduleResolver`) still treats any SIL date as leave for the whole day — why: not in the plan; one row per date has no morning/afternoon — cost if wrong: the office view shows a half-SIL person as on leave even when they come in for the other half.
- Ruling: Pint was not run, as in spec 013 (the repo is not Pint-clean) — why: smallest diff — cost if wrong: style only.

## Found, not changed: a whole SIL on a day with attendance is paid twice
`PayrollService` does not skip a whole-SIL date in step 4. It counts the date as worked (pay for the day, late/undertime deductions) and step 4b adds the SIL pay again. `$silRequiredCount` counts it once more, which also lowers the absence count.
- Measured at cdb67ca: E1, rate 520.00, worked Oct 5 (3 min late) with a whole SIL that day. Basic pay went from 516.75 to 1036.75, and absent days from 14 to 13. The breakdown shows only the SIL row (520.00).
- The plan says a whole SIL keeps today's behaviour exactly, so it is unchanged, and the half-day path does not repeat it.
- A fix (skip a whole-SIL date in step 4) is a one-line change with its own test. It is listed under Proposed tasks for the owner to decide.

## Merge danger
Two-way door with two migrations.
- **Schema.** One column on `sil_applications` (existing rows 1.0) and `payroll_items.absent_days` int → decimal(4,1). Existing values are unchanged, so pay and balances are the same on deploy.
- **Behaviour.** Only a SIL filed as a half day behaves differently. Whole SIL, the default everywhere, takes the old path.
- **Blast radius if wrong.** SIL pay and the SIL balance. Payroll for employees without a half SIL is untouched: the whole payroll and approvals suites pass.

## Deploy notes
- `php artisan migrate`: two migrations, `2026_11_14_000001_add_days_to_sil_applications` and `2026_11_14_000002_make_payroll_items_absent_days_decimal`.
- Then `php artisan optimize:clear`, because views changed.
- No config or env change, and no queue or worker restart.
- Xander's Nov 1 SIL is a whole day in the data. After deploy, remove it and apply it again as a half day on the calendar (or set `days = 0.5` on that row), then recompute the payroll run that covers Nov 1. Nothing here recomputes a run.
- The migrations were run (up, down, up for the first) on SQLite only. No MySQL server was available here, so run them on staging first.

## Revert
1. `php artisan migrate:rollback --step=2`. This drops `days`: every half SIL becomes a whole day again. `absent_days` goes back to INT, and MySQL rounds any stored half.
2. `git revert` of the commit.
3. `php artisan optimize:clear`.
4. Recompute any payroll run that had a half SIL.

## How to run
```
git checkout feat/015-half-day-sil
composer install
php vendor/bin/phpunit tests/Feature/HalfSil --testdox
php vendor/bin/phpunit
```

## Proposed tasks
- Stop paying a whole SIL twice on a day with attendance (see "Found, not changed") — why: it overpays one day's rate per such date — high.
- Fix the clock of `ApprovalsPageTest::test_s_10_1_nav_count_and_list` (still from spec 013) — why: it fails on any day outside October 2026 and hides real failures — normal.

015: done — SIL can be filed as a half day on the calendar, the profile and through approval; the balance sums halves and payroll pays the day's rate × 0.5
