# Result: spec 007 one approval per shift change

**Status:** done
**Date:** 2026-10-30
**Branch / PR:** `feat/007-approval-batches`, from main 9773f8f. Not pushed; no PR.
**Preview or run link:** n/a. Nothing is deployed. The rendered pages are in `docs/specs/007-evidence/`.

This includes amendment 007-1 (story .10: a profile shift assignment by someone who must ask becomes one pending approval).

## Summary
- **Shift change cards.** `App\Services\ApprovalCards` turns the pending rows into the cards of the Approvals page. The page, its paging, the sidebar badge, the JSON counts and the server's check of a posted group all use it.
  - A day whose only pending request is a shift change joins the group of its change. The group key is the employee, the shift asked, the requester, and the batch id (or, when there is none, the minute it was asked).
  - A group of two days or more is one card (`_group.blade.php`). It shows the dates as ranges, worked/late/undertime totals now vs if approved, the days that cannot be approved, and one Approve and one Reject. Under "See each day" are each day's punches, preview and its own Approve/Reject.
- **Group approve.** The card posts to `/approvals/shift-group/{approve,reject}`. The server re-checks each posted request: it is pending, it is a shift request, it has the posted key, and it is alone on its day. Then it decides each day through the existing `approve()`/`reject()`.
- **Requests that change nothing close themselves.** A request whose value is already official closes as `no_longer_needed` (`closeUnneeded`, `closeRows`). This happens when the page loads and inside `approve()`, never on a locked date. A closed shift request whose day was computed with another shift is recomputed the way its approval would have done it.
- **Profile shift assignments (amendment).** An add, edit or removal of a profile shift assignment by anyone but the CEO is now stored as one request with its range (new `details` column).
  - Approving it runs the CEO's own profile logic, moved into `AttendanceChangeService` and shared by both paths. Then it recomputes the computed days of the range, except locked ones.
  - Its card previews those days in one dry run.

## Case table
Output: `docs/specs/007-evidence/spec-007-tests.txt` (29 tests, 412 assertions, OK).

| Case | Test | Result |
|---|---|---|
| .1 grouping keys (requester, minute, value, mixed day) | `ShiftGroupsTest::test_s_007_1_grouping_keys` | pass ✔ "S-007.1 shift requests of one change are one card; …" |
| .1 batch id | `ShiftGroupsTest::test_s_007_1_batch_id_groups` | pass ✔ |
| .2 approve-all = one by one, incl. change log and payroll | `ShiftGroupsTest::test_s_007_2_approve_all_equals_one_by_one` | pass ✔ |
| .2 reject-all | `ShiftGroupsTest::test_s_007_2_reject_all` | pass ✔ |
| .2 per day inside the card | `ShiftGroupsTest::test_s_007_2_per_day_inside_the_card` | pass ✔ |
| .3 locked day inside a group | `ShiftGroupsTest::test_s_007_3_locked_day_inside_a_group` | pass ✔ |
| .4 select a card, badge counts cards | `test_s_007_4_select_all_and_badge`, `test_s_007_4_json_count_is_cards` | pass ✔ ✔ |
| .5 other kinds stay per day | `test_s_007_5_other_kinds_are_not_grouped` | pass ✔ |
| untrusted group posts | `test_s_007_group_post_is_checked_on_the_server`, `test_s_007_group_day_must_be_alone`, `test_s_007_long_change_is_cut_into_cards` | pass ✔ ✔ ✔ |
| .6 auto-close equal to official (profile, same edit, locked date, still different) | `AutoCloseTest::test_s_007_6_closes_on_page_load`, `test_s_007_6_same_edit_and_locked_date`, `test_s_007_6_different_value_stays` | pass ✔ ✔ ✔ |
| .7 partial group | `AutoCloseTest::test_s_007_7_partial_group` | pass ✔ (`done 2, closed 1`) |
| .8 recompute of closed days with the new shift; locked day not | `AutoCloseTest::test_s_007_8_closed_days_are_recomputed`, `test_s_007_8_locked_day_is_not_recomputed` | pass ✔ ✔ |
| .9 phone 390 px | rendered HTML only (see Done-when) | not checked in a real browser |
| .10 one pending card, nothing in effect, profile shows pending, reason required | `AssignmentRequestTest::test_s_007_10_request_is_one_card`, `test_s_007_10_reason_required` | pass ✔ ✔ |
| .10 approve = direct path + recompute, locked day not recomputed | `test_s_007_10_approve_is_the_direct_path_plus_recompute`, `test_s_007_10_previous_open_assignment_closed` | pass ✔ ✔ |
| .10 reject = no change; CEO direct | `test_s_007_10_reject`, `test_s_007_10_ceo_direct` | pass ✔ ✔ |
| .10 delete/edit by non-CEO → request | `test_s_007_10_edit_and_delete_are_requests` | pass ✔ |
| .10 overlap, changed since, range past the end, refused when asked | `test_s_007_10_overlap_at_approval`, `test_s_007_10_changed_since_needs_confirm`, `test_s_007_10_range_reaches_past_the_end`, `test_s_007_10_refused_when_asked` | pass ✔ ✔ ✔ ✔ |
| existing approval tests | full suite | pass: `OK (319 tests, 2928 assertions)`, baseline `OK (290 tests, 2514 assertions)` |

