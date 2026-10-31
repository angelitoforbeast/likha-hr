# Result: spec 011 overnight raw punches

**Status:** done
**Date:** 2026-10-31
**Branch / PR:** `feat/011-overnight-raw-punches`, from main ef5fffa. Not pushed; no PR.
**Preview or run link:** n/a. Nothing is deployed. The JSON, the rendered modal and picker HTML, and the test runs are in `docs/specs/011-evidence/`.

## Summary
- **The endpoint** (`AttendanceController::punches`, `GET /attendance/punches`). For date D it lists D's own punches as before. After them it lists D+1's punches from 00:00 up to `AttendanceComputeService::overnightWindowEnd($employee, D+1)`, which is the compute's own window. Each of these carries `next_day: true` and its `date`, and `count` includes them.
  - They are added only when D has punches of its own as the compute sees them (`workDatePunches`). A date with no punches, or whose only punch is the date before's time out, gets none.
  - D's own punches keep exactly their old keys, so a normal day's JSON is byte-for-byte the same.
  - D+1's own list is unchanged.
- **The calendar** (`attendance-calendar/index.blade.php`). Two small render functions sit between `/* raw-punch-render */` markers: `rawPunchText` and `punchPickerOptions`.
  - The Raw Punches modal lists a next-day punch as "1:11:04 AM (next day)" with its date in muted text.
  - The four punch pickers list it after the date's own punches with the same label. Only the Time Out picker lets you pick it, and only when the day already has a time in. The other pickers show it disabled.
- **The edit path is unchanged.** The picker puts "01:11:04" into the time-out input, and Save All / the request path store that time of day. Spec 009's `typedTimeOut` then stores a time out that is before the time in and inside the same window on D+1.
- **The older attendance page** (`attendance/index.blade.php`) uses the same endpoint. Its modal labels the punch "(next day)" too.

