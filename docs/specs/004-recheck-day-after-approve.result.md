# Spec 004 result: approving a change rechecks the day

Branch `fix/004-recheck-day-after-approve`, from `main` at `6ab1971`. Not pushed (`git branch -r` lists `origin/main` only).

**Status:** done. All S-004 cases pass. Full suite: 261 passed with 0 failures (base: 248 passed). The 2 warnings were there before.

| Commit | What |
|---|---|
| `abf1eac` | the brief, as `docs/specs/004-recheck-day-after-approve.md` |
| `1e1e532` | the recheck in the approval, `AttendanceComputeService::recheckDay`, the new checks in the override-aware compute, the command, tests, `qa/stories.md` |
| `c75f195` | tests: approved times out of order, or a cleared time in, stay flagged |
| `d2e4888` | from the review: the recheck keeps every stored time and falls back to the day's own shift; the command holds days whose pay would change |
| (this file) | the result |

## Why the day stayed flagged

`needs_review` and `notes` are written in only one place: the compute that builds a day from its punches (`computeDay`, or `computeDayWithOverrides` for a day with edits). An approval writes through `AttendanceChangeService::applyTimes`. That method sets the fields, writes the override rows and then calls `AttendanceComputeService::recomputeDay`. `recomputeDay` recomputes the minutes and the shift only and never touches `needs_review` or `notes`.

So day 3539 got its four times and 480 minutes, but kept the flag and the note the import gave it on 2026-10-12 from two punches.

The `'needs_review' => true` near line 141 of `AttendanceChangeService` is in `createBlankDay`. It is the row a CEO edit or an approval creates when the date has no row yet. That row has no times, so the flag is right at that moment. It was never cleared afterwards, for the same reason as above. It is left as it is: the recheck now replaces it as soon as the approval has written its times. The CEO's own "create day" still makes a blank row that needs review, which is correct.

## What changed

- **One place for every approval path.** Single approve, "approve selected" and batch approve all call `AttendanceApprovalService::approve`. After `apply()` it calls `AttendanceChangeService::recheckDay($employee, $date)` once per entry (one employee and date), inside the approval's transaction and after the employee lock.
  - This covers every kind of request: times, approved overtime, shift, SIL, rest day.
  - A failed recheck rolls the whole approval back, and the request stays pending (500, "nothing was changed"), as S-11.6 requires.
  - A date with no day row (SIL or a rest day on a day nobody worked) has nothing to recheck.
- **`AttendanceComputeService::recheckDay(AttendanceDay)`** is the override-aware compute (`computeDayWithOverrides`, the one "Compute Attendance" uses with force = false), run with every stored time kept.
  - `needs_review`, `notes` and the minutes are decided from the final times, using the shift of the date. When the date has no shift, it uses the day's own shift, as `recomputeDay` does.
  - It never writes `approved_overtime_minutes`, override rows, `day_offs`, `sil_applications` or shift assignments.
  - It keeps `source_run_id`.
  - Unlike "Compute Attendance", it does not swallow errors.
- **`computeDayWithOverrides` got two things:**
  - a `keepStoredTimes` parameter (default false, so "Compute Attendance" and the import behave as before);
  - the checks the import's own compute already makes, now applied to the final times: "No Time In recorded." and the three order checks ("Lunch Out is after Lunch In (order issue)." and the others).
- **Reject** is untouched and does not recheck.
- **The CEO's direct edits** are untouched (see the rulings).

## Locked days

The app's rule is that a locked date (a row in `attendance_date_locks`, including the dates a finalized payroll run locks) is written by nobody:

- edits and requests answer 423;
- the approval re-reads the lock under `FOR UPDATE` and refuses with 423 before it applies anything;
- the import skips locked dates.

The recheck runs inside the approval after that check, so it never runs on a locked date. The command follows the import's rule for unattended runs. It reads the lock under the employee lock and skips the day, listing it as "skipped: date locked".

## The one-time command

```
php artisan attendance:recheck-approved                      # dry run: prints every day, writes nothing
php artisan attendance:recheck-approved --apply              # writes the days whose flag/note change
php artisan attendance:recheck-approved --apply --allow-other-changes   # also writes "held" days
```

