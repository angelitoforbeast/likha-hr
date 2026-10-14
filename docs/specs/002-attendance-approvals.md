# Spec 002: The CEO approves attendance changes made by HR staff and admins

**Project:** Likha HR — attendance and payroll app: biometric (ZKTeco) punch imports, per-day attendance computed against shifts, an Attendance Calendar with manual overrides and date locks, payroll and payslips.
**Stack:** Laravel 10, PHP 8.4 locally, MySQL in production, Blade + Bootstrap, database queue (one worker runs `queue:work database`).
**Shape:** change · **Weight:** architectural · **Risk tier:** high

> Committed with the work as `docs/specs/002-attendance-approvals.md`. Names no people and no decision ids: "the owner" decided, "the reviewer" checks and merges.

---

## Why

Roles are `ceo`, `admin` and `hr_staff`. Today, on the Attendance Calendar (`/attendance-calendar`):

- A day with no `attendance_days` row on a non-rest, non-SIL date is shown as "absent". It is derived, never stored (`AttendanceCalendarController` index, about lines 143-298).
- Only the CEO can put times on such a day. The server refuses everyone else in four places: `createDay` (about 363), `saveAll` when there is no row (about 512), `fillFromShift` (about 739) and `ensureAttendanceDayForBulk` (about 1005). The page shows the inputs to every role, so HR staff type the times and get a 403 message.
- On a day that already has a row, HR staff and admins change times, approved overtime, SIL, the shift and the rest day directly, with no role check (`saveAll`, `overrideTime`, `assignShiftForDate`, `applySil`, `removeSil`, `toggleDayOff`, the bulk action). Every edit takes effect at once and payroll reads it.
- The older Attendance page has an edit path with no role check and no lock check at all (`AttendanceController::override`, `/attendance/override`), and its `createDay` checks the role but not the date lock.

The owner wants one rule: **HR staff and admins propose, the CEO approves.** What an HR staff member or an admin enters on a day (with the reason they already have to give) is saved as a pending request. The calendar marks the day "pending". Payroll and every total keep seeing the day as it officially is. A new "Approvals" link, for the CEO only, lists what is waiting with a count; the CEO approves (the change becomes real attendance exactly as if the CEO had entered it, and the record says who asked and who approved) or rejects with a reason the requester can read. The one exception is the rest day: turning an absent day into a rest day stays direct, as today. The CEO's own edits stay direct.

Payroll is why this is high risk: `PayrollService` reads `attendance_days` only and counts ANY row on a non-rest date as a worked day (about lines 113-115, 177-188, 438), and `AttendanceComputeService` treats every `attendance_overrides` row as "keep this field" (about 77-91, 367-381). A pending value must therefore exist in neither table, not even as a blank row, until the CEO approves it.

**This spec is plan first.** Read the code, then write `docs/plans/002-attendance-approvals.md` and stop for the reviewer's "go" before building anything. The plan states: where pending requests are stored (the table or tables, their columns, the unique rule that makes "one pending request per employee, date and field" impossible to break under two requests at once); the ONE write path per kind of change that both the CEO's direct edit and an approval go through (name the methods you will extract and every caller that moves onto them); the transaction and the row lock of an approval, and what is re-checked inside it; how each existing entry point decides "direct or request"; the routes and the pages; how the calendar and the older Attendance page learn which days are pending without a query per cell; the migration; your questions (at most five, each with your recommended answer).

## Stories

Add the slice below to `qa/stories.md` on this branch, after Slice 001, and fill the "Test" and "Last run" columns as you go. The stories are the done-when. The line numbers given in this spec are from a read of `main` at `ac3534a`: verify before you rely on one.

### Slice 002 – Approvals for attendance changes (heading in qa/stories.md: `## Slice 002 – Approvals for attendance changes`)

Fixtures used in every case. Users: C (role ceo), H and H2 (role hr_staff), M (role admin). Employees: E1 and E2 (active, shift 08:00–17:00 with lunch 12:00–13:00), E3 (inactive). Dates: Dn is an unlocked weekday where E1 has no day row, no rest day and no SIL; Dr is an unlocked weekday where E1 has a day row from punches at 08:03, 12:00, 13:00 and 17:00; Dl is a locked date. "Request" means one pending change asked by HR staff or an admin; the Approvals page shows one entry per employee and date that holds all of that day's pending fields, and an entry is approved or rejected as a whole. "Official" means what is in `attendance_days`, `attendance_overrides`, `day_offs`, `sil_applications` and `employee_shift_assignments`. "Day row" means one `attendance_days` row for that employee and `work_date`. "Payroll" means what `PayrollService` computes for E1. "Direct" means saved at once with no request. Cases that compare payroll note E1's payroll numbers, the count of E1's day rows and Dr's `updated_at` before the action. Rules in force: an admin is treated exactly like HR staff (asks, never approves); times, approved overtime, applying and removing SIL, assigning a shift for a date and bulk actions by HR staff or an admin all go through approval; a rest day change (add, cancel or remove) is direct when the employee has no day row on that date and goes through approval when there is a day row; the CEO's own edits are direct; only the CEO approves; a locked date takes no request and cannot be approved while locked.

