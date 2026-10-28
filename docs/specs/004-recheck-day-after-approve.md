# Brief 004: approving a change rechecks the day, so an old "needs review" goes away when the day is complete

## Why
On 2026-10-29 00:50 the CEO approved three change requests for one employee's 2026-10-12 (lunch out 14:01:41, lunch in 14:36:01, time out 19:15:37). The approval applied them (attendance_overrides 1232–1234; attendance_days 3539 now has all four punches and computed_work_minutes 480), but the day kept `needs_review = 1` and the old note "Lunch punches could not be inferred. No Time Out recorded (missed timeout)." So the Attendance Viewer/Calendar still show the day as a problem although it is complete. Note `AttendanceChangeService` (around line 141) sets `'needs_review' => true` on some path, and `AttendanceComputeService` computes needs_review and notes from punches (and has an override-aware compute / "Force Recompute" path).

## Decisions (do not reopen)
| # | Decision | Why |
|---|---|---|
| 1 | **After an approval is applied** (single approve, approve several, batch approve — every path that applies a change request), the system recomputes that attendance day with the same override-aware compute the app already uses (the Force Recompute logic), in the same transaction as the apply. `needs_review` and `notes` then come from the final punches: complete and no anomaly → `needs_review = 0` and the stale note gone; still incomplete or anomalous → `needs_review = 1` with a note listing only the remaining problems. Find why the apply path sets needs_review true and correct it only as far as this decision needs; explain it in the result. | The CEO only clicks Approve; the system keeps the day honest. |
| 2 | Recompute must not undo the approved values, other overrides, approved overtime, SIL/rest-day state, or date locks; if a day is locked (attendance_date_locks) follow the app's existing rule for locked days and say what it is. | Safe. |
| 3 | **One-time fix** for days already approved before this change: an artisan command (dry run by default, `--apply` to write) that recomputes the attendance days that have approved change requests, prints each day (employee, date, needs_review before → after, note before → after), and is idempotent. | Days approved earlier keep a stale flag otherwise (at least 2026-10-12 for employee 51). |
| 4 | Rejecting does not recompute (nothing changed). Audit logs/edit logs keep working as today. No UI change needed beyond the day now showing correctly. | Small change. |

## Cases (S-004)
P1: .1 a day flagged "No Time Out" gets an approved time_out (and lunch pair) → after approve needs_review = 0 and the note no longer mentions the fixed problem; .2 approving only lunch_in on a day still missing time_out → needs_review stays 1 and the note mentions only the missing time out; .3 approve several / batch approve recompute every affected day once; .4 approved overtime, other overrides and SIL/rest-day flags are unchanged by the recompute; .5 the command's dry run lists the day of case .1 with before → after and changes nothing; --apply writes; a second --apply changes nothing; .6 reject does not recompute; .7 existing suites pass.

## Done when
P1 pass; the full suite adds no new failures vs 6ab1971 (record the baseline). Result with: why the day stayed flagged, every apply path changed, the locked-day rule, the command usage, and deploy notes (deploy = push to main, then run the command dry run, then --apply). End with "004: <done|blocked> — <one line>".
