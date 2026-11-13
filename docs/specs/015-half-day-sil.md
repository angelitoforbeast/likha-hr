# Spec 015: half-day SIL

Weight: bounded. Risk tier: high (SIL feeds payroll and the leave balance). Size: medium.

## Why
An SIL is always one whole day today: `sil_applications` has one row per date and no amount, and used days = row count. Staff need to file half a day. Real case: Xander did not work on Nov 1, 2026 (All Saints' Day, a special non-working holiday, not his rest day) and filed half-day SIL; the system paid a full day (645.00) and used 1 day of his balance. Correct is 322.50 and 0.5 day used.

## Plan (approved by the owner)
1. **Data.** `sil_applications.days` decimal(3,1), not null, default 1.0; allowed 1 and 0.5. Existing rows stay 1.0. One row per employee and date stays.
2. **Filing.** Every place that files SIL offers "Whole day / Half day" (default whole): the attendance calendar modal, the employee profile add-SIL, and the HR/staff request that waits for the CEO. The request carries the half/whole choice through approval; the approval card and preview say "Apply SIL (half day)" for a half. Changing a whole SIL to a half (or back) = remove and apply again.
3. **Balance.** Used = SUM(days) for the year everywhere (`Employee::getSilUsedDays` returns float; the profile stops counting rows). Refuse to apply when remaining < the days asked, with the existing message. Remaining shows like 3.5.
4. **Pay** (`PayrollService`). SIL pay for a date = that day's rate × days, part of basic pay as now.
   - Half SIL on a required workday with no attendance: rate × 0.5 paid; the other half is absent (0.5 absent day, deducted at half the day's rate).
   - Half SIL on a special non-working holiday not worked: rate × 0.5 paid, no absence, no deduction.
   - Half SIL on a day with attendance: the worked part is paid as a normal day's work up to 4 hours (240 min) and its late/undertime counts only against those 4 hours; the SIL half is paid on top. Never above 1 day's rate (approved OT as today). A whole SIL keeps today's behaviour exactly.
   - Rest day / regular holiday combinations: whatever a whole SIL does today, scaled by days.
   - `payroll_items.absent_days` holds 0.5 (decimal(4,1)).
5. **Display.** The daily breakdown entry of a half SIL date: status "SIL ½", type stays `sil`, `sil_days` added, amount = what is paid for that day. Payslip, calendar cell and profile list show "½" for halves. Audit logging as for SIL today.

## Tests (first, red, then green)
Half SIL on a required day with no punches; on a special non-working holiday not worked; with 4 h of punches; with 3 h of punches; balance sums halves, refuses a half at 0, allows a half at 0.5; HR request for a half → CEO approves → row has 0.5; whole SIL unchanged; the migration leaves existing rows at 1.0.

## Constraints
Work only in the `feat/015-half-day-sil` worktree from cdb67ca (production). No push, merge, ssh or deploy. Commit on the branch with a conventional message.