### S-05 HR staff asks for times on a day (P1)
As an HR staff member, I want to enter times and a reason and have them wait for the CEO, so that I can record a missed punch without changing pay myself.
Independent test: H saves four times with a reason on E1/Dn; a pending request exists and E1/Dn has no day row.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-05.1 | happy | Given Dn with no day row, when H saves 08:00, 12:00, 13:00 and 17:00 with reason "Nakalimutan mag-punch" (`saveAll`), then the reply says it was sent for approval (not a 403), one pending request holds employee, date, the four values, the reason, requester H and when it was asked, and there is no day row, no override row, and payroll is unchanged | | Not run |
| S-05.2 | happy | Given Dr (time_in 08:03), when H changes time_in to 08:00, then a pending request holds old 08:03:00 and new 08:00:00, and the day row (values, `updated_at`), its totals and the override table are unchanged | | Not run |
| S-05.3 | happy | Given Dr, when H edits one field from the modal (`overrideTime`), then a pending request exists and the day row is untouched | | Not run |
| S-05.4 | happy | Given Dn, when H uses the add-time flow of the modal (today: create day, then enter times), then exactly one request holding the times exists, no blank day row exists, no "CEO access required" message shows, and no request without values is listed | | Not run |
| S-05.5 | happy | Given Dn and a shift for E1, when H uses Fill from Shift, then one pending request holds the four times of the shift as they were at the request, and there is no day row | | Not run |
| S-05.6 | happy | Given the same as S-05.1, when admin M saves, then the result is the same, with requester M, and M can never approve | | Not run |
| S-05.7 | negative | Given a reason that is empty or 2 characters, when H saves, then it is refused (422) and nothing is stored | | Not run |
| S-05.8 | negative | Given Dl, when H saves through any entry point (`saveAll`, `overrideTime`, `createDay`, `fillFromShift`), then it is refused (423) and no request exists | | Not run |
| S-05.9 | negative | Given Dr, when H saves the values the day already has, then the reply says "No changes to save." and no request is created | | Not run |
| S-05.10 | negative | Given a time such as `25:61`, an unknown employee id or a value that is not a date, when H saves, then it is refused (422) and nothing is stored | | Not run |
| S-05.11 | edge | Given H sends `saveAll` on Dn with times and `shift_id` together, when it is processed, then nothing official is written: no day row and no `employee_shift_assignments` row (today the shift row is written before the refusal) | | Not run |
| S-05.12 | edge | Given Dr, when H clears time_out (`clear_fields`), then the request records "clear" with old value 17:00:00, and the Approvals page shows "cleared", not a blank | | Not run |
| S-05.13 | edge | Given a reason of exactly 500 characters, then it is accepted; at 501 it is refused. A reason in Filipino and non-ASCII ("Hindi nakapag-punch dahil sira ang makina, Ñoño") is stored and shown intact in the modal and on the Approvals page | | Not run |
| S-05.14 | edge | Given the same save is sent twice (double click), then one request exists | | Not run |
| S-05.15 | edge | Given the server saved but the reply was lost (failed network), when H reloads the calendar, then the day shows pending once, and saving again does not create a second request (rule in S-15) | | Not run |
| S-05.16 | edge | Given the HR modal opened on a phone (390 × 844), when H saves, then the confirmation, the pending marker and the reason field are all visible without horizontal scrolling | | Not run |

### S-06 Overtime, SIL and shift changes also wait (P1)
As an HR staff member, I want every kind of change to a day to wait for approval, so that nothing that moves pay is decided by me alone.
Independent test: H applies SIL for E1 on Dn; a pending request exists and there is no `sil_applications` row.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-06.1 | happy | Given Dr, when H sets approved overtime to 2 hours, then a pending request holds 2 h, and `approved_overtime_minutes`, the totals and the override table are unchanged. "0" (approve no overtime) and "back to automatic" are two different requests, shown as "0 h" and "automatic" | | Not run |
| S-06.2 | happy | Given E1 is SIL-eligible with balance, when H applies SIL for Dn, then a pending request exists, no `sil_applications` row exists, the remaining balance is unchanged, and payroll is unchanged | | Not run |
| S-06.3 | happy | Given SIL applied on a date, when H removes it, then a pending removal exists and the SIL is still applied and counted until approval | | Not run |
| S-06.4 | happy | Given a date with the current shift, when H assigns another shift for that date (`assignShiftForDate`), then a pending request exists, no new `employee_shift_assignments` row exists, and `getShiftForDate` still returns the old shift | | Not run |
| S-06.5 | negative | Given E1 is not SIL-eligible, or the balance is 0, or SIL is already applied on that date, when H applies SIL, then it is refused (422) with the existing message and no request is created | | Not run |
| S-06.6 | edge | Given a balance of 1 and two pending SIL requests for two different dates, when C approves the first, then the second cannot be approved ("No remaining SIL days") and nothing is applied for it | | Not run |
| S-06.7 | edge | Given H saves times, overtime and a shift for E1/Dr in one save, then one request per field is created, the Approvals page shows ONE entry for E1/Dr holding all of them, and Approve or Reject applies to all of them together | | Not run |
| S-06.8 | edge | Given a SIL request on a date where E1 already has a day row with times, then the Approvals page shows both, so the CEO sees what SIL will replace | | Not run |

### S-07 Rest day changes (P1)
As an HR staff member, I want turning an absent day into a rest day to take effect at once, and a rest day change on a day that already has a record to wait for the CEO, so that a mis-scheduled day is fixed fast but a worked day can't be taken out of pay alone.
Independent test: H marks E1/Dn (no day row) as a rest day; `day_offs` and `day_off_logs` have rows and no request exists.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-07.1 | happy | Given Dn with no day row, when H adds a day off with a reason, then a `day_offs` row (type day off) exists, one `day_off_logs` row names H and the reason, there is no request, and the calendar shows the rest day | | Not run |
| S-07.2 | happy | Given a rest day on Dn with no day row, when H cancels it ("must work"), then it is direct, with a log row, and no request | | Not run |
| S-07.3 | happy | Given a day-off override on Dn with no day row, when H removes the override, then it is direct, with a log row, and no request | | Not run |
| S-07.4 | happy | Given Dr (E1 has a day row), when H adds a day off, then a pending request exists, nothing is official yet (no `day_offs` row, no log row, day row and totals unchanged, payroll unchanged), and the cell is marked pending | | Not run |
| S-07.5 | happy | Given a rest day and a day row on the same date, when H cancels the rest day or removes the override, then each is a pending request and nothing changes until approved | | Not run |
| S-07.6 | happy | Given the S-07.4 request, when C approves, then the `day_offs` row and one `day_off_logs` row exist as if C had set them, the log names C as the one who set it, the request and the day's history name H as the one who asked, and payroll equals the payroll after C sets the same rest day directly | | Not run |
| S-07.7 | negative | Given Dl, when H toggles a day off (with or without a day row), then it is refused (423) as today. A missing or 2-character reason is refused (422) | | Not run |
| S-07.8 | edge | Given a pending times request for E1/Dn, when H makes Dn a rest day directly (no day row), then the rest day stands and the request is flagged "now a rest day", cannot be approved and can be rejected (S-17.4) | | Not run |
| S-07.9 | edge | Given a bulk day-off action by H over 3 employees where 2 have no day row on that date and 1 has one, then the 2 are direct (one log row each) and the third becomes a pending request, and the reply says "2 done, 1 sent for approval" | | Not run |
| S-07.10 | edge | Given a pending rest-day request on Dr, when H asks the same again, then one request remains (rule in S-15) | | Not run |
| S-07.11 | edge | Given the CEO sets or cancels a rest day on a date with or without a day row, then it is direct and no request exists | | Not run |

