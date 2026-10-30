# Result: spec 006 the actual device punches beside each approval

**Status:** done
**Date:** 2026-10-30
**Branch / PR:** `feat/006-approval-punches` (local worktree `.claude/worktrees/punches-006`, from `main` 95455a4). Not pushed; no PR.
**Preview or run link:** n/a (nothing deployed). Rendered evidence is in `docs/specs/006-evidence/`.

## Summary
Each pending request group on /approvals now has a "Device punches" block at the top of the right-hand column, directly above the spec-005 preview card. On a phone it sits below the request, before the preview and the buttons. `AttendanceApprovalService::devicePunches()` reads the punches of every pending entry on the page in one query: `DISTINCT employee_id, punched_at` with one `(employee_id = ? AND punched_at >= 'D 00:00:00' AND punched_at < 'D+1 06:00:00')` condition per entry, ordered by time. PHP then splits the rows into the day's own punches and the next date's punches before 06:00. `labelPunches()` gives each day punch the official field(s) it equals to the minute, using the same day row the preview's Now column reads. The partial `approvals/_punches.blade.php` renders the list ("8:02 AM · Time in"), the empty message and the muted "Next day: …" line. Nothing is written, there is no new route or input, and Approve/Reject are untouched.

## Case table
Command: `php vendor/bin/phpunit tests/Feature/Approvals/ApprovalPunchesTest.php --testdox`. Result: `OK (13 tests, 180 assertions)`.

| Case | Test | Result |
|---|---|---|
| S-006.1 | `test_s_006_1_shows_every_punch_of_the_date_in_time_order_in_12_hour_format` | pass: `✔ S 006 1 shows every punch of the date in time order in 12 hour format` |
| S-006.2 | `test_s_006_2_a_punch_stored_twice_is_listed_once` | pass: `✔ S 006 2 a punch stored twice is listed once` |
| S-006.3 | `test_s_006_3_early_next_day_punches_are_on_their_own_line_and_change_nothing` | pass: `✔ S 006 3 early next day punches are on their own line and change nothing` |
| S-006.4 | `test_s_006_4_a_date_without_punches_says_so`, `test_s_006_4_a_date_without_punches_still_shows_the_next_day_line` | pass: `✔ S 006 4 a date without punches says so`, `✔ S 006 4 a date without punches still shows the next day line` |
| S-006.5 | `test_s_006_5_the_punches_the_day_uses_now_carry_their_label`, `test_s_006_5_labels_follow_the_official_times_now_not_the_punches` | pass: `✔ S 006 5 the punches the day uses now carry their label`, `✔ S 006 5 labels follow the official times now not the punches` |
| S-006.6 | `test_s_006_6_another_employees_punches_never_appear`, `test_s_006_6_entries_of_one_employee_on_consecutive_dates_each_get_their_own_punches` | pass: `✔ S 006 6 another employees punches never appear`, `✔ S 006 6 entries of one employee on consecutive dates each get their own punches` |
| S-006.7 | `test_s_006_7_rendering_the_punches_writes_nothing`, `test_s_006_7_the_punch_queries_do_not_grow_with_the_groups` | pass: `✔ S 006 7 rendering the punches writes nothing`, `✔ S 006 7 the punch queries do not grow with the groups` |
| S-006.8 | `test_s_006_8_the_block_sits_below_the_request_and_never_widens_the_page`, plus a 390 px frame measurement (see Done-when) | pass: `✔ S 006 8 the block sits below the request and never widens the page` |
| S-006.9 | full suite | pass: `OK (290 tests, 2514 assertions)` (baseline `OK (277 tests, 2334 assertions)`) |
| (guard) | `test_decided_entries_have_no_punch_block` | pass: `✔ Decided entries have no punch block` |

Test-first: the 11 case tests were committed failing in f44ee69 (`Tests: 12, Assertions: 58, Failures: 11`; every failure was "the entry shows no device punches" or a count of 0). The 12th test, the decided-entries guard, passed before any code by design.