## Case table
| Case | Test (`tests/Feature/Overnight/OvernightRawPunchesTest.php`) | Result |
|---|---|---|
| .1 JSON | `test_s_011_1_next_day_time_out_is_listed_after_the_date_own` | pass: `✔ S-011.1 the punches of D are its own three, then 01:11:04 of D+1 flagged next day; 11:56 is not included` |
| .1 modal/pickers | `test_s_011_1_modal_and_pickers_render_the_next_day_punch` (the page's own script, run in node) | pass: `✔ S-011.1 the modal renders "1:11:04 AM (next day)" and "4 punches total"; the time-out picker offers it (the page script run in node)` |
| .2 until | `test_s_011_2_until_edge` | pass: `✔ S-011.2 window edges: 05:59 of D+1 is included and 06:00 is not` |
| .2 next shift | `test_s_011_2_next_shift_edge` | pass: `✔ S-011.2 window edges: with a D+1 shift starting at 08:00 the window ends 05:00; 04:59 is included and 05:30 is not` |
| .3 | `test_s_011_3_normal_day_is_unchanged` (compares the raw response string) | pass: `✔ S-011.3 a normal day with no punch after midnight returns exactly the JSON of before` |
| .4 | `test_s_011_4_next_date_keeps_its_own_list` | pass: `✔ S-011.4 the list of D+1 is unchanged: 01:11:04 is still its own first punch` |
| .5 direct, 11:00 shift | `test_s_011_5_picked_next_day_time_out_saved_directly` | pass: `✔ S-011.5 the CEO picks "1:11:04 AM (next day)" as the time out and saves (11:00 shift): time out D+1 01:11:04, the day as the compute makes it (468: 12 m late)` |
| .5 direct, live 12:00 shift | `test_s_011_5_picked_next_day_time_out_is_a_full_day_on_the_live_shift` | pass: `✔ S-011.5 the same pick on the live day's 12:00 shift: time out D+1 01:11:04 and payable 480 (8 h), as the repair made it` |
| .5 HR request + approve | `test_s_011_5_picked_next_day_time_out_requested_and_approved` | pass: `✔ S-011.5 HR picks the next-day time out: the request, once approved, stores time out D+1 01:11:04, the day as the compute makes it` |
| .5 no time in yet | `test_s_011_5_next_day_time_out_cannot_be_picked_without_a_time_in` | pass: `✔ S-011.5 a day with no time in yet (no day row): the time-out picker shows the next-day punch but it cannot be picked, since the save would keep it on D` |
| .5 same pick | `test_s_011_5_same_pick_is_no_change` | pass: `✔ S-011.5 when the day already has the next-day time out, saving the same pick changes nothing` |
| .6 first day / empty D+1 | `test_s_011_6_first_day_and_empty_next_day` | pass: `✔ S-011.6 the first day of the employee records, and a D+1 with no punches: no error, no extra rows` |
| .6 empty D | `test_s_011_6_empty_date_lists_nothing_of_the_next_date` | pass: `✔ S-011.6 a date with no punches of its own lists none of the next date's early punches` |
| .6 taken D | `test_s_011_6_date_whose_punches_were_taken_lists_no_next_day_punch` | pass: `✔ S-011.6 a date whose only punch is the time out of the date before lists no next-day punch, so none can be saved as its time out` |

Run: `OK (14 tests, 75 assertions)`. The full output is in `docs/specs/011-evidence/case-tests-green.txt`.

## Story changes
- Added Slice 011 to `qa/stories.md`: S-011.1–.6 as in the spec, plus S-011.7, the regression row for the full suite.
- S-011.5's Then now reads "the day as the compute makes it: payable 480 on the live day's 12:00 shift, 468 on the 11:00 shift". The spec's "payable 480 for the .1 day" cannot hold on the 11:00–20:00 shift it names, because In 11:12 is 12 minutes late there. See Rulings.
- Added S-011.5's disabled-option case.
- Added one owner check: after deploy, look at the live Oct 16 day's Raw Punches on production.

## Done-when checklist
- [x] P1 pass with tests, first failing, then passing.
  - Failing first: `docs/specs/011-evidence/red-tests-before-code.txt` (`Tests: 11, Assertions: 32, Errors: 1, Failures: 5.`, before any code). For the two later fixes: `red-review-fixes.txt`.
  - Passing: `case-tests-green.txt` (`OK (14 tests, 75 assertions)`).
- [x] Full suite has no new failures vs ef5fffa.
  - Baseline: `OK (340 tests, 3115 assertions)`.
  - After: `OK (354 tests, 3190 assertions)` (`baseline-ef5fffa.txt`, `full-suite-after.txt`).
- [x] The rendered modal HTML and JSON for case .1 are in the result (below, and in `011-evidence/case-1-punches-D.json` and `case-1-modal-and-pickers.html`).
- [x] Deploy notes (below).
- [x] The final line.

Case .1, `GET /attendance/punches?employee_id=<X>&date=2026-10-12` (from `case-1-punches-D.json`):
```json
{
    "employee_name": "Eleven One",
    "date": "2026-10-12",
    "count": 4,
    "punches": [
        { "time": "11:12:00", "time_display": "11:12:00 AM" },
        { "time": "16:00:00", "time_display": "4:00:00 PM" },
        { "time": "16:59:00", "time_display": "4:59:00 PM" },
        { "time": "01:11:04", "time_display": "1:11:04 AM", "next_day": true, "date": "2026-10-13" }
    ]
}
```
This is the modal and pickers HTML that the page's own `rawPunchText` / `punchPickerOptions` produce from that JSON (run in node; from `case-1-modal-and-pickers.html`):
```html
<ul class="list-group list-group-flush" id="punchesList">
  <li class="list-group-item py-1 px-2">11:12:00  (11:12:00 AM)</li>
  <li class="list-group-item py-1 px-2">16:00:00  (4:00:00 PM)</li>
  <li class="list-group-item py-1 px-2">16:59:00  (4:59:00 PM)</li>
  <li class="list-group-item py-1 px-2">1:11:04 AM (next day)<span class="small text-muted ms-2">2026-10-13</span></li>
</ul>
<div class="small text-muted mt-2"><span id="pCount">4</span> punches total.</div>
<select ... data-target="dInput_time_out"><option value="">Punches…</option><option value="11:12:00">11:12:00</option><option value="16:00:00">16:00:00</option><option value="16:59:00">16:59:00</option><option value="01:11:04">1:11:04 AM (next day)</option></select>
<select ... data-target="dInput_time_in">…<option value="01:11:04" disabled>1:11:04 AM (next day)</option></select>   (lunch_out, lunch_in: the same)
```
D+1's own list (case .4, `case-4-punches-D1.json`) is still `count: 2`: 01:11:04, 11:56:00, with no flags.

## How to run
```
git checkout feat/011-overnight-raw-punches
composer install
php vendor/bin/phpunit tests/Feature/Overnight/OvernightRawPunchesTest.php --testdox
php vendor/bin/phpunit
```
SQLite runs in memory, set by phpunit.xml. `node` must be on PATH for the two picker-render tests; without it they are skipped, not failed.

## Rulings
- Ruling: case .5 asserts "the day as the compute makes it from the same punches", not a fixed 480. 480 is asserted on the live day's 12:00–21:00 shift, and 468 (12 m late) on the spec's 11:00–20:00 shift — why: the spec's 11:00 shift and its "payable 480" contradict each other under the unchanged compute, and decision 4 asks for "as the repair did" — cost if wrong: none to code, only the test's expected number.
- Ruling: next-day punches are listed only when D has punches of its own as the compute sees them. A date with no punches, or whose only punch was taken as the date before's time out, gets none — why: such a date has no shift running past midnight, and offering the punch there let it be saved as a time out with no time in, which stays on D. That would break decision 4. Found by the skeptic review — cost if wrong: HR does not see a next-day punch on such a date, though it is still on D+1's own list.
- Ruling: the next-day option shows in all four pickers but can be picked only in Time Out — why: decision 2 says to show it in the pickers, but saving it as a time in or a lunch would store D 01:11. Disabled keeps it visible and safe — cost if wrong: one click less for an unusual edit.
- Ruling: in the Time Out picker it can be picked only when the day already has a stored time in (`data-time-in` of the cell) — why: with no time in, `typedTimeOut` has nothing to be before and keeps 01:11 on D. The test proves this and shows the save gives `2026-10-12 01:11:04` — cost if wrong: on a day with punches but no day row yet, HR first computes or saves the time in, then picks the time out.
- Ruling: every D+1 punch inside the window is listed (decision 1, "the punches of D+1 that fall inside D's overnight window"), not only the one the compute takes as the time out — why: raw punches are shown raw; the compute's choice already shows in the cell — cost if wrong: a D with its own time out may also show an early D+1 punch as "(next day)".
- Ruling: the modal shows own punches as before ("16:00:00  (4:00:00 PM)") and a next-day punch as "1:11:04 AM (next day)" plus its date in muted text — why: it uses the spec's exact wording and matches Approvals — cost if wrong: wording only.
- Ruling: the older attendance page's Raw Punches modal (`attendance/index.blade.php`) also labels next-day punches. It was not named in the spec but uses the same endpoint — why: without the label it would show 01:11 as a punch of D — cost if wrong: one line of view code.

## Deferred minors
From the skeptic review of case .5 and the edit path. No blocker; all 33 Overnight tests passed for the reviewer.
- If, in one Save All, HR clears the time in (trash mark) and picks the next-day time out on a day with no lunch punches, the time out stays on D 01:11. Not fixed: the picker is built when the modal opens, from the stored time in. Typing 01:11 by hand does the same (spec 009 behaviour), and manual-edit protection is a separate spec.
- A next-day punch picked by hand as D's time out is not removed from D+1's own punches. On a sparse D+1 the same punch can then count on both days. This is spec 009's behaviour for a typed 01:11 too, and manual-edit protection is out of scope. See Open questions.
- The picker's `change` handler does not call `markAllDirty()` (it didn't before either). Harmless: Save All compares every field with the stored value.

