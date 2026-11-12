# Spec 013: a rate change by staff waits for the owner's approval, and a new rate shortens an open-dated rate instead of failing

Weight: bounded. Risk tier: high (pay rates feed payroll). Size: medium.

## Why
On 2026-11-12 staff could not add raises for three employees. Adding a rate that starts inside a current rate with a future end date (e.g. "For Probi" rates ending in 2027) fails with "Rate overlaps with existing rate", while for open-ended rates the app already shortens the old rate to the day before. The owner also wants rate changes entered by staff to wait for his approval before they count; today anyone with the `daily_rates` feature changes pay at once.

## Stories
- S1 (P1) **Overlap fix.** Adding a rate whose start date falls inside an existing rate that has a future end date shortens that existing rate to end the day before the new start (exactly like the existing open-ended behaviour), and the new rate is saved. Still refused: a new rate that would sit entirely inside another closed range in a way that cannot be resolved by shortening (e.g. starts before an existing rate's start and overlaps it) — keep a clear message. Editing a rate gets the same rule where it applies. The audit log records the shortened rate's old → new end date.
- S2 (P1) **Approval.** A new or edited daily rate saved by a non-owner (anyone except the owner/super-admin role — find what the app calls it) is stored as **pending**: it does not count anywhere (payroll, employee current rate, reports) and does not shorten other rates yet. The owner sees pending rate changes in one place (a list with employee, old rate → new rate, dates, who asked, when) and approves or rejects each (optional note). On approve, the rate becomes active and the S1 shortening happens at that moment (re-validated against current data); on reject nothing changes. The person who asked sees the status on the employee's rate list ("Waiting for approval" / "Rejected: note").
- S3 (P1) **Owner saves directly.** A rate saved by the owner is active at once, as today.
- S4 (P1) The existing rate audit log stays as is and also records submitted / approved / rejected with who and when.
- S5 Payroll already computed for past periods is not recomputed by this change.

## Constraints
- Smallest schema change (e.g. status + requested_by + approved_by + approved_at + note on the existing rates table, with existing rows = approved); explain the choice. Every query that reads rates for pay must only see approved rows — add a test that proves pending rates never reach PayrollService.
- Follow the app's existing UI style and language.
- Commit: `feat(rates): staff rate changes wait for the owner's approval and a new rate shortens an open-dated one`; no co-author, session or generated-with lines, no person names. Save this spec as docs/specs/013-rate-approval.md and a result .result.md (match 011/012 style).

## Allowed
composer install, php artisan test, php artisan migrate (local only), vendor/bin/pint on changed files (if the repo uses it), git add/commit, `git push -u origin feat/013-rate-approval`. Do NOT merge, open a PR, push to main or deploy.

## Report back
Branch and SHA, migration, how pending rates are kept out of payroll, the approval screen route, test results, anything that could not be done.

## Budget
2 attempts per failing step; stop and report if blocked.