## Story changes
- Added Slice 007 to `qa/stories.md`: cases S-007.1–S-007.11 with the .10 cases of the amendment, plus two owner checks (S-007.9 on a real phone, S-007.10 on staging).
- **S-17.1 changed** (`ApprovalFlowTest::test_s_17_1_import_after_the_request`).
  - Before: the punches imported after a four-times request left all four pending.
  - Now: the imported lunch out and lunch in equal the asked ones, so those two close as "no longer needed" when the page loads. Two stay pending.
  - Reason: story .6.
- **S-17.9 changed** (`RequestOtherKindsTest::test_s_17_9_sil_request_after_the_balance_changed`).
  - Before: a SIL request whose date the CEO already made SIL was refused with "already applied" (422).
  - Now: it closes as "no longer needed" (200) and writes nothing. The "no balance" refusal is unchanged.
  - Reason: story .6.

## Done-when checklist
- [x] **P1 cases pass with tests.** 29 new tests are green (`docs/specs/007-evidence/spec-007-tests.txt`). Run with `php vendor/bin/phpunit tests/Feature/Approvals/ShiftGroupsTest.php tests/Feature/Approvals/AutoCloseTest.php tests/Feature/Approvals/AssignmentRequestTest.php --testdox`.
  - Seen failing first: 20 of 24 failed before any code (commit c68c499). The 4 that passed are guards on behaviour that already existed.
- [x] **The full suite adds no new failures vs 9773f8f.**
  - Baseline: `OK (290 tests, 2514 assertions)` (`docs/specs/007-evidence/baseline-9773f8f.txt`).
  - Now: `OK (319 tests, 2928 assertions)` (`docs/specs/007-evidence/full-suite.txt`).
  - Two existing assertions changed; see Story changes.
- [x] **Evidence page.** The files are in `docs/specs/007-evidence/`; the app rendered them in a test with invented names. The data:
  - Ana Reyes asked the late shift for 6 dates in the minute Oct 26 4:44 PM, and Oct 13 was locked afterwards.
  - Ben Santos has a profile assignment asked by HR.
  - Carla Cruz's two shift requests became official through a CEO profile assignment.
  - Files:
    - `approvals-page.html`: the whole page.
    - `shift-group-card.html`: the card reads "Ana Reyes · Shift → Late shift · Oct 05–Oct 13 (6 days) · Dates: Oct 05–06, Oct 08–09, Oct 12–13 · Official now: Day shift · Asked by Hr One on Oct 26, 2026 4:44 PM · Oct 13 cannot be approved: date locked". Totals over the 5 days that can be approved: Worked 38 h 36 m → 34 h 6 m, Late 24 m → 0 m, Undertime 1 h → 5 h 54 m. Buttons: "Approve 5 days" and "Reject all", then "See each day".
    - `assignment-card.html`: "Ben Santos · Shift assignment → Late shift · from Oct 01, 2026 · ongoing · Oct 13 is locked: its day is not recomputed", 5-day totals, "See each day (5 with attendance)".
    - `no-longer-needed-tab.html`: Carla's two requests, "Asked by Hr One on Oct 26, 2026 4:50 PM · Closed by itself on …: The official shift is already Late shift. Nothing was written."
    - `profile-pending.html`: the profile row "Pending approval · Not in effect until the CEO approves".
    - `group-approve-reply.json`: one Approve on Ana's card, `"5 approved, 1 not approved: date locked"`, with the rows after it.
- [x] **Result with rulings.** How the 4:44 PM requests were made, where the auto-close check runs, and what is recomputed are under Rulings.
- [x] **Deploy notes.** See below.
- [x] **The final line.**
- [ ] **.9 (P2) phone 390 px.** Checked in the HTML only.
  - The card header, badges and actions are `d-flex flex-wrap`. The totals use the existing fluid `approval-preview-grid` (fractions, no fixed width). The only widths set are `max-width: 16rem` on the reason inputs.
  - The built-in browser cannot open a local file, and the page needs the CDN styles, so there is no real 390 px screenshot. It is an owner check in `qa/stories.md`.