## Merge danger
Two-way door. One endpoint's JSON gains entries only on days that have a punch after midnight inside the window. Two views change their rendering. No schema, no data writes, no pay rule changes. Blast radius if wrong: the Raw Punches modal / pickers on overnight days. Revert: `git revert` of 07f6dcb and f064aea.

## Deploy notes
No migration, no config, no new env. After deploy: `php artisan view:clear` (or `optimize:clear`) so the blade changes show. No queue or worker restart is needed.

## Tests
- `tests/Feature/Overnight/OvernightRawPunchesTest.php`, 14 tests:
  - the endpoint JSON (.1–.4, .6);
  - the page's own render functions, run in node on the endpoint's JSON (.1, .5);
  - the save paths: CEO Save All, HR request then approve, no change, and no time in yet (.5).
- Latest: `OK (14 tests, 75 assertions)`. Full suite: `OK (354 tests, 3190 assertions)`, vs `OK (340 tests, 3115 assertions)` at ef5fffa.

## Open questions
- Should a next-day punch that HR picks (or types) as D's time out also be removed from D+1's own computed punches? Today it is not, so on a sparse D+1 (for example 01:11 and 11:56 only) the 01:11 can fill one of D+1's slots too. This belongs to the manual-edit protection spec that is out of scope here. Nothing in this change makes it worse than typing 01:11, which spec 009 already allowed.

## Process suggestions
- Make spec numbers come from the computed day. The spec's ".1 day" (11:00–20:00 shift) and its "payable 480" did not agree: In 11:12 is 12 minutes late on that shift. Evidence: `test_s_011_5_picked_next_day_time_out_saved_directly` gives 468.

## Proposed tasks
- Manual edits on overnight days: keep a picked or typed next-day time out from also counting on D+1, and keep a time out with no time in from staying on D — why: the deferred minors above and the open question — normal.

011: done — the raw punches and pickers of a day list its overnight time out as "(next day)"; picking it saves time out on D+1
