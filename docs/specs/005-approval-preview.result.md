# Spec 005 result: the CEO sees the whole day as it will be, before approving

Branch `feat/005-approval-preview`, from `main` at `f78f3d4`. Not pushed.

**Status:** done. Every S-005 case passes. The full suite has 275 passed and 0 failed; the base had 261 passed. The 2 warnings were there before.

| Commit | What |
|---|---|
| `8f75ea6` | the brief, as `docs/specs/005-approval-preview.md` |
| `fac979c` | the preview: `AttendanceChangeService::dryRun`, `AttendanceApprovalService::applyEntry/preview/dayPicture/warnings`, the `_preview` partial and the layout, tests, `qa/stories.md` |
| `3ee0056` | from the review: the guard against a lost transaction, the read-only SIL balance check, "Now" computed inside the try, the hint on blocked entries |
| (this file) | the result |

## What the CEO sees

Every pending entry (one employee and one date, the block with Approve and Reject) gets a **Preview** box:

- On a laptop it is a right-hand column beside the request.
- On a phone it sits below the request and above the buttons.

It has two columns, **Now** and **If approved**, with these lines:

- the four times;
- **Shift**, only when a shift change is asked for or the shift changes;
- Lunch (its length), Worked, Late, Undertime;
- Overtime worked and Overtime approved;
- **Rest day** and **SIL**, only when they are asked for or change;
- Needs review.

Below the lines comes the note. When the note changes it is shown twice, as "Note now" and "Note if approved". A changed value is highlighted in the If approved column. Times are in the page's own format, for example `4:45 PM`.

Under the note come **warnings**, one plain line each:

- Still missing: time out.
- Lunch is 2 h 30 m, longer than the shift's 1 h.
- Lunch in is before lunch out.
- Time out is before time in.
- Worked 6 h 30 m, under the shift's 8 h.
- 30 m of overtime is not covered by approved overtime.

**When there is no preview.** If the preview cannot be worked out, the box says "Preview not available: <reason>", and the buttons work as before. The reasons are:

- the employee has no shift on that date;
- an approval refusal, such as "No remaining SIL days for 2026.";
- any error, shown as "It could not be worked out."

**Blocked entries.** When an entry is blocked or its date is locked, the box adds "Approve is not allowed now. This is what it would save once it is."

## How "If approved" is computed (Decision 2)

It is not a second calculation. Two code paths now share the same steps:

