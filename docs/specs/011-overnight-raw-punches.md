# Spec 011: the raw punches of a day include its overnight time out

## Why
Spec 009 made a punch after midnight count as the previous work date's time out (live, 2026-10-16: In 11:12 · Lunch out 16:00 · Lunch in 16:59 · Out 01:11 on 2026-10-17; the day now computes 8 h). But the attendance calendar's "View Raw Punches" modal and the four punch pickers in the edit modal still list only punches dated on the calendar day (`AttendanceController::punches`, `GET /attendance/punches?employee_id=&date=`, whereBetween startOfDay..endOfDay). So HR opens Oct 16, sees only 11:12, 16:00, 16:59, and reads the day as missing its time out; the 01:11 punch is visible only under Oct 17. The Approvals page (spec 006) already shows such a punch as "Next day: 1:11 AM".

## Decisions (do not reopen)
| # | Decision | Why |
|---|---|---|
| 1 | For date D, the punches endpoint also returns the punches of D+1 that fall inside D's overnight window, using the same window as the compute (`AttendanceComputeService::overnightUntil()` / `overnightWindowEnd()`, config `attendance.overnight`: until 06:00, or 3 h before the next date's shift start, whichever is earlier). Reuse that code; do not write a second window rule. | One rule for compute and display. |
| 2 | Each such punch is flagged (e.g. `next_day: true` plus its date) and shown after D's own punches as "1:11:04 AM (next day)" in the Raw Punches modal and the punch pickers, matching the Approvals wording. The count line counts them too. | HR sees the full shift on the day it belongs to. |
| 3 | D+1's own list is unchanged: it still shows 01:11 as a raw punch of that date. | Raw punches stay raw; nothing is hidden. |
| 4 | Picking a next-day punch in the time-out picker and saving stores the time out on D+1 (e.g. 2026-10-17 01:11:04), so the day computes as the repair did (8 h); it never stores D 01:11. If the existing edit path cannot store a next-day time out correctly, list next-day punches in the Raw Punches modal only, leave them out of the pickers, and report that under Open questions. | No edit may bring back the "time out before time in" day. |

## Stories / cases (S-011)
P1:
- .1 D punches 11:12, 16:00, 16:59; D+1 punches 01:11 and 11:56 (shift 11:00–20:00 on both dates): `/attendance/punches` for D returns 4 punches, the 4th is 01:11:04 flagged next day; 11:56 is not included. The modal renders "1:11:04 AM (next day)" and "4 punches total".
- .2 Window edges: a D+1 punch at 05:59 is included and 06:00 is not; with a D+1 shift starting at 08:00 (window ends 05:00), 04:59 is included and 05:30 is not.
- .3 A normal day with no punch after midnight returns exactly the same JSON as before (regression).
- .4 D+1's own list is unchanged (still includes 01:11 as its own punch).
- .5 Edit modal on D: the time-out picker offers "1:11:04 AM (next day)"; saving it gives time out D+1 01:11:04 and payable 480 for the .1 day (or, per decision 4, the documented fallback and an Open question).
P2: .6 the first day of the employee's records and a D+1 with no punches behave (no error, no extra rows).

## Threat model and risk
Trusted: HR/admin users; the endpoint stays behind the existing auth and permission. Risk tier: medium (display, plus the time-out edit path that feeds pay). Run a skeptic reviewer on case .5 and the edit path.

## Done when
P1 pass with tests (first failing, then passing); full suite no new failures vs ef5fffa; the rendered modal HTML or JSON for case .1 is in the result; deploy notes (no migration expected; caches only); the final line.

## Out of scope
The overnight rule itself (span limits, manual-edit protection: a separate spec), the calendar cell, payroll, Approvals page, deploying.

## Budget
Small job. At most 2 attempts at the same failing step, then stop and write what you tried and what you need into the result.