### S-08 The CEO's own edits stay direct (P1)
As the CEO, I want my own edits to apply at once, so that I never have to approve myself.
Independent test: C saves times on E1/Dn; the day row and override rows exist and no request exists.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-08.1 | happy | Given Dn, when C saves four times with a reason, then the day row and one override row per field exist (`updated_by` = C), and no request is created | | Not run |
| S-08.2 | happy | Given C changes a time on Dr, approved overtime, SIL, a shift for a date, a rest day, or runs a bulk action, then each is direct as today and no request is created | | Not run |
| S-08.3 | negative | Given Dl, when C edits through the calendar, then it is refused (423) | | Not run |
| S-08.4 | edge | Given a user who was admin when they asked and is now CEO, when they edit, then the edit is direct (the role is read at each call), and their earlier request follows S-19.5 | | Not run |
| S-08.5 | edge | Given C's direct edit on a date where H has a pending request, then the request follows S-17.2 and S-17.3 | | Not run |

### S-09 The calendar and the old Attendance page show pending (P1)
As an HR staff member or admin, I want to see that a day is waiting, so that I don't enter it twice or think it was lost.
Independent test: after S-05.1, the E1/Dn cell says pending, not absent.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-09.1 | happy | Given S-05.1, when H opens the calendar, then the E1/Dn cell is marked "pending" and its modal shows the requested times, reason, requester and when asked. Totals show nothing worked | | Not run |
| S-09.2 | happy | Given a pending change on Dr, then the cell shows the official values with a "pending" marker, and the modal shows requested next to official. Totals are those of the day row | | Not run |
| S-09.3 | negative | Given the pending day, then calendar summaries (present and absent counts, per-employee and per-day totals) and any calendar print or export count Dn as absent as before, and Dr with its official values | | Not run |
| S-09.4 | negative | Given E1 has a pending request, then E2's cells and E1's other dates are unmarked | | Not run |
| S-09.5 | happy | Given the same pending day, when H opens the old Attendance page (`/attendance`), then the row for E1/Dn or Dr is marked pending and keeps its official values. Its CSV export and print view contain official values only | | Not run |
| S-09.6 | edge | Given the page is reloaded, then the pending state comes from the server and not from the page's script | | Not run |
| S-09.7 | edge | Given a request is approved, withdrawn or rejected, then after a reload the pending marker is gone; approved shows the real times, rejected shows "Rejected: <reason>" (S-13.2) | | Not run |
| S-09.8 | edge | Given a request made at 23:30 Manila time, then it is on the work date asked for (not the `created_at` date), and "asked at" shows Manila time | | Not run |
| S-09.9 | edge | Given a phone width (390 × 844), then the pending marker is visible on the cell and in the modal, and the calendar is still usable | | Not run |
| S-09.10 | edge | Given a pending rest-day request on Dr, then the cell is marked pending, still shows the worked day with its official times, and is still counted as worked | | Not run |

### S-10 The CEO sees what is waiting (P1)
As the CEO, I want an "Approvals" link with a count and a list, so that I can see at a glance what needs me.
Independent test: with 3 entries pending, C sees "Approvals 3" and a list of 3 rows.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-10.1 | happy | Given 3 pending entries (3 employee-and-date pairs, one of them holding 2 fields), when C loads any page, then the nav shows "Approvals" with 3. The list shows, per entry, employee, date, the kind of change, the values put in with the official value next to them, the reason, who asked and when | | Not run |
| S-10.2 | happy | Given C approves or rejects one entry, then on the next load the count is 2 and the row is gone from the pending list | | Not run |
| S-10.3 | negative | Given H, H2 or M, then there is no Approvals link, and GET of the Approvals address returns 403. The nav does not leak the count | | Not run |
| S-10.4 | negative | Given nothing pending, then the page says nothing is waiting and the nav shows no badge | | Not run |
| S-10.5 | edge | Given a feature permission is granted to hr_staff or admin for any nav link, then the Approvals link and address still stay CEO-only | | Not run |
| S-10.6 | edge | Given enough entries to page, then the count is the total pending entries and not the page size. Withdrawn, approved and rejected are not counted. Entries on locked dates or deactivated employees are counted and flagged | | Not run |
| S-10.7 | edge | Given the filters (P2), then the list can be filtered by status (pending, approved, rejected, withdrawn), employee, requester, work-date range and kind of change. The default is pending, oldest first. A "decided" view shows who approved or rejected, when, and the CEO's reason | | Not run |
| S-10.8 | edge | Given an employee name with Ñ or ñ, and a reason with `<script>` or a long unbroken word, then both show as plain text and the layout holds | | Not run |
| S-10.9 | edge | Given a phone width (390 × 844), then every row is readable and the approve and reject controls are reachable | | Not run |
| S-10.10 | edge | Given a CEO who opens the page in a stale tab, then loading again shows current data, and a stale click is handled by S-16 | | Not run |
| S-10.11 | edge | Given an entry holding fields asked by H and H2, then the row shows each field with its own requester | | Not run |