**Which days it checks.** Every distinct employee and date that has an `approved` row in `attendance_change_requests`, oldest date first.

**How each day is handled.**

- It runs in its own transaction: lock the employee, check the date lock, recheck with the same code an approval runs, then compare the row before and after.
- In a dry run, or when nothing changed, the transaction is rolled back. With `--apply` it is committed.
- A day whose recheck would also change its times, shift or minutes is **held**: it is listed with those changes in "Other changes" and written only with `--allow-other-changes`. This happens when the shift assignment changed after the approval, for example. Such a day changes pay, so it is a decision to make after reading the dry run.

**What it prints.** One table row per day: employee (`#id name`), date, needs review `before → after`, the note before, the note after, other changes, and the result. The result is one of: would change, changed, held, unchanged, skipped: date locked, no day row, or error. A summary line with the counts follows.

**Exit code.** 0, or 1 if any day had an error. An error on one day does not stop the others.

**Running it again.** It is idempotent: a second `--apply` reports "0 changed" and writes nothing.

What the test run printed for the case of S-004.1 (an invented employee):

```
| #1 Employee One | 2026-10-08 | 1 → 0 | Lunch punches could not be inferred. No Time Out recorded (missed timeout). | Preserved overrides: time_out, lunch_out, lunch_in. |  | would change |
Dry run: nothing was written. Run again with --apply to write the changes.
1 days checked: 1 to change, 0 held (other columns change), 0 unchanged, 0 skipped (date locked), 0 without a day row, 0 errors.
```

For day 3539 (employee 51, 2026-10-12), I expect from the code: `1 → 0`, note "Preserved overrides: time_out, lunch_out, lunch_in.", nothing else changed. The minutes are already 480 from the same times and shift. This is not checked against production; the dry run will show it.

## Cases

`php.bat artisan test`, run on 2026-10-29. All tests are in `tests/Feature/Approvals/RecheckAfterApproveTest.php`; `qa/stories.md` has Slice 004.

| Case | Test(s) | Result |
|---|---|---|
| S-004.1 approved time out + lunch pair clears the flag and the stale note | `test_s_004_1_completed_day_is_no_longer_flagged`, `test_s_004_1_day_created_by_the_approval_is_checked` | pass (red before the fix) |
| S-004.2 only lunch in approved: still flagged, note names only the missing time out | `test_s_004_2_still_incomplete_day_names_only_what_is_left`, `test_s_004_2_anomalies_in_approved_times_stay_flagged` | pass (first red before the fix) |
| S-004.3 approve several / batch: each day rechecked once | `test_s_004_3_approve_several_rechecks_each_day_once`, `test_s_004_3_batch_approve_rechecks_each_day_once` | pass (red before the fix) |
| S-004.4 approved overtime, other overrides, SIL/rest day, source run unchanged; no time moves | `test_s_004_4_recheck_keeps_what_was_decided_before`, `test_s_004_4_shift_approval_keeps_the_stored_times` | pass (first red before the fix; the second red with the times rebuilt from punches) |
| S-004.5 dry run lists and writes nothing; `--apply` writes; second `--apply` no change; locked skipped; pay changes held | `test_s_004_5_command_fixes_days_approved_before`, `test_s_004_5_command_skips_locked_dates`, `test_s_004_5_command_holds_days_whose_pay_would_change` | pass (red: no command) |
| S-004.6 reject does not recheck | `test_s_004_6_reject_does_not_recheck` | pass (it passed on the base as well, since nothing rechecked there) |
| S-004.7 existing suites pass | full suite | pass; one Slice 002 test changed on purpose, see below |
| (extra) a failed recheck rolls the approval back | `test_failed_recheck_leaves_the_request_pending` | pass (red before the fix) |

**Baseline vs final.**

| | Result |
|---|---|
| Base `6ab1971` | `Tests: 2 warnings, 248 passed (1954 assertions)` |
| Final | `Tests: 2 warnings, 261 passed (2125 assertions)` |

The two warnings are the same ones as before: `file_get_contents` in S-11.1 and S-11.2, not related to this change.

**Changed test.** `LockConflictAndGuardsTest::test_old_page_request_carries_the_old_page_note` is now `test_old_page_request_is_rechecked_after_approval`.