## Story changes
- Added "Slice 006 – The device punches beside each approval" to `qa/stories.md`, with story S-006 and cases S-006.1 to .9 as in the spec. Some cases are made more precise than the spec's wording, without changing their meaning. .1 adds the 00:00:00 and 23:59:59 edges and the day before. .3 adds the 5:59:59 and 6:00:00 edges. .5 adds a label that follows a changed official time. .6 adds consecutive dates of one employee. Reason: these edges are where the rules in the spec could go wrong.
- Added an owner check: S-006.8 on a real phone with the site's styles loaded (staging). Reason: the offline check here cannot load Bootstrap from the CDN.

## Done-when checklist
- [x] **All P1 cases pass with a test each.** See the case table: 13 tests, all passing.
- [x] **The full suite adds no new failures vs 95455a4.** Baseline recorded before any code, at 95455a4 in this worktree with `php vendor/bin/phpunit`: `OK (277 tests, 2334 assertions)`. After the change: `OK (290 tests, 2514 assertions)`, 0 failures.
- [x] **Rendered HTML of a request group with real-shaped fake data, desktop and 390 px.**
  - `docs/specs/006-evidence/request-group.html` is one entry and `approvals-page.html` is the whole page, rendered by the app in a test. The data: "Maria Dela Cruz" asks for a missed time out (5:00 PM) on 2026-10-08. The device has 7:58:12, 12:01:05 (stored twice), 12:58:40 and 15:10:22 that day, plus 01:11:03 and 07:55:30 the next day. The block as rendered:
    ```html
    <div class="approval-side">
    <div class="approval-punches border rounded p-2 mb-2">
        <div class="small fw-semibold mb-1">Device punches</div>
                <ul class="approval-punch-list list-unstyled mb-0">
                                <li class="approval-punch">7:58 AM<span class="small text-muted"> · Time in</span></li>
                                <li class="approval-punch">12:01 PM<span class="small text-muted"> · Lunch out</span></li>
                                <li class="approval-punch">12:58 PM<span class="small text-muted"> · Lunch in</span></li>
                                <li class="approval-punch">3:10 PM</li>
                        </ul>
                    <div class="small text-muted mt-1 approval-punches-next">Next day: 1:11 AM</div>
        </div><!-- /approval-punches -->
    <div class="approval-preview border rounded p-2" data-preview="ok">
    ```
    So: the duplicate is shown once, seconds are dropped, the extra 3:10 PM punch is plain, and the 7:55 AM next-day punch is not shown.
  - The desktop and phone layout is the same markup. The block is the first child of `.approval-side`: the right-hand column from 992 px, and below the request under 992 px (S-006.8 test).
  - 390 px: local headless Chrome, all network blocked (`--host-resolver-rules="MAP * ~NOTFOUND"`), so nothing was downloaded. The page was loaded in a 390 px iframe. Measured: `punchesTop=1160 requestBottom=1148 punchesRight=343 clientWidth=375`, so the block starts below the request and ends inside the screen. The page as a whole measured `scrollWidth=459`. The only elements past the edge were existing form inputs (`SELECT.form-select`, the `INPUT.form-control` filters and the reject-reason input). They have no Bootstrap styles here because Bootstrap is loaded from the CDN, which was blocked. Nothing in the punch block overflowed. No screenshot is attached: without Bootstrap the sidebar and lists render unstyled and would misrepresent the page. The owner check in `qa/stories.md` covers a real phone.
- [x] **The query count for 30 groups** (`docs/specs/006-evidence/query-count.txt`, measured with `DB::listen` during one page load):

  | groups on page | all queries | queries on attendance_logs | punch-block queries |
  |---|---|---|---|
  | 1 | 62 | 1 | 1 |
  | 30 | 1193 | 1 | 1 |

  The total grows because of the spec-005 preview's per-entry dry runs, which already existed. The punch read stays at one. The S-006.7 test asserts exactly one `attendance_logs` query for 1 and for 31 entries.
- [x] **The cases table.** Above.
- [x] **Deploy notes.** Below.
- [x] **Ends with "006: <done|blocked> — <one line>".** Last line.

## How to run
```
git clone <repo> && cd likha-hr && git checkout feat/006-approval-punches
composer install
cp .env.example .env && php artisan key:generate   # tests use sqlite in memory from phpunit.xml
php vendor/bin/phpunit tests/Feature/Approvals/ApprovalPunchesTest.php --testdox
php vendor/bin/phpunit
```