### S-11 The CEO approves a request (P1)
As the CEO, I want approval to make the change real exactly as if I had entered it, so that payroll and reports treat it like any other attendance.
Independent test: approve the S-05.1 request; E1/Dn has the same day row as if C had saved those times.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-11.1 | happy | Given the S-05.1 request, when C approves, then the day row exists with the four times, one override row per field with the requester's reason, and the totals (work, late, early, overtime minutes) equal those of a control employee and date where C entered the same times. The request is approved with approver C and the decision time, and H is still recorded as the requester. The calendar is no longer pending and payroll counts the day | | Not run |
| S-11.2 | happy | Given a time_in request on Dr, when approved, then only time_in changes, the override row has old and new value, and the other fields are untouched | | Not run |
| S-11.3 | happy | Given an overtime, SIL apply, SIL removal or shift request, when approved, then the same official data exists as the direct path creates, and the SIL balance moves by exactly one day | | Not run |
| S-11.4 | happy | Given an approved request, then the day's history (modal and logs) shows who asked and who approved, with the reason | | Not run |
| S-11.5 | negative | Given the date is locked at approval time, then the approval is refused with the lock message, the request stays pending and nothing is written | | Not run |
| S-11.6 | negative | Given the approval fails halfway (the recompute throws), then nothing is half-written: no new day row, no override row, and the request is still pending with an error shown | | Not run |
| S-11.7 | edge | Given an approved clear-time request, then the field becomes empty and the old value is in the log | | Not run |
| S-11.8 | edge | Given an approved day, when an import later brings punches for it, then the approved fields are kept as overrides (the same as a CEO-entered edit; S-02.1) | | Not run |
| S-11.9 | edge | Given a request that is already approved, rejected or withdrawn, when approval is attempted, then it is refused and nothing changes | | Not run |
| S-11.10 | edge | Given an entry with 3 pending fields, when C approves, then all 3 are applied together; none is applied alone | | Not run |
| S-11.11 | edge | Given an entry with a field asked by H and a field asked by H2, when C approves, then each override row and the history name the right requester for that field and C as approver | | Not run |

### S-12 The CEO approves several at once (P2)
As the CEO, I want to approve many entries in one go, so that a pay period's batch doesn't take 40 clicks.
Independent test: select 3 pending entries and approve; all 3 are approved.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-12.1 | happy | Given 3 entries selected, when C approves, then all 3 are applied and approved and the reply says "3 approved" | | Not run |
| S-12.2 | negative | Given 1 of the 3 is on a locked date, then it stays pending with the reason named, the other 2 are applied, and the reply says "2 approved, 1 not approved: date locked" | | Not run |
| S-12.3 | edge | Given one entry was withdrawn after the list loaded, then it is skipped with a message and the rest are applied | | Not run |
| S-12.4 | edge | Given a filter is on, then "approve all" affects only the filtered rows | | Not run |
| S-12.5 | edge | Given 50 selected with one failing (SIL balance or deactivated employee), then each is isolated: one failure does not undo the others, and the page does not time out | | Not run |
| S-12.6 | edge | Given reject selected, then one reason is required and is stored on each rejected entry | | Not run |
| S-12.7 | edge | Given a batch made by a bulk action (S-22), when C approves the batch in one action, then each of its requests is applied or, if blocked (lock, rest day, SIL, deactivated), stays pending with its reason named, and the others are applied | | Not run |

### S-13 The CEO rejects with a reason (P1)
As the CEO, I want to reject with a reason the requester can read, so that they know what to fix.
Independent test: reject a request with "Walang katibayan"; the day is as before and H sees the reason.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-13.1 | happy | Given a pending entry, when C rejects with a reason, then the status is rejected with the reason, rejecter C and time, and the day (day row, values, `updated_at`, overrides, rest day, SIL, shift) and payroll are unchanged | | Not run |
| S-13.2 | happy | Given S-13.1, when H opens the day in the calendar, then the modal shows "Rejected: Walang katibayan" with the values H asked for. Nothing is sent by email or chat | | Not run |
| S-13.3 | negative | Given the reason is empty or fewer than 3 characters, then the rejection is refused and the request stays pending | | Not run |
| S-13.4 | negative | Given a request already decided or withdrawn, then reject is refused with no change | | Not run |
| S-13.5 | edge | Given an entry on a locked date, then C can still reject it | | Not run |
| S-13.6 | edge | Given a rejected request, then H can ask again on the same employee, date and field. The rejected one stays in history, the new one replaces the "Rejected" notice in the modal, and the count has only the new one | | Not run |
| S-13.7 | edge | Given a 501-character reason, then it is refused. Given a Filipino and non-ASCII reason, then it is shown intact to H | | Not run |
| S-13.8 | edge | Given C rejects an entry with 2 fields, then both fields are rejected with the same reason | | Not run |
| S-13.9 | edge | Given the requester's rejected items badge (P2), when H has 2 rejected entries not yet replaced, then the calendar page shows a small list of them with date, employee and reason, and an item leaves the list once a new request replaces it | | Not run |

