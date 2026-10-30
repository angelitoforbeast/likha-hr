# Spec 009: a time-out after midnight belongs to the day it started

## Why
Warehouse staff sometimes work past midnight. Example (live, 2026-10-16): punches In 11:12 · Lunch out 16:00 · Lunch in 16:59 · Out 01:11 the next morning. The app treats 01:11 as the same date, so the day reads "Time out is before time in", Undertime 18 h 49 m, Hours 4 h and half pay (₱359 of ₱718) on the payroll breakdown, although the person worked about 13 hours. The Approvals page (spec 006) already shows such a punch as "Next day: 1:11 AM".

## Decisions (do not reopen)
| # | Decision | Why |
|---|---|---|
| 1 | A punch after midnight is the previous work date's TIME OUT (not the next date's time in) when the previous date has a time in and no later punch, and the punch falls within the overnight window. | The owner's ruling. |
| 2 | Overnight window = until 06:00, or 3 hours before the employee's shift start on the next date, whichever is earlier; one setting (config or settings table), default as stated. | The owner did not know; this default was chosen and he accepted that overtime still needs approval. |
| 3 | Worked minutes, lunch, late, undertime and payable minutes are computed across midnight on the start date; regular pay stays capped at the shift as today; minutes past the shift end are overtime that is paid ONLY when approved (existing approval flow, 1.25×). | Owner: "yes, dadaan sa approval". |
| 4 | Night differential (+10% for 22:00–06:00, existing `night_differential_eligible` flag and payroll rule) counts the minutes across midnight for eligible employees only — use the existing rule; if the app has no night-differential computation yet, report that under Open questions instead of inventing one. | Keep pay rules where they are. |
| 5 | Rice allowance (per-day threshold rule in PayrollService) uses the start date's payable minutes. | Same day, same rule. |
| 6 | The next date's own punches are unaffected: its time in is its own first punch after the window. | No double use of a punch. |
| 7 | Applies to import compute, Recompute, the calendar edit/fill paths and the approval preview (all through the existing compute service); manual overrides stay as they are; locked dates are not recomputed. | One compute path. |

## Stories / cases (S-009)
P1:
- .1 In 11:12, L-out 16:00, L-in 16:59, Out 01:11 next day (shift 12:00–21:00 or the employee's shift): time out shows as next-day 01:11, worked ≈ 13 h, regular = full day, late 0, undertime 0, overtime minutes recorded but unpaid until approved, rice allowance full day.
- .2 The 01:11 punch is not the next date's time in; the next date's first punch after the window is.
- .3 A punch at 05:59 vs 06:00 (window edge) and a next shift starting at 08:00 (window ends 05:00): correct side of the edge.
- .4 Night differential for an eligible employee counts 22:00–01:11; not for a non-eligible one (or Open question if no ND logic exists).
- .5 A normal day (no overnight) computes exactly as before (regression).
- .6 Payroll daily breakdown and totals for a cutoff with an overnight day show the full day, the hours and the amount.
- .7 Approvals preview for an overtime request on such a day shows the right "Now / If approved" values (no "Time out is before time in").
- .8 A repair command (dry-run by default, `--apply` to write) recomputes past days that have a next-day punch inside the window and an unlocked date, listing what changes; it is not run in production by this spec.
P2: .9 the calendar cell and the attendance viewer show "01:11 (next day)".

## Threat model and risk
Trusted: HR/admin users. Risk tier: high (pay). Run a skeptic reviewer on the compute and payroll changes.

## Done when
P1 pass with tests (first failing, then passing); full suite no new failures vs 89b9676; the result lists every changed computation, the repair command's dry-run output on seeded data, deploy notes (migration only if the setting needs one; after deploy the owner decides whether to run the repair with --apply), and the final line.

## Out of scope
Changing overtime approval rules, night-shift schedules, payroll finalize, deploying, running the repair on production.

## Budget