## Rulings
- Ruling: the block appears on pending entries only, not on the Approved/Rejected/Withdrawn tabs — the spec places it with the spec-005 preview, which only pending entries have, and its labels are the day's times "now", which mean nothing for a decision already taken — cost if wrong: one `@isset` and the status check in `buildEntries` to widen.
- Ruling: the block goes inside `.approval-side`, above the preview card. It is not a separate grid area — this keeps the 005 layout and its CSS unchanged — cost if wrong: a template move.
- Ruling: labels compare the date as well as the minute (`Y-m-d H:i`), against the same day row the preview's Now column reads (`findDay`) — an official time stored on another date (an override past midnight) must not label a same-day punch with the same clock time — cost if wrong: none today, since no code path stores the times on another date.
- Ruling: two distinct punches in the same minute (different seconds) both carry the label — the spec matches "to the minute", and only identical `punched_at` values count as duplicates — cost if wrong: one label shown twice.
- Ruling: a punch equal to two official times shows both labels, comma-separated — the spec doesn't cover it; this hides nothing — cost if wrong: cosmetic.
- Ruling: next-day punches carry no label — the spec says they are "only shown" — cost if wrong: none.
- Ruling: one OR condition per entry instead of a min/max date range per employee — this reads exactly the needed days through the existing `(employee_id, punched_at)` index, however far apart the page's dates are (up to 50 entries × 3 bindings) — cost if wrong: a longer SQL string on a full page.
- Ruling: on the phone, the punches are a wrapping row (flex, wrap) rather than one per line — this keeps the block short above the preview, and nothing in it has a width — cost if wrong: cosmetic.

## Deferred minors
- Review: the OR chain grows with the page size (3 bindings per entry). Accepted: there are 50 entries per page today, well within limits.
- Review: `findDay` and the preview still run per-entry queries (spec 005). Accepted: these are not punch queries, and the spec rules out changing anything else on the page.
- Review: S-006.8 cannot prove "no sideways scroll" from HTML alone. Partly covered by the offline 390 px frame measurement. The rest is the staging owner check.
- Review findings 1 (count every `attendance_logs` query) and 2 (consecutive dates of one employee) were fixed in 8101eb0.

## Merge danger
Two-way door. The change is read-only display on a page only the CEO can open. No migration, route, input or write. If it is wrong, the CEO sees a wrong or missing punch list, while the Approve/Reject behaviour and all data stay unchanged. To revert: `git revert` the feat commit 7036c0c (the tests in f44ee69/8101eb0 would then fail and go with it).

## Deploy notes
- Migrations: none.
- Caches: `php artisan view:clear` (or `view:cache`), for the new partial `approvals/_punches.blade.php` and the changed `approvals/index.blade.php`. No config, route or queue change, so no worker restart is needed.
- Not deployed. Nothing was pushed.

## Tests
- `tests/Feature/Approvals/ApprovalPunchesTest.php`: 13 tests covering S-006.1 to .8 and the decided-tab guard, on the existing `ApprovalsTestCase` fixtures (sqlite in memory).
- Run: `php vendor/bin/phpunit tests/Feature/Approvals/ApprovalPunchesTest.php` gives `OK (13 tests, 180 assertions)`. The full `php vendor/bin/phpunit` gives `OK (290 tests, 2514 assertions)` (2026-10-30).

## Open questions
None.

## Process suggestions
- Real-browser evidence at 390 px needs the CDN styles. Headless Chrome offline cannot load Bootstrap, and it won't size a window below about 500 px (it measured 512 when asked for 390). A local copy of the CSS for tests, or a staging check, would give a true screenshot.

## Proposed tasks
- Remove the duplicate punches older imports left in `attendance_logs` — the page already hides them, but the compute and other pages still see them — normal.
- Show the device punches on the Attendance Calendar day editor too — the same judgement is made there — low.

006: done — each pending approval now shows that day's device punches, deduplicated, with next-day early punches on their own line and labels for the official times; one punch query per page; 290 tests pass.