### S-14 The requester changes or withdraws a pending request (P1)
As the person who asked, I want to correct or take back my request while it waits, so that a typo doesn't need the CEO to reject it.
Independent test: H withdraws; the request leaves the list and the count.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-14.1 | happy | Given H's pending request, when H changes the times and reason, then the same request holds the new values (same id), "changed at" is set, and the CEO's list shows the new values | | Not run |
| S-14.2 | happy | Given H's pending request, when H withdraws, then the status is withdrawn, the request is out of the list and the count, and the cell returns to absent or to Dr's official values. No day row exists | | Not run |
| S-14.3 | negative | Given H's request, when H2 or M tries to change or withdraw it (including by posting the id directly), then 403 and no change. In an entry holding fields of H and H2, each can change or withdraw only their own fields | | Not run |
| S-14.4 | negative | Given the request is approved or rejected, when H changes or withdraws it, then it is refused ("already decided") | | Not run |
| S-14.5 | negative | Given the date got locked, when H changes the request, then it is refused (423); when H withdraws it, then it is withdrawn | | Not run |
| S-14.6 | negative | Given an edit with the reason missing or a bad time, then 422 and the request is unchanged | | Not run |
| S-14.7 | edge | Given an edit whose values equal the official ones, then it is refused as "No changes" and the request is kept as it was | | Not run |
| S-14.8 | edge | Given withdraw sent twice, then no error 500, and the final state is withdrawn | | Not run |
| S-14.9 | edge | Given a withdrawn request, then H can ask again later as a new request | | Not run |
| S-14.10 | edge | Given C wants a pending request gone, then C cannot withdraw it (only reject it) | | Not run |

### S-15 A second request for the same employee, date and field (P1)
As the CEO, I want only one pending request per employee, date and field, so that I never approve two conflicting values.
Independent test: H asks twice for E1/Dn time_in; one pending request remains.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-15.1 | happy | Given H has a pending time_in for E1/Dn, when H asks again with a new value, then that request is updated (not duplicated), one remains pending, and the count is 1 | | Not run |
| S-15.2 | negative | Given H has a pending time_in for E1/Dn, when H2 or M asks for the same employee, date and field, then it is refused with a message naming H as the one who asked, and the one request remains unchanged | | Not run |
| S-15.3 | edge | Given a pending time_in for E1/Dn, when H2 asks for time_out, SIL, overtime or a rest day on the same day, then it is accepted as a request of its own and the Approvals page shows ONE entry for E1/Dn holding both fields, each with its requester | | Not run |
| S-15.4 | edge | Given the same field on another date, or another employee, then it is independent and a separate entry | | Not run |
| S-15.5 | edge | Given the first one was approved, rejected or withdrawn, then a new request for the same field is allowed and is compared with the new official value | | Not run |
| S-15.6 | edge | Given two identical requests arrive at the same instant, then one row exists (a database-level guard) and the other gets the "already pending" reply | | Not run |

### S-16 Two people acting at once (P1)
As the CEO, I want a double click or two tabs never to apply a change twice or halfway, so that the records stay right.
Independent test: approve the same entry twice at the same instant; the data is applied once.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-16.1 | negative | Given two approvals of the same entry at once (double click, two tabs), then one set of rows exists (one day row, one override per field, one SIL application), and the second gets "already approved", not an error 500 | | Not run |
| S-16.2 | edge | Given approve and reject at once, then exactly one wins, and the final data matches the status: approved means data written; rejected means none | | Not run |
| S-16.3 | edge | Given C opened values V1 and H changes the request to V2 before C approves, then the approval is refused with "request changed, review again". V2 is never applied unseen | | Not run |
| S-16.4 | edge | Given H withdraws while C approves, then one wins, and no data is written if withdrawn won | | Not run |
| S-16.5 | edge | Given C is approving an entry holding time_in and H2 adds time_out to it at the same moment, then the approval is refused with "request changed, review again", nothing is written, and the entry then holds both fields | | Not run |
| S-16.6 | edge | Given an approval and C's direct creation of the same E1/Dn day row at once, then one day row only exists, both actions are logged, and no duplicate-key error is shown | | Not run |

### S-17 The day changes between request and approval (P1)
As the CEO, I want a stale request to be flagged or blocked, so that I never approve something that no longer fits the day.
Independent test: a request on E1/Dn; the date becomes a rest day; Approve is blocked and Reject works.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-17.1 | happy | Given a pending four-times request on Dn, when an import brings punches for Dn (a day row appears with times), then the request stays pending and the entry shows "was X, now Y" for each requested field that now differs. Approve needs the CEO's confirmation, and then the requested fields are applied to the existing day row (still one day row) | | Not run |
| S-17.2 | edge | Given C changed the same field directly after the request, then the entry shows "was X, now Y" (X is the value the request saw), Approve needs the CEO's confirmation, and the log then shows C's edit first, then the approved change | | Not run |
| S-17.3 | edge | Given C changed a different field than the request holds, or the requested field's official value is still what the request saw, then no confirmation is asked and approval applies the request's own fields only | | Not run |
| S-17.4 | negative | Given the date became a rest day, then Approve is blocked ("now a rest day"), Reject works, and nothing is written. If the rest day is undone, Approve works again | | Not run |
| S-17.5 | negative | Given SIL was applied for that date, then Approve of a times request is blocked, Reject works, and nothing is written. If the SIL is removed, Approve works again | | Not run |
| S-17.6 | negative | Given E1 was deactivated, then the request stays visible with a flag, Approve is blocked, Reject works, and there is no payroll effect. If E1 is reactivated, Approve works again | | Not run |
| S-17.7 | edge | Given E1's shift for the date changed meanwhile, then approval writes the requested times and recomputes with the current shift | | Not run |
| S-17.8 | edge | Given a CEO force-recompute of the range, then pending requests are neither applied nor deleted | | Not run |
| S-17.9 | edge | Given a SIL request and E1's balance reached 0 or the date became SIL, then Approve is refused with the reason and nothing is written | | Not run |
| S-17.10 | edge | Given a pending rest-day request on Dr and the day row is then removed or changed, then approval still applies the rest-day change as the request saw it, and is refused only by the blocks above | | Not run |

