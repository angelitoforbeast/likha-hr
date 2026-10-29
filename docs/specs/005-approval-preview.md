# Brief 005: the CEO sees the whole day as it will be, before approving

## Why
On /approvals the CEO sees each requested time with "official now: …", one field at a time (e.g. an employee's 2026-10-13: Lunch out 4:00 PM (now 4:45 PM), Lunch in 4:45 PM (now 9:04 PM), Time out 9:04 PM (now none)). He cannot see what the employee's day becomes if he approves: the four punches together, the lunch length, worked hours, late, undertime, overtime, or whether the day is still a problem. The owner (2026-10-29): put a preview on the right side showing how it will look if approved.

## Decisions (do not reopen)
| # | Decision | Why |
|---|---|---|
| 1 | **A preview beside each request group** (one employee + one date, the block that has the Approve/Reject buttons): on desktop a right-hand column, on phones below the request. Two columns, **Now** and **If approved**: Time in, Lunch out, Lunch in, Time out, shift (if a shift change is requested), lunch length, worked hours, late, undertime, overtime (computed and approved), needs-review flag and its note. Values that change are highlighted; times in the same 12-hour format the page uses. | The owner's request. |
| 2 | **Computed by the real code, never saved:** "If approved" runs the same path Approve uses (the same service methods and AttendanceComputeService rules: rounding, shift, lunch, OT, needs_review as fixed in spec 004) on in-memory copies or inside a database transaction that is always rolled back. Nothing is written: no attendance_days/overrides/audit/change-log rows, no events, no jobs. A test proves the tables are unchanged after rendering the page, and another proves the preview equals the day after a real approval for the same request (several cases: missed timeout, lunch fix, overtime request, shift change). | Must match what Approve does. |
| 3 | **Warnings** in the preview when the result is still unusual: still missing a punch, lunch longer than the shift's lunch rule or under 0, time out before time in, worked hours under the shift, overtime not covered by an approved OT. Plain words, one line each. | The CEO decides with the risk in view. |
| 4 | If the preview cannot be computed (missing schedule, error), show "Preview not available" with the reason; the Approve/Reject buttons and the rest of the page work as today. Never a 500. | Safe. |
| 5 | Page speed: the page lists many groups; compute previews efficiently (reuse loaded days/schedules; no N+1); state the measured time for 30 groups. If it is too slow, load each preview lazily (a small read-only endpoint, same permission as the approvals page) — your call, explain it. | The CEO opens this often. |
| 6 | No change to how Approve/Reject work, to permissions, or to the data. | Narrow change. |

## Cases (S-005)
P1: .1 a missed-timeout request shows Now (no time out, needs review) vs If approved (time out set, worked hours, review cleared); .2 a lunch fix shows the new lunch length and hours; .3 an overtime request shows the approved OT; .4 a shift change shows the new shift and recomputed late/undertime; .5 rendering the page writes nothing (row counts and updated_at unchanged); .6 the preview equals the saved day after approving the same request; .7 a group whose preview fails shows "Preview not available" and can still be approved; .8 on a 390 px screen the preview is below the request with no sideways scroll; .9 existing suites pass.

## Done when
P1 pass; the full suite adds no new failures vs f78f3d4 (record the baseline). Result with a screenshot or rendered HTML of the page with real-shaped fake data (desktop and 390 px), the speed measurement, deploy notes (migrations, caches), and the cases table. End with "005: <done|blocked> — <one line>".
