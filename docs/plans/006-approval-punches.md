# Plan: spec 006 The CEO sees the actual device punches beside each approval

Base: `main` at `95455a4`. Branch: `feat/006-approval-punches`.
Baseline full suite at `95455a4` (this worktree, `php vendor/bin/phpunit`): `OK (277 tests, 2334 assertions)`.

## What the code does today

- `AttendanceApprovalController::index` pages the (employee, work date) pairs, 50 per page, and
  `buildEntries` builds one entry per pair. Only pending entries get `official`, `drift`, `blocks`,
  `day` (the day row via `findDay`) and the spec-005 `preview`.
- `resources/views/approvals/index.blade.php` lays an entry out as `.approval-request`, then
  `.approval-side` (the preview card, `_preview.blade.php`), then `.approval-actions`. One column
  below 992 px, request | side from 992 px up. The preview's Now column reads `time_in`,
  `lunch_out`, `lunch_in`, `time_out` of that same day row.
- `attendance_logs(employee_id, punched_at)` is indexed; the compute takes a day's punches as
  `punched_at` between the date's 00:00:00 and 23:59:59.

## Change

1. `AttendanceApprovalService::devicePunches(array $pairs)`: one read-only query for all pairs of the
   page: `SELECT DISTINCT employee_id, punched_at FROM attendance_logs WHERE (employee_id = ? AND
   punched_at >= 'D 00:00:00' AND punched_at < 'D+1 06:00:00') OR …  ORDER BY punched_at`. `DISTINCT`
   drops a punch stored twice. Grouped in PHP into `day` (on D) and `next_day` (D+1 before 06:00),
   keyed `employeeId_date`. One clause per pair keeps the read to exactly those days, through the
   existing index, whatever the spread of dates on the page.
2. `buildEntries`: for pending entries only, `entry['punches']` = the day's punches each with its
   labels (Time in / Lunch out / Lunch in / Time out where the punch equals that official value of the
   day row to the minute, date included) and the next-day times. The punches are loaded once before
   the loop.
3. View `approvals/_punches.blade.php`, included inside `.approval-side` above the preview card:
   heading "Device punches", one line per punch ("8:02 AM · Time in", label in small muted text),
   "No punches from the device on this date." when none, and a muted "Next day: 1:11 AM, …" line
   when any. Wraps; no fixed widths, so nothing widens the page at 390 px.

Nothing else changes: no input, route, write or permission.

## Tests (written first, seen failing): `tests/Feature/Approvals/ApprovalPunchesTest.php`

One test per case S-006.1 to .8, on the existing `ApprovalsTestCase` fixtures; the page's HTML is
read per entry card as the spec-005 tests do. .7 snapshots every table before and after two page
loads and counts the queries on `attendance_logs` with `DB::listen` for 1 and for 30 groups.
.8 checks the block's order (request, punches, preview, buttons) and that its CSS has no fixed
width. .9 is the full suite.

## Evidence for the result

The rendered HTML of one entry with real-shaped fake data saved under `docs/specs/006-evidence/`,
and a local headless-browser screenshot at desktop width and 390 px if the browser on this machine
can render it without downloading anything.