### S-18 Locked dates (P1)
As the CEO, I want a locked date to take no request and no approval, so that closed pay periods stay closed.
Independent test: a locked date takes no request, and a request pending when the date gets locked cannot be approved.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-18.1 | negative | Given Dl, when H or M uses any entry point (`saveAll`, `overrideTime`, `createDay`, `fillFromShift`, `assignShiftForDate`, `applySil`, `removeSil`, `toggleDayOff`, bulk, and the old Attendance page; S-20), then 423 and no request, no day row, no log | | Not run |
| S-18.2 | negative | Given a request pending and the date is then locked (single lock or range lock), when C approves, then it is refused with the lock message, nothing is written, and the request stays pending and is flagged "date locked" in the list and on the cell | | Not run |
| S-18.3 | negative | Given the same, then Reject still works, H can withdraw it, and H cannot change it (423) | | Not run |
| S-18.4 | edge | Given the date is unlocked again, then the request is approvable again | | Not run |
| S-18.5 | negative | Given a payroll run is finalized while the request is pending (finalizing auto-locks its dates), then it behaves as S-18.2, the finalized payroll has the day as it was, and the request is still counted | | Not run |
| S-18.6 | edge | Given a pending request inside the period of the run being finalized, when C opens the finalize screen, then it shows a warning with the count of pending requests in the period, and finalizing is not blocked | | Not run |
| S-18.7 | edge | Given a lock on another date or another period, then it does not block this one. The lock check uses the work date, not today | | Not run |

### S-19 Only the CEO approves, on the server (P1)
As the owner, I want approval to be impossible for anyone but the CEO, even by calling the address directly, so that the control cannot be bypassed.
Independent test: H posts an approval for a real request id; it is refused and nothing changes.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-19.1 | negative | Given a real pending id, when H posts to approve, reject or approve-several, then 403, the request stays pending and nothing is written | | Not run |
| S-19.2 | negative | Given the same with admin M, then 403 with no change | | Not run |
| S-19.3 | negative | Given a signed-out caller, then redirect to sign-in (or 401) with no change | | Not run |
| S-19.4 | negative | Given H posts a save with extra fields such as `status=approved`, `approved_by` or `requested_by`, then they are ignored: the request is pending and its requester is the signed-in user | | Not run |
| S-19.5 | negative | Given the user who made a request (or one field of an entry) is now a CEO (role changed), when they approve an entry containing it, then it is refused ("cannot approve your own request"), and another CEO can approve it | | Not run |
| S-19.6 | negative | Given H posts approve with an id that does not exist, then 404 and no error 500 | | Not run |
| S-19.7 | edge | Given a user deactivated or demoted after their page loaded, when they click, then the role is checked at that call and the action is refused | | Not run |
| S-19.8 | edge | Given a request without the CSRF token, then 419 and no change | | Not run |

### S-20 The old Attendance page follows the same rule (P1)
As the owner, I want every way to change a day to follow one rule, so that no old screen is a back door.
Independent test: H posts `/attendance/override` on Dr; a pending request exists and the day row is unchanged.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-20.1 | happy | Given Dr, when H or M posts a time field to `/attendance/override`, then a pending request is created, and the day row, its totals and the override table are unchanged (today it saves directly with no role check) | | Not run |
| S-20.2 | happy | Given H posts `shift_id` there, then it is a pending request and the day's shift is unchanged | | Not run |
| S-20.3 | happy | Given C posts there, then it is saved and recomputed as today, with an override row | | Not run |
| S-20.4 | negative | Given Dl, when anyone including C posts `/attendance/override` or `/attendance/create-day`, then 423 and nothing is written (today neither route checks the lock) | | Not run |
| S-20.5 | negative | Given H posts the old `createDay`, then no day row and no blank request is created | | Not run |
| S-20.6 | edge | Given a clear (empty `new_value`) by H there, then the request is "clear", the same as S-05.12 | | Not run |
| S-20.7 | edge | Given the same change by H through the calendar and through the old page, then the requests are the same in kind and fields, so the Approvals page shows no difference | | Not run |
| S-20.8 | edge | Given H runs "Compute" on a range with pending requests, then they are neither applied nor deleted, and existing overrides are still kept | | Not run |

### S-21 Nothing pending reaches payroll or totals (P1)
As the owner, I want unapproved changes to have no effect on pay, so that a request can never pay or dock anyone.
Independent test: with a pending request on E1/Dn, E1's payroll equals the baseline to the centavo.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-21.1 | happy | Given a pending times request on Dn, when payroll is computed for the period, then E1's days, gross and deductions equal the baseline, and Dn counts as absent as before | | Not run |
| S-21.2 | happy | Given a pending change on Dr, then payroll uses Dr's official times | | Not run |
| S-21.3 | happy | Given a pending overtime, SIL, shift or rest-day request, then OT pay, SIL pay, late and OT minutes and the worked days equal the baseline, and the SIL balance is unchanged | | Not run |
| S-21.4 | negative | Given the request is rejected or withdrawn, then payroll equals the baseline | | Not run |
| S-21.5 | happy | Given the request is approved, then payroll equals what it would be if C had entered the same values directly (same amounts to the centavo) | | Not run |
| S-21.6 | negative | Given any number of pending requests, then E1's `attendance_days` row count for the period is unchanged, and so are the dashboard and report counts and every CSV and PDF export | | Not run |
| S-21.7 | edge | Given an approved overtime of 0.333 and of 1.25 hours, then the minutes are 20 and 75 (the same rounding as the direct path), and payroll matches the direct path | | Not run |
| S-21.8 | edge | Given a draft payroll run exists, when a request is approved afterwards, then the run changes only when it is recalculated, as after a CEO edit today | | Not run |

