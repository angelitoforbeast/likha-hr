# Plan: spec 011 overnight raw punches

## What changes
1. `AttendanceController::punches` (`GET /attendance/punches`): after date D's own punches, append the
   punches of D+1 from 00:00 up to (not including) `AttendanceComputeService::overnightWindowEnd($employee, D+1)`,
   the same window the compute uses. Each appended punch carries `next_day: true` and `date: D+1`.
   `count` counts them. D's own punches keep exactly the keys they have today (`time`, `time_display`),
   so a day without such punches returns the same JSON as before.
   Appended only when D has punches of its own: a day with no punches has no shift to run past midnight,
   and the compute never gives it a time out from the next date either.
2. `resources/views/attendance-calendar/index.blade.php`:
   - Raw Punches modal: a next-day punch is listed after the date's own as "1:11:04 AM (next day)"
     with its date beside it in muted text; the count line uses `data.count`, which includes it.
   - Punch pickers: next-day punches are listed after the date's own, labelled "1:11:04 AM (next day)".
     They can be picked only in the Time Out picker (value "01:11:04"); in the other three pickers they
     are shown disabled, because a time in or lunch saved there would be stored on D, the wrong date.
   - The rendering lives in two small pure functions (label of a punch, options of a picker) so the
     output can be checked with node from the endpoint's JSON.
3. `resources/views/attendance/index.blade.php` (the older attendance page) uses the same endpoint for
   its own Raw Punches modal: its list gets the same "(next day)" label so a next-day punch never reads
   as a punch of D.

## Edit path (decision 4)
The pickers fill the time input with "H:i:s"; Save All / Override store the time of day, and since
spec 009 `typedTimeOut` turns a time out before the time in that falls inside the next date's window
into D+1. The picker only offers punches inside that same window, so a picked next-day punch is
always stored on D+1. Verified by a test, not by assumption (CEO direct save, and HR request then
approve).

## Tests (test-first), tests/Feature/Overnight/OvernightRawPunchesTest.php
- .1 JSON for D (4 punches, 4th 01:11:04 next day, 11:56 absent), and the modal/picker rendering via node.
- .2 window edges (05:59 in / 06:00 out; D+1 shift 08:00: 04:59 in / 05:30 out).
- .3 normal day: JSON identical to the pre-change shape.
- .4 D+1's own list still has 01:11:04.
- .5 save of the picked value gives time out D+1 01:11:04, payable 480 (CEO direct; HR request + approve).
- .6 first day of records; D+1 with no punches; D with no punches.

## Steps
1. Baseline suite (340 tests, OK at ef5fffa). 2. Tests, see them fail. 3. Endpoint. 4. Views + node check.
5. Skeptic review of .5 and the edit path. 6. Full suite, result file.