- It pinned that an approved day created from nothing keeps the creation note of the CEO's own edit ("Manually created by CEO from absent row.").
- Decision 1 replaces that note with the recheck's ("Lunch punches could not be inferred. No Time Out recorded (missed timeout). Preserved overrides: time_in.", needs review 1).
- The CEO's own edit still writes the old note.

## Rulings

Ruling: the recheck keeps every stored time and does not use "Force Recompute" — Force Recompute (`forceRecomputeForDateRange`) deletes every override and day row of the range, which would undo approved values (Decision 2). The override-aware compute is used instead. Keeping the times nobody edited, rather than rebuilding them from punches, comes from the review: they were built from the punches already, and after a shift change they could move to other slots and change pay — cost if wrong: a day whose punches arrived after its last compute keeps its old times until the next Compute or import, which already recomputes such days.

Ruling: the override-aware compute now also flags a missing time in and times out of order — without these, an approved day with a cleared time in, or edited times out of order, would read as complete (Decision 1 "complete and no anomaly") — cost: the next "Compute Attendance" or import may newly flag an edited day that has those problems. Only the flag and note change, never the minutes.

Ruling: only approvals recheck. The CEO's direct edits are unchanged and still keep the old flag and note — the brief asks for the approval paths and a small change — cost: a day the CEO completes by direct edit stays flagged until the next Compute; and for flag and note, the approval no longer writes exactly what the direct edit writes (the minutes and rows still match). Proposed task: recheck after the CEO's direct edits too, through the same `recheckDay`.

Ruling: the command holds days whose times, shift or minutes would change unless `--allow-other-changes` is given — the fix is for the flag and the note, and a moved minute is a pay change nobody approved (review finding) — cost: one more run for those days if the owner wants them refreshed.

Ruling: the command skips locked dates — the import's rule for unattended runs; a closed pay period must not move — cost: a flagged locked day stays flagged until it is unlocked and the command runs again.

## Review

One skeptic review of the diff ran in a subagent. Its findings, and what was done with each:

1. **Major: the command commits any difference, including minutes.** Fixed in `d2e4888`. Such days are held, counted, and written only with `--allow-other-changes`; there is a test for it.
2. **Minor: a shift approval re-slots punched times.** Fixed: the recheck keeps every stored time; there is a test for it.
3. **Minor: the null-shift fallback differed from `recomputeDay`.** Fixed: the recheck uses `$shift ?: $day->shift`.
4. **Minor: rest-day approvals, and shift approvals from the calendar's own shift control, still run `autoComputeSingleDay` after the commit.** That is a second compute, and it nulls `source_run_id`. This was already the case before this change and is left as it is. It gives the same flag and note for days with overrides. For a punched day without overrides it rebuilds from the punches, as before.
5. **Minor: the new checks also affect "Compute Attendance".** Stated in the rulings above.
6. **Minor: test weaknesses.** Fixed: the fixture now gives the day a real `source_run_id`, and there are tests for the shift approval and for the held output. Not added: tests for the command's "no day row" and error rows.
7. **Minor: a typo in a comment.** Fixed.

## Deploy notes

Deploy is done by the reviewer or the owner, not by this branch. Nothing was pushed.

1. Merge, then push to `main`. In this repo that push is the production deploy.
2. No migration and no config change.
3. Run `php artisan queue:restart`. The import job runs in the queue worker and uses `computeDayWithOverrides`, which changed; the worker must load the new code.
4. Run `php artisan attendance:recheck-approved` (dry run) and read it:
   - day 3539 should show `1 → 0`;
   - the "held" count should be 0, or each held day's "Other changes" should be understood.
5. Run `php artisan attendance:recheck-approved --apply`. Then run it once more: it should report "0 changed".
6. Add `--allow-other-changes` only if the owner wants held days refreshed as well.
7. Check the Attendance Viewer or the Calendar: employee 51 on 2026-10-12 is no longer flagged.

**How to undo.** Revert the commits and push.

- Flags and notes the command already cleared stay cleared. They are true for the day as it is, and the next Compute sets them again from the punches.
- No table changed.

004: done — every approval now rechecks the day in its own transaction, and `attendance:recheck-approved` fixes the days approved before.
