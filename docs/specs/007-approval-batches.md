# Spec 007: one approval per shift change, and requests that change nothing close themselves

## Why
On https://likhahr.tech/approvals the owner sees dozens of shift requests (Joann 12, Royelle 13 for Oct 11–25), each "Asked by Ariane on Oct 26, 2026 4:44 PM": one change by the admin became one request per date. Meanwhile the admin also set the same shift (8-5PM) as a shift assignment on the employee profile, which applies directly, so the official value already equals what the requests ask ("official now: 8-5PM") and the requests change nothing. The owner: one approval for a shift change like that ("yes, sa shift ganun"), and requests that no longer change anything should close by themselves ("okay sige").

## What exists (read before planning)
- `app/Services/AttendanceChangeRequestService.php` (`submit($employee, $date, $fields, $reason, $actor, $origin, $batchId)`; `officialValue()`; `mustRequest()`), `app/Models/AttendanceChangeRequest.php` (fields incl. `origin`, `batch_id`, `status`, `field`, kinds incl. shift), `app/Services/AttendanceApprovalService.php` (grouping for the page, approve/reject, previews, `devicePunches()` from spec 006), `app/Http/Controllers/AttendanceCalendarController.php` (single-date `assignShiftForDate`, bulk actions with `$batchId`), `resources/views/approvals/*`.
- Find from the code (and the tests' factories) how the 4:44 PM requests were created: a bulk action with a batch_id, or many single-date calls. Write the answer under Rulings.

## Stories (S-007)
P1 — One approval per shift change
- .1 Shift requests from the same change are shown as ONE card: same employee, kind shift, same requested shift, same requester, and either the same batch_id or (no batch_id) submitted within the same minute — e.g. "Joann Dolorosa · SHIFT → 8-5PM · Oct 11–Oct 25 (12 days) · Asked by Ariane on Oct 26, 4:44 PM · Reason …". The card lists the dates compactly (ranges), the device punches per day (spec 006) and the preview per day collapsed under "See each day", with the totals of the change (late/undertime/worked minutes now vs if approved summed over the days).
- .2 One Approve on the card approves every pending request in the group exactly as approving them one by one does today (same computation, same change log naming requester and approver, same date-lock rules); one Reject with a reason rejects them all. Per-day approve/reject stays possible inside "See each day".
- .3 A locked date inside a group: that day cannot be approved (as today), the card says which day is blocked, the others can still be approved together.
- .4 The "Select all on this page / Approve selected / Reject selected" bulk controls work with cards (selecting a card selects its days). The pending count in the sidebar badge counts cards (changes), with the number of days shown on the card.
- .5 Other kinds (times, rest day, overtime) keep today's per-day cards. Do not group them in this spec.
P1 — Requests that change nothing close themselves
- .6 A pending request whose requested value now equals the official value (e.g. the shift is already 8-5PM through a profile shift assignment, or someone already made the same edit) closes itself with a new final status such as "no longer needed" (not approved, not rejected): it leaves the pending list, writes nothing to attendance, keeps its record (who asked, when, why it closed) and shows under its own tab or filter. This is checked when the Approvals page is loaded or a request is acted on, and by a light scheduled pass (or after the actions that change official values — choose the most reliable point and say why under Rulings); never on a locked date's history.
- .7 Approving a group where some days are already equal to official approves only the days that still change something and marks the rest "no longer needed".
- .8 The days that closed as "no longer needed" because the profile assignment already applied must still be RECOMPUTED with the official shift if their computed values (late/undertime/worked) were made with the old shift — use the existing compute path for those dates (as an approval would), so payroll sees the right minutes. Say exactly what is recomputed in the result.
P2
- .9 Phone 390 px layout of the grouped card.

## Decisions (do not reopen)
| # | Decision | Why |
|---|---|---|
| 1 | Group only shift requests, by change (batch or same minute), as one card with one Approve/Reject. | The owner: "sa shift ganun". |
| 2 | Requests whose value already equals official close automatically as "no longer needed"; they never write attendance; the record stays. | The owner: "okay sige". |
| 3 | Approval rules, CEO-only approval, date locks, the change log and payroll reads stay as they are. | Narrow change. |
| 4 | At most one additive migration (e.g. the new status value if it needs a column/enum change); no deletion of any request. | Keep history. |

## Threat model and risk
Trusted: signed-in CEO (approver), admin/hr_staff (requesters). Untrusted: request ids and group keys posted by the page (re-check every request in a group server-side: same employee, kind, value, pending, not locked, company). Risk tier: medium (approving many days at once changes pay; a wrong group would approve days the CEO did not see).

## Cases to test
Feature tests for .1–.8 (grouping keys, approve-all = same result as one by one incl. change log, reject-all, locked day inside a group, auto-close equal-to-official, partial group, recompute of closed days with the new shift), plus existing approval tests green (list changed assertions under Story changes). Rendered evidence page of the Approvals screen with a grouped shift card and a "no longer needed" tab, saved under docs/specs/007-evidence/.

## Done when
P1 cases pass with tests; the full suite adds no new failures vs 9773f8f; evidence page; result with rulings (how the 4:44 PM requests were made; where the auto-close check runs), deploy notes (migration if any; caches), and the final line.

## Out of scope
Changing the profile shift-assignment path (it stays direct), grouping other kinds, deploying.

## Budget
Medium job. At most 2 attempts at the same failing step, then stop and write what you tried and what you need into the result.