## How to run
From a fresh clone:
1. `composer install`
2. `cp .env.example .env`
3. `php artisan key:generate`
4. `php vendor/bin/phpunit` (sqlite in memory, from phpunit.xml)

## Rulings
- **How the 4:44 PM requests were made.** They are many single-date calls without a batch id.
  - Why: no code path makes a shift request with a `batch_id`. The calendar bulk action (`AttendanceCalendarController::bulkAction`) only asks for rest days and times. Shift requests come from `assignShiftForDate` (origin `assign_shift`), `saveAll` (`save_all`) and the old Attendance page (`attendance_override`), one date per call.
  - Cost if wrong: none for grouping, since a batch id groups too. It can be confirmed on the live rows (`origin`, `batch_id`, `created_at` of those 25 requests) by a read-only check.
- **Same minute means the same calendar minute in the app time zone** (the minute the card shows, "4:44 PM"). Why: the spec says "the same minute", and one card shows one time. Cost if wrong: a change typed across a minute boundary becomes two cards, each still correct.
- **Only a day whose only pending request is a shift change joins a group.** A day with a shift and a time (Save All) stays a per-day card, because its Approve applies every field of the day together. A group of one day is today's per-day card.
- **A change of more than 50 days is cut into cards of at most 50 days** with the same key. Why: one page or one post never carries more than 50 previews or days (the page budget, and the 200 limit of the posts). Cost: a very long change needs two Approves.
- **Group approve decides day by day.** It runs one transaction per day, through the unchanged `approve()`/`reject()`, so a locked or blocked day stays pending while the others go through (story .3). The whole post is refused (422) when any posted request is not part of the one change its key names.
- **Card totals count only the days one Approve saves.** Locked and blocked days are left out, and the totals header says how many days are counted.
- **Pending pages hold cards up to 50 days of previews,** with a Previous/Next pager. Laravel's paginator counts rows, not days. The other tabs keep their paging and stay one entry per employee and date (not grouped).
- **Where the auto-close runs:** at the start of every Approvals page load (all pending requests), and inside `approve()` for the entry being approved (story .7).
  - Why not a schedule: `Console\Kernel::schedule` is empty, and whether a cron runs `schedule:run` is not known.
  - Why not after the actions that change official values: there are many of them (calendar, profile, imports, CEO edits).
  - The page is the one place the CEO decides from, so closing there removes the request before it is counted, shown or offered.
  - The sidebar badge on other pages can therefore count a request that will close on the next Approvals load.
  - Reject does not auto-close: the CEO chose to reject, and neither way writes anything.
