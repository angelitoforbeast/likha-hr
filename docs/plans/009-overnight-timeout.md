# Plan 009: a time-out after midnight belongs to the day it started

## Findings that shape the plan
- The compute groups punches by calendar date (`AttendanceComputeService::computeForEmployee`), so a
  01:11 punch is the next date's only punch: the start date has no time out and the next date gets a
  day row with time in 01:11.
- Every typed time (calendar Save All, the cell edit, Fill from shift, bulk, the old Attendance page,
  an approved time request) is written by `AttendanceChangeService::applyTimes`, which stores
  `work_date + 'H:i:s'`. A typed 01:11 time out is therefore stored on the start date and is before
  the time in: that is the live "Time out is before time in", undertime 18 h 49 m, 4 h.
- `computeMetrics` already works across midnight when the time out is a full next-day datetime:
  the afternoon overlap stops at the shift end, undertime is 0, overtime is shift end to time out.
  Regular minutes stay capped at the shift. Payroll pays overtime only from
  `approved_overtime_minutes` (1.25x), and rice allowance reads `payable_work_minutes`.
- No night-differential computation exists anywhere (only the `night_differential_eligible` flag and
  its screens). Case .4 becomes an open question.
- No settings table exists; the window is a config entry.

## Design
1. `config/attendance.php`: `overnight.until` (06:00) and `overnight.hours_before_next_shift` (3).
2. `AttendanceComputeService`:
   - `overnightWindowEnd(employee, nextDate)`: the earlier of `nextDate until` and the next date's shift
     start minus the hours. Exclusive end.
   - Import/Recompute: `computeForEmployee` reads two days before and the next date's early punches,
     and walks the dates in order. Date D takes the last punch of D+1 inside D+1's window as its time
     out when D's own punches give a time in and no time out. Those window punches then leave D+1.
     `assignTimestamps` gets that punch for the time-out slot only.
   - `overnightTimeOut(...)`: a stored or typed time out on the work date that is before the time in
     and inside the next date's window is the next day's. Used by `recomputeDay` (every applyTimes
     ends there), by `computeDayWithOverrides` for kept values (Recompute, recheck after approval).
     The override row itself keeps the typed value.
   - `computeEmployeeDays` (import): a date that received a punch inside its window also computes the
     previous date (unless locked), so a file that brings only the 01:11 fixes the start date.
3. Approvals preview: `dayPicture` marks a next-day time out; the "Time out is before time in" warning
   compares real datetimes; the preview shows "1:11 AM (next day)".
4. Repair: `php artisan attendance:repair-overnight --from= --to= [--apply]`. Dry run by default: for
   every unlocked start date with a next-day punch in the window, or a stored time out before the time
   in that falls in the window, it recomputes the start date and the next date inside a transaction,
   lists before/after (time out, worked, late, undertime, overtime, payable) and rolls back unless
   `--apply`. A next date left with no punch of its own is listed, not deleted.
5. P2: the calendar cell and the attendance viewer show "01:11 (next day)".

## Tests first (tests/Feature/Overnight/OvernightTimeOutTest.php)
.1 the live day from punches (import and Recompute), .2 next date's time in, .3 05:59/06:00 and 04:59/05:00
with an 08:00 next shift, .5 normal days unchanged, .6 payroll breakdown and totals, .7 approvals preview
(typed time out and OT request), .8 repair dry run and apply, typed 01:11 time out through Save All,
.9 rendered calendar cell and viewer. Then the full suite against the baseline (319 tests, all green).
