# Brief 006: the CEO sees the actual device punches beside each approval

## Why
On /approvals each request group (one employee + one date, with the Approve/Reject buttons) shows the requested values and, since spec 005, a preview of the day Now and If approved. The official times there are what the app computed from the punches, not the punches themselves. To judge a request (a missed time out, a lunch fix, an overtime claim) the CEO needs to see what the biometric device actually recorded that day. The owner (2026-10-30): the actual punches should also be visible on this page.

## Decisions (do not reopen)
| # | Decision | Why |
|---|---|---|
| 1 | **A "Device punches" block in every request group**, inside or directly above the spec-005 preview card, same layout on desktop and phone (below the request on phones, no sideways scroll at 390 px). It lists every punch stored in `attendance_logs` for that employee whose `punched_at` falls on the group's work date (00:00:00 to 23:59:59, the same calendar-day rule `AttendanceComputeService` uses), in time order, in the page's 12-hour format (e.g. 8:02 AM). Seconds are not shown. | The owner's request. |
| 2 | **A punch stored more than once** (same employee, same `punched_at`; older imports left duplicates) is shown once. | The duplicates are an import artefact, not extra punches. |
| 3 | **Next-day early punches**: the employee's punches on the next calendar day before 06:00 are shown on a separate muted line, labelled "next day" (e.g. "Next day: 1:11 AM"). They are only shown, never added to the day. | Overnight time outs land on the next date today; the CEO must see them when judging a missing time out. |
| 4 | **Which punch the day uses now**: a punch whose time (to the minute) equals the day's official Time in, Lunch out, Lunch in or Time out *now* (the values the preview's Now column shows) carries that label in small text (e.g. "8:02 AM · Time in"). Punches that match none are shown plain. No other interpretation, no warnings from this block. | Shows at a glance which punches were used and which were ignored. |
| 5 | **No punches**: "No punches from the device on this date." (plus the next-day line if any). | Clear for an absent day. |
| 6 | **Read-only and fast**: nothing is written; the punches for all groups on the page are loaded in one query (or one per page of groups), never one query per group. Same permission as the page (CEO); nothing else on the page changes, and Approve/Reject behave exactly as today. | The page lists many groups; narrow change. |
| 7 | Not on other pages (Attendance Calendar, the old Attendance page) in this brief. | Scope. |

## Cases (S-006)
P1:
- .1 a group shows all device punches of its date for that employee, in time order, 12-hour format, without seconds.
- .2 a punch stored twice is listed once.
- .3 a punch at 1:11 AM the next day is shown on the "Next day" line; a punch at 6:30 AM the next day is not shown; the next-day punch does not change the preview or the day.
- .4 a date with no punches shows "No punches from the device on this date."
- .5 the punch equal to the official Time in now is labelled "Time in" (same for Lunch out, Lunch in, Time out); an extra punch in between is unlabelled.
- .6 another employee's punches on the same date never appear in this group.
- .7 rendering the page writes nothing (row counts and updated_at unchanged, as the spec-005 test does) and the number of queries for punches does not grow with the number of groups (30 groups).
- .8 at 390 px the block sits below the request with no sideways scroll.
- .9 existing suites pass.

## Threat model and risk
Trusted: the CEO viewing the page; punches come from the admins' device imports. Untrusted: nothing new is accepted from the browser (no new input or endpoint). Risk tier: low (read-only display on a CEO-only page).

## Done when
All P1 cases pass with a test each; the full suite adds no new failures vs 95455a4 (record the baseline first). The result has: rendered HTML or a screenshot of a request group with real-shaped fake data (desktop and 390 px), the query count for 30 groups, the cases table, deploy notes (migrations: none expected; caches), and ends with "006: <done|blocked> — <one line>".

## Out of scope
Changing how days are computed (the overnight rule, duplicates clean-up), editing punches, new pages, deploying.

## Budget
Small job. At most 2 attempts at the same failing step, then stop and write what you tried and what you need into the result.