- **Kinds that close themselves:** times, approved overtime, shift, and SIL ("apply" when already applied, "remove" when none is applied). Rest-day requests do not, because they are actions on a pattern, not values. Never on a locked date.
- **A closed request keeps its record.** It has status `no_longer_needed`, no decider, `decided_at` set, the requester and reason kept, and the reason "The official … is already …. Nothing was written."
- **What is recomputed for a closed shift request.** Only when the date has a day row whose `shift_id` is not the shift asked for, and the date is not locked. The day gets:
  - The same compute the approval of that request would run after its write, minus the write itself. For origin `assign_shift` (the calendar's per-date shift), the day is rebuilt from its punches with overrides kept (`autoComputeSingleDay`, after commit). For `save_all` and `attendance_override`, the minutes of the day row are recalculated (`recomputeDay`).
  - Then `recheckDay`.
  - The columns written are `shift_id`, `computed_work_minutes`, `computed_late_minutes`, `computed_early_minutes`, `computed_overtime_minutes`, `payable_work_minutes`, `needs_review` and `notes`. A rebuild from punches can also move times that have no override.
  - No `employee_shift_assignments` row is written.
- **Profile assignment request (.10).**
  - It is stored as one row with field `shift_assignment`, kept on the first date of its range. `details` holds the action (add/update/delete), the assignment id, the assignment before and after.
  - The reason is the Remarks field (the Reason box for a removal), and it is required.
  - It is kept out of every per-day reading: calendar markers and day items, the old Attendance page, per-day entries, `RecheckApprovedDays`.
  - The payroll page's pending count includes it when its range touches the period.
- **What an approved assignment recomputes:** every computed day from the earliest start to the latest end of the assignment as it is now and as asked, except locked dates (named on the card).
  - The range is open-ended when either side is ongoing, or when an addition closes an open assignment that started before it.
  - The recompute is the calendar's per-date shift recompute (rebuilt from punches, overrides kept) plus `recheckDay`. A range approved at once therefore ends with the same minutes as the same shift approved day by day.
  - The preview runs the same code in a dry run for at most the 62 most recent days.
- **Deviation: the CEO's direct profile path is now one transaction under the employee lock.** Its logic is unchanged: auto-close of the previous open assignment, the overlap rule, schedule mode to Manual, no recompute. One difference: an overlap refusal now also undoes the auto-close. Before, the previous assignment stayed closed although nothing was added. Cost if wrong: none known.
- **Requests are also checked when they are asked.** An overlap is refused at once. A second, different change from the same start date, or a second change of the same assignment, is refused while the first is pending. The same person asking again updates their own request.
- **An edit or removal whose assignment was changed since it was asked** needs "I have seen the changed values". If the assignment was removed since, the request can only be rejected.
- **Department-level shift assignment (not changed).** `DepartmentController::assignShift` creates or updates a `DepartmentShiftAssignment`. It copies it directly to the employees in department mode (`replicateShiftToEmployees`). It asks for no approval and recomputes no day.
- **Single company.** The schema has no company or tenant column, so "same company" in the threat model has nothing to check.
- **Migration:** one additive migration, a nullable `details` JSON column. The new status needs none, because `status` is `string(20)`.

## Deferred minors
- **Approving a long ongoing assignment recomputes every computed day of the range in one request.** It is inside the transaction, plus one rebuild per day after commit. Fine for weeks of days; a range of many months is slow. Reason: correctness first. A queued job would split one approval over time.
- **"Approve N days" keeps its label after a day of the card is decided on its own.** The posted days do follow (the decided day's inputs are removed). Cosmetic.
- **The decided tabs list a decided assignment request inside the day entry of its first date** when that date also has decided day requests. Display only.
- **The profile has no Withdraw button for a pending assignment request.** The existing withdraw endpoint works for it.

## Merge danger
- Two-way door for the code.
- **Blast radius:**
  - The CEO's Approvals page.
  - The profile shift assignment for HR and admin: their additions, edits and removals now wait for the CEO.
  - The badge count.
- **Revert:** revert the commits. Rows with status `no_longer_needed` stay and are shown by no tab of the old code (harmless). The `details` column can stay; rolling the migration back drops the ranges of pending assignment requests, so decide or withdraw those first.

## Deploy notes
- **Migration:** `php artisan migrate` (adds `attendance_change_requests.details`, nullable).
- **Caches:** `php artisan view:clear` (new and changed Blade views), plus `php artisan route:clear` or `route:cache` (two new routes) if routes are cached.
- No queue or worker change, no scheduler entry.
- **First load writes:** the first load of Approvals after the release closes every pending request whose value is already official. The 4:44 PM shift requests that already show "official now: 8-5PM" are among them, unless their date is locked. It also recomputes those days with the official shift.
- A draft payroll run of that period should be recomputed afterwards so it reads the new minutes.

## Tests
- **Covered:** the 29 new feature tests listed above, plus the whole existing suite.
- **Run:** `php vendor/bin/phpunit` (all) or the three files above.
- **Latest:** `OK (319 tests, 2928 assertions)` on 2026-10-30.
- **Review:** one skeptical review of the diff ran in the foreground. Fixed from it:
  - The confirm on a changed assignment.
  - The range past a closed assignment.
  - Cards of at most 50 days.
  - A group day must be alone.
  - An error in one employee's auto-close no longer breaks the page.
  - The overlap is checked when asked.
  - Date locks are read for update in the range recompute.
  - The payroll count and the recheck command now treat assignment requests as ranges.
  - The rest are under Deferred minors.

## Open questions
1. The employees list's bulk action "Assign shift" (`EmployeeController::bulkAction`, `assign_shift`) still writes assignments directly for any role that can use it. So do the profile's default shift and schedule mode (`EmployeeController::update`). The amendment names only the profile assignment form. Should these also go through approval?
2. The CEO's own profile assignment still recomputes no day (unchanged path). That is how requests go stale, and why .8 exists. Should the direct path recompute the range as the approval now does?

## Process suggestions
- The built-in browser cannot open a local file, and the page loads its styles from a CDN, so 390 px checks stay HTML-only. Evidence: S-006.8 and S-007.9 are both owner checks. A staging URL for phone checks would close both.

## Proposed tasks
- Bulk "Assign shift" and the default shift through approval: the same bypass as the profile form had — normal.
- Recompute the range on the CEO's direct profile assignment: stops new stale requests and stale minutes — normal.
- Withdraw button for a pending assignment request on the profile — low.

007: done — one card and one decision per shift change, requests that change nothing close themselves (page load and approve), profile shift assignments wait for approval; 319 tests green, one additive migration.