### S-22 Bulk actions by HR or admin (P1)
As an HR staff member, I want a bulk action to send requests rather than change the days, so that many employees can be fixed without bypassing the CEO.
Independent test: H runs bulk set times on 3 employees on Dn; 3 pending requests exist and no day rows.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-22.1 | happy | Given 3 employees on Dn, when H runs "set times" with a reason, then 3 pending requests exist (one per employee and date), grouped as one batch, the reply says "3 requests sent for approval", and there are no day rows | | Not run |
| S-22.2 | happy | Given H runs "fill from shift", then it is the same, with the shift times as at the request. An employee with no shift is listed as a failure as today | | Not run |
| S-22.3 | happy | Given H runs a bulk add day off, cancel day off or remove override, then each employee-day follows the rest day rule: direct when that employee has no day row on the date, a pending request in the batch when there is one (S-07.9) | | Not run |
| S-22.4 | negative | Given Dl, then the whole action is refused (423) and no request is created | | Not run |
| S-22.5 | negative | Given H runs a bulk set times on days that already have day rows, then no day row changes (today `bulkApplySetTimes` writes directly on existing rows) and requests are created | | Not run |
| S-22.6 | edge | Given one employee already has a pending request for that field from the same person, then it is updated (S-15.1); if it is from another person, that employee is reported as a failure naming who asked; the others get requests. The reply lists both counts | | Not run |
| S-22.7 | edge | Given C runs the bulk action, then it is direct as today and no requests exist | | Not run |
| S-22.8 | edge | Given an empty employee list, or `set_times` with no times, then nothing is created (422 or no change) | | Not run |
| S-22.9 | edge | Given 100 employees, then it completes without timing out, and a failure on one does not drop the rest | | Not run |
| S-22.10 | edge | Given a batch from H, then C can approve the whole batch with one action or each request separately (S-12.7) | | Not run |
| S-22.11 | edge | Given a batch from H, when C rejects the whole batch with one reason, then every request in it is rejected with that reason and no day changes | | Not run |

Owner checks to add:
- [ ] S-11.1 CEO approves a request on a real phone and sees the day become real attendance (staging)
- [ ] S-10.1 The Approvals link and the count look right in the nav on the CEO's account (staging)
- [ ] S-13.2 The requester sees the CEO's rejection reason on the calendar, on HR's account (staging)

**Tests:** one failing test per case first, named with its case ID (`test_s_05_1_...`, as Slice 001 names them), at the seam the case describes: an HTTP request as a signed-in user of the stated role against the route, then a read of the tables and, where the case says so, of what `PayrollService` computes. Expected values come from the case, never recomputed the way the code does it. Build a base test class for this slice (users per role, employees, a shift, locks, punches) beside `ImportTestCase`. Cases that name a phone width or what a page shows are checked on the rendered HTML (the marker, the text, the control present); say in the result which of them you could only check by reading the HTML. If the code shows a gap no case covers, put a numbered question in the result file before building it.

## Constraints