- **Approve** calls `applyEntry`. That is the old `apply()` (every field, through the methods the CEO's direct edits use) followed by `recheckDay`, both moved out of `approve()` without any change.
- **The preview** calls the same `applyEntry` inside `AttendanceChangeService::dryRun`.

What `dryRun` does:

- It opens a transaction and **always rolls it back**, in a `finally`.
- After a real approval, the rest-day change and the shift change made from the calendar still rebuild the day after the commit (`autoComputeSingleDay`, via `DB::afterCommit`). Inside a dry run that rebuild is queued and run before the day is read. The preview therefore shows what the commit would leave. A test removes this step and S-005.6 turns red.
- While a dry run is open, a guard on the connection refuses every statement that would run outside a transaction. On MySQL a deadlock ends the whole transaction at once. Some code swallows its errors: the rebuild after an edit, and the audit log. Without the guard, the statements that follow would autocommit. With the guard they fail, and the preview says it is not available.
- A dry run inside another one is refused.

What the preview leaves out on purpose: the checks that decide whether Approve may run at all (role, own request, "seen", date lock, blocks, changed official value). The page already shows those. The preview shows what Approve writes once it is allowed.

## Speed (Decision 5)

The previews are computed **inline**, in the page request. Measured with 30 entries (10 invented employees × 3 dates, each a missed time out), sqlite in memory, 5 loads each:

| | Page load | Queries |
|---|---|---|
| Without previews | 32–54 ms | 204 |
| With previews | 147–250 ms | 1,134 |

So a preview costs about 4–6 ms and 31 queries per entry. Most of that is the real write path, and it cannot be shared between entries without stopping being the real path. The "Now" side and the reads add a few queries per entry.

On MySQL each query is a network round trip. My estimate is roughly 0.2–0.6 s more for 30 entries, and up to about 1 s for a full page of 50. That is not measured: no MySQL was used, because the only one here is the owner's own local server, and I did not read its credentials.

Why inline and not lazy: the CEO decides with the preview in view, a lazy endpoint adds a second request per card and JavaScript, and the numbers above are tolerable. If MySQL turns out slower in practice, the partial can be loaded per card from a small GET endpoint that runs the same `preview()`. That is a contained change.

## Rulings

- **Ruling: the preview runs the approval's write path in a rolled-back transaction, not an in-memory copy.**
  - Why: only the real path is guaranteed to equal Approve (Decision 2). An in-memory copy would be a second implementation of `applyTimes`, `recheckDay`, the shift paths and the rebuild.
  - Cost if wrong, on MySQL:
    - each page load takes a short row lock on each shown employee (`FOR UPDATE`), held until that entry's rollback (milliseconds), plus gap and next-key locks on the rows it touches. An approval, an edit or an import of the same employee at that moment waits for it;
    - in a rare deadlock the database picks a victim. If it picks the import's statement, the import logs an error for that one day, and the next import or Compute redoes it;
    - InnoDB does not give back auto-increment ids on rollback. Every load uses up ids in `attendance_overrides`, `attendance_days`, `day_off_logs`, `employee_shift_assignments`, `sil_applications` and `audit_logs`. They are gaps only, and no code relies on ids being contiguous.
- **Ruling: a day row with no shift after approval shows "Preview not available".** Why: Decision 4 names a missing schedule as a reason, and without a shift the hours, late and undertime are all 0. Cost if wrong: the CEO does not see the times for such an entry. They are still listed in the request beside it.
- **Ruling: approved overtime that was never set shows as "not set", not as "automatic" (the request rows' word).** Why: payroll pays only approved overtime (`approved_overtime_minutes ?? 0`). "Automatic" next to the warning "… not covered by approved overtime" would read as a contradiction. Cost if wrong: two words for the same state on one card.
- **Ruling: the SIL balance check reads the balance instead of creating it.** `assertSilCanBeApplied` used `getSilRemainingDays`, and that method `firstOrCreate`s a balance row. The Approvals page runs this check for every SIL entry it shows (through `blocks()`, which predates this spec). Opening the page could therefore commit an empty balance row and its audit row. The check now reads `total_days` (or 0) and computes the same number. Cost if wrong: an employee with no balance row no longer gets an empty one when a SIL request or apply is refused. The calendar and the Employee page still create it as before.
- **Ruling: the hours and missing-punch warnings are skipped on a rest day or a SIL day.** Why: missing punches on such a day are expected. Cost if wrong: an odd worked rest day is not warned. Its values are still shown.
- **Ruling: the preview is a grid of `div`s with table roles, not a `<table>`.** Why: S-10.9 pins that the entries are "cards, not a wide table". The grid's three columns are `minmax(0, …)` and cannot widen the card.

## Cases

Run with `php.bat artisan test` on 2026-10-29. The tests are in `tests/Feature/Approvals/ApprovalPreviewTest.php`; `qa/stories.md` has Slice 005.

| Case | Test(s) | Result |
|---|---|---|
| S-005.1 missed time out: Now has no time out and needs review; If approved shows the time out, 8 h, review cleared | `test_s_005_1_missed_timeout_shows_the_completed_day` | pass |
| S-005.2 lunch fix: lunch 2 h → 1 h, worked 7 h → 8 h, late 1 h → 0; each warning | `test_s_005_2_lunch_fix_shows_the_new_lunch_length_and_hours`, `test_s_005_2_lunch_still_too_long_is_warned`, `test_s_005_2_other_unusual_results_are_warned` | pass |
| S-005.3 overtime: approved "not set" → 1 h 30 m, with 30 m not covered | `test_s_005_3_overtime_request_shows_the_approved_overtime` | pass |
| S-005.4 shift change: Day shift → Late shift, late 3 m → 0, undertime 0 → 2 h, worked 7 h 57 m → 6 h | `test_s_005_4_shift_change_shows_the_new_shift_and_recomputed_late_and_undertime` | pass |
| S-005.5 the page writes nothing: every row of every table and `sqlite_sequence` unchanged after two loads, with timestamps moved 2 h on; no job; no transaction left open; no SIL balance row; nothing runs after a lost transaction | `test_s_005_5_rendering_the_page_writes_nothing`, `test_s_005_5_a_sil_entry_without_a_balance_row_writes_none`, `test_s_005_5_a_dry_run_that_lost_its_transaction_runs_nothing_more` | pass; red with commit instead of rollback, without the guard, and with the old SIL check |
| S-005.6 preview = the saved day, for 8 kinds (missed time out, lunch fix, overtime, shift from Save All, shift from the calendar with the rebuild after commit, new day, rest day, SIL), compared through the preview's code and straight from the `attendance_days` row | `test_s_005_6_preview_equals_the_day_after_approval` | pass; red without the deferred rebuild |
| S-005.7 "Preview not available" (no shift; a thrown error), Approve still works, page 200, nothing written | `test_s_005_7_preview_that_cannot_be_computed_says_so_and_can_still_be_approved`, `test_s_005_7_an_error_in_the_preview_never_breaks_the_page` | pass |
| S-005.8 390 px: request, then preview, then buttons; no sideways scroll caused by the preview | `test_s_005_8_preview_sits_between_the_request_and_the_buttons` (HTML order and CSS), and the rendered page in a browser, below | pass, see the note |
| S-005.9 existing suites | full suite | pass, no test changed |

**Baseline vs final.**

| | Result |
|---|---|
| Base `f78f3d4` | `Tests: 2 warnings, 261 passed (2125 assertions)` |
| Final `3ee0056` | `Tests: 2 warnings, 275 passed (2334 assertions)` |

**Rendered page.** A probe test that is not committed rendered `/approvals` as the CEO, with invented entries:

- the brief's shape: lunch out 4:00 PM (now 4:45 PM), lunch in 4:45 PM (now 9:04 PM), time out 9:04 PM (now none);
- a missed time out;
- 2 h of overtime;
- a shift change.

The HTML is at `C:\Users\Forbeast\AppData\Local\Temp\claude\C--mira-mira-office\1332ecd3-ded0-44d9-8ce1-615fd84c5036\scratchpad\approvals-render.html`.

What the browser showed:

- **At 1280 px**: the request column is 538 px wide on the left, and the preview is 384 px on the right, level with the top of the request. For the brief's entry, If approved shows lunch 4 h 19 m → 45 m, worked 3 h 48 m → 4 h 3 m, overtime 0 → 4 h 4 m, needs review Yes → No. The warnings are "Worked 4 h 3 m, under the shift's 8 h." and "4 h 4 m of overtime is not covered by approved overtime."
- **At 390 px**: request, then preview, then buttons, one under the other, and nothing inside a card is wider than the card.

**One pre-existing problem at 390 px.** The page already scrolls sideways there: 478 px of content, with or without the preview. The app's layout has a fixed 250 px sidebar and no phone breakpoint, so the top bar (name and Logout) overflows. This is on `main` too. I left it alone, because Decision 6 keeps this change narrow. A phone breakpoint for the layout is a separate task.

## Review

One skeptic review ran in a subagent. What was done with each finding:

1. **Major: a deadlock swallowed inside the dry run could leave later statements autocommitting on MySQL.** Fixed in `3ee0056`:
   - the connection guard refuses any statement while a dry run is open and no transaction is;
   - the counters are set only after `BEGIN` succeeds;
   - a failed top-level rollback purges the connection;
   - nested dry runs are refused;
   - there is a test for it.
2. **Major: opening the page could commit an `employee_sil_balances` row.** This was old behaviour, through `blocks()`. Fixed in `3ee0056` with the read-only balance check; there is a test for it.
3. **Minor: "Now" and the warnings ran outside the try.** Fixed.
4. **Minor: locks and auto-increment use on MySQL.** Accepted and stated in the rulings and the speed section. The wait timeout was not lowered.
5. **Minor: the `deferred` state is per instance.** Nested runs are now refused. Code inside `apply()` that builds a new `AttendanceChangeService` would bypass the queue, and none does. Not changed.
6. **Minor: a failing preview logs on every load.** Accepted. It logs one warning line per failing entry; the rebuild's own log line can appear too.
7. **Minor: no hint on blocked entries.** Fixed: "Approve is not allowed now. …".
8. **Minor: holidays are not treated as off days in the warnings, and 3 minutes late gives a "Worked 7 h 57 m, under …" line.** Deferred. The second is literally true. Whether to warn only on larger gaps is the owner's call.
9. **Minor: long notes in the grid.** Already handled: `.approval-entry { overflow-wrap: anywhere; }` covers the preview.
10. **Minor: test weaknesses.** Fixed:
    - the always-true `Shift</th>` assertion is replaced;
    - the transaction level is asserted;
    - the lost-transaction and SIL tests were added.

    Not added: a test of a failure inside "Now" alone.

## Deploy notes

The deploy is done by the reviewer or the owner. In this repo, a push to `main` is the production deploy.

1. Merge, then push to `main`.
2. **No migration** and no config change.
3. `php artisan view:clear` is optional, since the Blade views change and are compiled again when their files change. No `queue:restart` is needed: the import job does not use `AttendanceChangeService` or the SIL check, and the compute it runs did not change. A restart does no harm.
4. Check: open `/approvals` as the CEO with a few pending entries. Each card has a Preview box, and the Approvals count does not change from opening the page.
5. Watch the first loads on MySQL. If the page is slow with many entries, the lazy per-card endpoint is the next step.

**How to undo.** Revert the two code commits and push. No data changed, and no table changed.

**Merge danger.** Two-way door, small blast radius: the Approvals page (CEO only), plus the SIL "no remaining days" check, which now reads the balance instead of creating it.

005: done — each pending entry shows Now vs If approved, computed by Approve's own code in a transaction that is always rolled back; 275 tests pass, the page writes nothing, and the preview equals the saved day.