**Decisions already made** (settled with the owner or by the reviewer; don't reopen them unless something is actually broken):

| Topic | Decision |
|---|---|
| Who asks, who approves | `hr_staff` and `admin` are treated alike: every change they make to a day becomes a pending request. Only `ceo` approves or rejects. The CEO's own edits are direct, as today. The role is read on the server at every call, never taken from the page or from the request body |
| What goes through approval | Times on a day with or without a row (`saveAll`, `overrideTime`, `createDay`, `fillFromShift`), approved overtime, applying and removing SIL, assigning a shift for a date, the bulk actions, and the same changes made through the older Attendance page |
| The rest day exception | Turning a day that has NO day row into a rest day is direct, and cancelling a rest day on a day with no day row is direct, both logged as today. A rest-day change on a day that HAS a day row goes through approval |
| Where a pending value lives | Only in a new store of its own. Nothing is written to `attendance_days`, `attendance_overrides`, `sil_applications`, `employee_shift_assignments` or `day_offs` for a request until it is approved: no blank row, no placeholder |
| What approval does | It applies the change through the SAME code path a CEO's direct edit uses (extract it; the direct path and the approval both call it), so the rows, the per-field change log, the recompute and the rounding are identical. The change log and the day's history name the requester and the approver, with the requester's reason |
| Atomic | An approval is one database transaction that locks the request row, re-checks the request is still pending, the role, the date lock and the state of the day, applies, and marks it approved. Any failure leaves nothing written and the request pending. A second approval of the same request gets "already approved", never a second write |
| One at a time | One pending request per employee, date and field, enforced by the database and not only by a check before the insert. The same requester asking again updates it; another requester is refused with a message naming who asked. The Approvals page shows one entry per employee and date with all its pending fields, approved or rejected as a whole |
| What the CEO sees | For each entry: employee, date, kind of change, the values asked for beside the official values now, the reason, who asked and when. If an official value changed since the request was made, "was X, now Y" is shown and approval needs a confirm. If the day became a rest day, SIL was applied or the employee was deactivated, Approve is blocked and Reject still works |
| Stale view | An approval carries what the CEO saw (a version or the time of the last change of the request). If the request was changed after that, the approval is refused with "request changed, review again" |
| Locks | A locked date takes no request. A pending request whose date gets locked (a lock, a range lock or a finalized payroll run) stays listed and flagged "date locked", cannot be approved while locked, can be rejected by the CEO and withdrawn (not changed) by the requester, and can be approved again after an unlock. Finalizing a payroll run with pending requests in its period warns with the count and does not block |
| Reject | Needs a reason of 3 to 500 characters. Nothing official changes. The requester sees "Rejected: <reason>" in the day's modal until a new request replaces it. No email and no chat message |
| Change and withdraw | Only the requester may change or withdraw a pending request, never another HR staff member or an admin, also not by posting its id directly |
| Bulk | A bulk action by HR staff or an admin creates one request per employee and date, grouped as one batch that the CEO can approve or reject in one action or one by one. One failing employee never undoes the others |
| The nav | A link "Approvals" with the count of pending entries, for the CEO only, hard-coded on the role like the other CEO-only links (not a `feature_permissions` key). Its routes answer 403 to every other role |
| The older Attendance page | Follows the same rule on every path: request for HR staff and admins, direct for the CEO, never on a locked date. Its missing role and lock checks are closed by this spec |
| Text | English, as the app is. Short: "Sent for approval", "Pending approval", "Approved", "Rejected: <reason>", "Date locked" |
| Schema | Additive only: new table or tables. No change to the columns of existing tables unless the plan shows it is unavoidable and the reviewer says "go" |
| Payroll and compute | `PayrollService` and `AttendanceComputeService` are not changed in what they compute. If a change there seems needed, it is a question first |

**Test setup.** As Spec 001 left it: `composer install` in this worktree if `vendor/` is missing (the one download this spec allows), sqlite in memory through `phpunit.xml`, a test-only application key. Production data and the production schema are never part of a test.

**Threat model.** Untrusted: everything a signed-in `hr_staff` or `admin` user sends (every field, id, date, time, reason, and any extra field such as a status or an approver id), the ids in the address of an approve, reject, change or withdraw call, and what the page's script claims about the user's role or about what the CEO saw. Trusted: the CEO's own actions, rows written by the import, files in this repo, config. What must not happen: (1) anyone but the CEO making a change official, including by calling an address directly, replaying a request, or sending extra fields; (2) a request being approved by its own requester; (3) a pending or rejected value reaching `attendance_days`, a total, an export or payroll; (4) one approval applied twice or half; (5) a change on a locked date; (6) one user changing or withdrawing another user's request; (7) a reason or a name rendered as HTML. A major needs a one-line realistic scenario. Fix loops stop after two; a remaining security or payroll-correctness major goes to the reviewer as a question, other findings are accepted with a reason under "Deferred minors".

**Rules**
- Never `git push`. Never ssh. No remote database. No real `.env` or key read, created or printed.
- Don't start other Claude Code sessions; use subagents inside this session, in the foreground.
- Plan first (`docs/plans/002-attendance-approvals.md`), then build after the reviewer's "go". A run may be ended from outside after two hours: commit as you go, and at about 90 minutes of work write where you are into the result file, commit, and end with a status line; the reviewer resumes you.
- No `Co-Authored-By`, `Claude-Session` or "Generated with Claude Code" lines in commit messages.
- Code comments explain the reason itself. Comments, commit messages and branch names never name people or decision ids; the spec number may appear in branch names and commits.
- No commands that start with an environment variable (`FOO=1 php ...`): put the setting in `phpunit.xml`.
- Downloads: `composer install` in this worktree only. No npm install (Blade only, no asset build).
- Build for the least skilled user: HR staff do what they do today and read "Sent for approval"; the CEO sees a count, a list and two buttons. Nothing new to learn, nothing to configure.
- Employee names, pay and punch times of real people never appear in tests, fixtures, logs or the result; use invented employees.
- Keep your context small: long command output goes to a file outside the repository and you read its last lines.

## Budget

- Attempts: at most 2 tries at the same step; then stop and report what you tried and what you need.
- Size: large job, in stages you propose in the plan (suggested: A the store, the shared write path and requests for times; B the Approvals page with approve, reject, change and withdraw; C overtime, SIL, shift and rest day on a day with a row; D bulk, the older Attendance page, locks and the payroll-finalize warning). Each stage is committed when its cases pass.

## Done when

- [ ] Every case of Slice 002 passes, each with a test named after it, and `qa/stories.md` has the slice with "Test" and "Last run" filled.
- [ ] The result has a red line (test name, first failure line) for S-05.1, S-19.1, S-20.1 and S-21.1 on the base code, watched before the change.
- [ ] The whole suite runs with `php.bat vendor/phpunit/phpunit/phpunit` and adds no new failures compared with the base; every Slice 001 test still passes.
- [ ] At least ten mutations of the finished code, each turning a named test red (for example: the role check of approve removed; a request written as a blank day row; the lock re-check inside the approval removed; the own-request rule removed; the unique rule dropped; the row lock dropped; the stale-view check removed; the older page's rule removed; a rejected request applied; the direct path and the approval writing through different code).
- [ ] `git diff main --stat` lists only: the two attendance controllers and what you extract from them, the new model(s), controller(s), views and migration(s), `routes/web.php`, the layout's nav, the calendar and Attendance views, the payroll finalize view or controller for the warning, tests, `qa/stories.md`, `docs/`, and anything the reviewer approved in the plan.
- [ ] `git diff main -- app/Services/PayrollService.php app/Services/AttendanceComputeService.php` is empty, or shows only what the reviewer approved.
- [ ] Nothing was pushed: `git branch -r` shows no branch for this work.
- [ ] The result file is filled in, including "Merge danger" and what production needs after the code lands (the migration, the queue worker restart if job code changed, what happens to the edits HR staff make the minute the code is live, and how to switch back).

## Out of scope

- Email, chat or push messages about a request or a decision.
- Approval by anyone but the CEO; delegation; several levels of approval.
- Any change to how minutes, lateness, overtime or pay are calculated.
- Changing edits that are already official; a back-fill of past edits into requests.
- The Compute buttons and the `attendance:compute` command (they neither apply nor delete pending requests: S-17.8 and S-20.8 pin that).
- The deploy itself, the server, the queue worker's configuration.

## Report back

Fill in `docs/specs/002-attendance-approvals.result.md` in the form of `001-import-computes-days.result.md` and commit it with the work, including every ruling you made (`Ruling: <decision> — <why> — <cost if wrong>`; an unrecorded deviation is a secret decision), "Story changes" if a case had to change because the code proved it wrong, and the deferred minors. There is no pull request for this spec: the reviewer reads the branch, has it tested, merges it locally and deploys on the owner's word. In "Branch / PR" give the branch and its head commit. "Merge danger" says: one-way or two-way door, blast radius, how to revert.
