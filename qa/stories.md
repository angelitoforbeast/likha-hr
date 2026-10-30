# Stories

## Slice 001 – Import computes the attendance days

Slice 001: 32 passed, 0 failed, 0 blocked, 0 skipped, 0 not run

Tests live in `tests/Feature/Import/`. Run them with `php vendor/phpunit/phpunit/phpunit --testdox`.

Fixtures used in every case. Employees: A (zkteco id 1, active), B (id 2, active), C (id 3, status inactive), D (id 4, active, not in the file); each has a shift. Dates: Mon 2026-10-05 (D1), Tue 2026-10-06 (D2), Wed 2026-10-07 (D3). A "full day" in a file means four lines at 08:00:00, 12:00:00, 13:00:00 and 17:00:00. "Day row" means one `attendance_days` row for that employee and `work_date`. "Processed" means the import job ran for that run. No case asserts minutes, lateness or pay: only whether a row exists, is kept, is skipped or is untouched, and the counts.

### S-01 Importing a file fills in the attendance days (P1)
As an admin, I want the days to be computed as soon as an import finishes, so that the Attendance Calendar shows the imported dates without anyone pressing "Compute Attendance".
Independent test: import a file with a full day for A on D1 and for B on D1 into an empty database; both day rows exist and the run is done.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-01.1 | happy | Given no day rows and a file with a full day for A on D1 and for B on D1 and D2, when processed, then 3 day rows exist (A/D1, B/D1, B/D2), each with `source_run_id` = the run id, the run status is `done`, and the compute summary is days computed 3, locked skipped 0, errors 0 | `ImportComputesDaysTest::test_s_01_1_import_creates_the_day_rows_of_the_file` | Passed · 2026-10-09 · auto |
| S-01.2 | happy | Given the same file was already imported as run 1 (its 12 punches are stored) and the 3 day rows were deleted afterwards, when the same file is processed again as run 2, then `attendance_logs` still holds exactly 12 rows for A and B on D1–D2 (none inserted again; run 2 reports 12 duplicates skipped and 0 inserted), the 3 day rows exist again, and the summary says 3 computed | `ImportComputesDaysTest::test_s_01_2_same_file_twice_inserts_nothing_again` | Passed · 2026-10-09 · auto |
| S-01.3 | edge | Given a file where A has lines at 08:00:00, 08:01:00 and 08:02:00 on D1 (the last two fall within the 3-minute duplicate window) and nothing else, when processed, then one punch is stored, the A/D1 day row exists and the summary says 1 computed | `ImportComputesDaysTest::test_s_01_3_duplicates_inside_the_file_still_compute_the_day` | Passed · 2026-10-09 · auto |
| S-01.4 | edge | Given A has stored punches on D1 and D3 from an earlier run and no day rows, and the new file holds A's punches only on D1 and D2, when processed, then day rows exist for A/D1 and A/D2 only, A/D3 has no row, and the summary says 2 computed | `ImportComputesDaysTest::test_s_01_4_dates_outside_the_file_are_not_computed` | Passed · 2026-10-09 · auto |
| S-01.5 | edge | Given D has stored punches on D1 and a day row for D1, and the file has only A's and B's lines, when processed, then D's row is unchanged (same values, same `updated_at`, same `source_run_id`) | `ImportComputesDaysTest::test_s_01_5_employee_not_in_the_file_is_untouched` | Passed · 2026-10-09 · auto |
| S-01.6 | edge | Given the file has a line for zkteco id 99 that matches no employee, plus a full day for A on D1, when processed, then only the A/D1 row is created, `unmatched_zkteco_ids` is 1, and the unmatched id adds no day row and no compute error | `ImportComputesDaysTest::test_s_01_6_unmatched_id_adds_no_day_and_no_error` | Passed · 2026-10-09 · auto |
| S-01.7 | edge | Given a file with zero valid lines (only blank lines and one line `garbage<TAB>not-a-date`), when processed, then the run is `done`, no day rows exist, and the summary is computed 0, locked skipped 0, errors 0 | `ImportComputesDaysTest::test_s_01_7_file_without_valid_lines` | Passed · 2026-10-09 · auto |
| S-01.8 | negative | Given A has stored punches on D1 at 08:00:00 and 12:00:00 and a day row for D1 from an earlier run (no overrides), and the file has A on D1 at 08:00:00, 12:00:00, 13:00:00 and 17:00:00, when processed, then two punches are inserted and two skipped as duplicates, there is still exactly one A/D1 row, its `source_run_id` is this run, and the summary says 1 computed | `ImportComputesDaysTest::test_s_01_8_existing_day_row_with_new_punches_is_recomputed_in_place` | Passed · 2026-10-09 · auto |
| S-01.9 | edge | Given A has a stored punch on D1 at 08:00:00 from an earlier run, and the new file has A on D1 at 08:01:30, 12:00:00, 13:00:00 and 17:00:00, when processed, then 08:01:30 is skipped as a duplicate of the stored punch (duplicates skipped 1, inserted 3), and the A/D1 day row exists | `ImportComputesDaysTest::test_s_01_9_stored_punch_makes_a_file_punch_a_duplicate` | Passed · 2026-10-09 · auto |
| S-01.10 | edge | Given the file has three lines for A whose timestamps have the right shape but are not real times (`2026-13-45 08:00:00`, `2026-02-30 08:00:00`, `2026-10-05 25:61:61`) plus a full day for A on D1, when processed, then the run is `done`, the three lines are skipped as invalid (7 lines parsed, 4 punches inserted), the A/D1 day row exists and the summary is computed 1, locked skipped 0, errors 0 | `ImportComputesDaysTest::test_s_01_10_impossible_timestamp_is_an_invalid_line` | Passed · 2026-10-09 · auto |
| S-01.11 | edge | Given A has a stored punch on D1 at 23:59:30 from an earlier run, and the file has only A on D2 at 00:01:00, when processed, then that line is skipped as a duplicate (duplicates skipped 1, inserted 0), no day row exists for A on D2 or D1, and the summary is computed 0, locked skipped 0, errors 0: a date on which no punch is stored is counted nowhere | `ImportComputesDaysTest::test_s_01_11_date_without_a_stored_punch_is_not_counted` | Passed · 2026-10-09 · auto |
| S-01.12 | negative | Given A has four stored punches on D1 (full day) and a day row for D1 from an earlier run (values V, `updated_at` T, `source_run_id` of the earlier run), and the file has the same full day for A on D1, when processed, then nothing is inserted (4 duplicates), the A/D1 row still has values V, `updated_at` T and the earlier `source_run_id`, and the summary says 0 computed, 0 locked skipped, 0 errors | `ImportComputesDaysTest::test_s_01_12_day_with_a_row_and_no_new_punch_is_untouched` | Passed · 2026-10-09 · auto |
| S-01.13 | edge | Given the same file as S-01.12 plus a full day for B on D1 whose punches are already stored but B has no day row, when processed, then B/D1 gets a row with `source_run_id` = this run, A/D1 is unchanged, and the summary says 1 computed | `ImportComputesDaysTest::test_s_01_13_day_without_a_row_is_computed_even_without_new_punches` | Passed · 2026-10-09 · auto |

### S-02 The automatic compute respects overrides, locks and inactive staff (P1)
As an admin, I want the import to leave alone what people have already fixed, locked or deactivated, so that importing a file never undoes manual work or changes a closed date.
Independent test: lock D1, import a file with A on D1 and D2; D2 has a row, D1 has none, and the summary says 1 day computed, 1 locked skipped.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-02.1 | happy | Given A/D1 has a day row with a manual override on one time field (value X, with its `attendance_overrides` row), and the file has a full day for A on D1, when processed, then that field still equals X, the row id is unchanged, and the summary counts it as computed (1) | `ImportRespectsLocksAndOverridesTest::test_s_02_1_overridden_field_is_kept` | Passed · 2026-10-09 · auto |
| S-02.2 | happy | Given D1 is in `attendance_date_locks` and the file has a full day for A on D1 and on D2, when processed, then A/D2 has a row, A/D1 has no row, and the summary says 1 computed, 1 locked skipped. A's punches for D1 are still saved in `attendance_logs` | `ImportRespectsLocksAndOverridesTest::test_s_02_2_locked_date_is_skipped` | Passed · 2026-10-09 · auto |
| S-02.3 | negative | Given D1 is locked and A/D1 already has a day row (values V, `updated_at` T), and the file has A on D1, when processed, then the A/D1 row still has values V and `updated_at` T, and the summary says 0 computed, 1 locked skipped | `ImportRespectsLocksAndOverridesTest::test_s_02_3_existing_row_on_a_locked_date_is_untouched` | Passed · 2026-10-09 · auto |
| S-02.4 | negative | Given C is inactive and the file has a full day for C on D1 and for A on D1, when processed, then A/D1 has a row and C/D1 has none, the run is `done`, C's punches are saved in `attendance_logs`, and the summary says 1 computed, 0 locked skipped, 0 errors (inactive staff are not counted anywhere) | `ImportRespectsLocksAndOverridesTest::test_s_02_4_inactive_employee_is_not_computed` | Passed · 2026-10-09 · auto |
| S-02.5 | edge | Given D1 is locked and the file has A and B on D1 plus A on D2, when processed, then locked skipped is 2 (counted per employee-day) and only A/D2 is computed | `ImportRespectsLocksAndOverridesTest::test_s_02_5_locked_days_are_counted_per_employee_day` | Passed · 2026-10-09 · auto |
| S-02.6 | edge | Given A/D1 has an override and D1 is also locked, when processed, then the override row and the day row are unchanged and the day counts as locked skipped, not computed | `ImportRespectsLocksAndOverridesTest::test_s_02_6_override_on_a_locked_date_is_untouched` | Passed · 2026-10-09 · auto |

### S-03 A compute problem never fails the import (P1)
As an admin, I want the punches to stay saved and the run to stay "done" even if a day can't be computed, so that one bad day doesn't force me to upload the whole file again.
Independent test: make the compute fail for A/D1, import a file with A/D1, A/D2 and B/D1; the run is done, 2 day rows exist, and the summary says errors 1.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-03.1 | happy | Given the compute is made to throw for A/D1 only, and the file has A on D1 and D2 and B on D1, when processed, then the run status is `done`, all punches are in `attendance_logs`, rows exist for A/D2 and B/D1 but not A/D1, the summary says 2 computed, 0 locked skipped, 1 error, and one log entry names the employee id and the date | `ImportSurvivesComputeFailureTest::test_s_03_1_one_failing_day_does_not_stop_the_others` | Passed · 2026-10-09 · auto |
| S-03.2 | negative | Given the whole compute step throws before any day is computed, when processed, then the run is `done` (not `failed`), the punches are saved, `stats_json` has no top-level `error` key, and the compute summary is computed 0, locked skipped 0, errors 1 | `ImportSurvivesComputeFailureTest::test_s_03_2_failing_compute_step_does_not_fail_the_import` | Passed · 2026-10-09 · auto |
| S-03.3 | happy | Given the run's `stats_json` was set at commit by `previewCommit` (`preview_filter`: date_from 2026-10-05, date_to 2026-10-07, included_count 2, kept_lines 9, dropped_lines 3), when the import finishes, then `stats_json` read through the model is an array whose `preview_filter` still has those five values, next to the parse counts and the compute summary | `ImportSurvivesComputeFailureTest::test_s_03_3_preview_filter_is_kept` | Passed · 2026-10-09 · auto |
| S-03.4 | edge | Given a run whose `stats_json` holds the preview filter in the older stored form (a JSON string inside the JSON column, as the code before this spec wrote it), when the import finishes, then `preview_filter` is kept the same way as in S-03.3 | `ImportSurvivesComputeFailureTest::test_s_03_4_preview_filter_in_the_older_form_is_kept` | Passed · 2026-10-09 · auto |
| S-03.5 | edge | Given a run with a preview filter and the parse itself fails (`attlog.dat` missing), when processed, then the run is `failed`, `stats_json.error` is set, `preview_filter` is still there, and there is no compute summary | `ImportSurvivesComputeFailureTest::test_s_03_5_failed_parse_keeps_the_preview_filter` | Passed · 2026-10-09 · auto |
| S-03.6 | edge | Given a successful import, when the job finishes, then the compute summary has the three keys days computed, locked skipped and errors, each an integer (0 when nothing happened) | `ImportSurvivesComputeFailureTest::test_s_03_6_summary_has_three_integer_keys` | Passed · 2026-10-09 · auto |

### S-04 The Import page shows what the compute did (P2)
As an admin, I want the run's Stats cell to say how many days were computed and how many were skipped, so that I can see at a glance whether the calendar is complete.
Independent test: render the Import page for a done run with a compute summary of 37 computed and 2 locked skipped; the Stats cell contains "Computed: 37 days, 2 locked skipped".

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-04.1 | happy | Given a done run with parse counts and a summary of computed 37, locked skipped 2, errors 0, when the Import page renders for a signed-in user who may see it, then that run's Stats cell still shows the existing "Users … Logs … Dupes … Unmatched …" text and also "Computed: 37 days, 2 locked skipped" | `ImportPageShowsComputeTest::test_s_04_1_cell_shows_computed_and_locked_skipped` | Passed · 2026-10-09 · auto |
| S-04.2 | negative | Given an older done run whose stats have no compute summary, when the page renders, then the cell is exactly as today, with no "Computed" text and no error | `ImportPageShowsComputeTest::test_s_04_2_run_without_summary_is_unchanged` | Passed · 2026-10-09 · auto |
| S-04.3 | edge | Given a summary of computed 0, locked skipped 0, errors 0, when the page renders, then the cell shows "Computed: 0 days" and no "locked skipped" and no "errors" text | `ImportPageShowsComputeTest::test_s_04_3_zero_summary` | Passed · 2026-10-09 · auto |
| S-04.4 | edge | Given a summary of computed 5, locked skipped 0, errors 3, when the page renders, then the cell shows "Computed: 5 days, 3 errors" | `ImportPageShowsComputeTest::test_s_04_4_errors_without_locked_skipped` | Passed · 2026-10-09 · auto |
| S-04.5 | edge | Given a queued run whose `stats_json` holds only the preview filter (in the new array form, and again in the older string form), when the page renders, then it renders without error and shows no "Computed" text | `ImportPageShowsComputeTest::test_s_04_5_queued_run_with_only_the_preview_filter` | Passed · 2026-10-09 · auto |
| S-04.6 | edge | Given a run that is still processing and then finishes while the page is open, when the page's status poll receives the finished run, then the Stats cell shows the same "Computed: …" text without a reload (the text comes from the status response; the poll running in a browser is checked by hand) | `ImportPageShowsComputeTest::test_s_04_6_status_poll_carries_the_compute_text` | Passed · 2026-10-09 · auto |
| S-04.7 | edge | Given a summary of computed 1, locked skipped 0, errors 0, when the page renders, then the cell shows "Computed: 1 day" (singular) | `ImportPageShowsComputeTest::test_s_04_7_one_day_is_singular` | Passed · 2026-10-09 · auto |

## Slice 002 – Approvals for attendance changes

Slice 002: 164 passed, 0 failed, 0 blocked, 0 skipped, 0 not run

Tests live in `tests/Feature/Approvals/`. Run them with `php vendor/phpunit/phpunit/phpunit --testdox`. Cases that name a phone width or what a page shows are checked on the rendered HTML; no test renders a real screen. `LockConflictAndGuardsTest` holds 13 tests without a case number, written after a run on MySQL: they pin rules of S-16 and S-19 (one lock order, the retry after a deadlock, who may do what with their own old request). `PageUpdatesTest` holds 6 more, written after a run in a browser: the replies that let a page show a saved request at once, no browser dialogs on the controls of this slice, and the calendar's request script run under `node` (skipped where `node` is not installed).

Fixtures used in every case. Users: C (role ceo), H and H2 (role hr_staff), M (role admin). Employees: E1 and E2 (active, shift 08:00–17:00 with lunch 12:00–13:00), E3 (inactive). Dates: Dn is an unlocked weekday where E1 has no day row, no rest day and no SIL; Dr is an unlocked weekday where E1 has a day row from punches at 08:03, 12:00, 13:00 and 17:00; Dl is a locked date. "Request" means one pending change asked by HR staff or an admin; the Approvals page shows one entry per employee and date that holds all of that day's pending fields, and an entry is approved or rejected as a whole. "Official" means what is in `attendance_days`, `attendance_overrides`, `day_offs`, `sil_applications` and `employee_shift_assignments`. "Day row" means one `attendance_days` row for that employee and `work_date`. "Payroll" means what `PayrollService` computes for E1. "Direct" means saved at once with no request. Cases that compare payroll note E1's payroll numbers, the count of E1's day rows and Dr's `updated_at` before the action. Rules in force: an admin is treated exactly like HR staff (asks, never approves); times, approved overtime, applying and removing SIL, assigning a shift for a date and bulk actions by HR staff or an admin all go through approval; a rest day change (add, cancel or remove) is direct when the employee has no day row on that date and goes through approval when there is a day row; the CEO's own edits are direct; only the CEO approves; a locked date takes no request and cannot be approved while locked.

### S-05 HR staff asks for times on a day (P1)
As an HR staff member, I want to enter times and a reason and have them wait for the CEO, so that I can record a missed punch without changing pay myself.
Independent test: H saves four times with a reason on E1/Dn; a pending request exists and E1/Dn has no day row.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-05.1 | happy | Given Dn with no day row, when H saves 08:00, 12:00, 13:00 and 17:00 with reason "Nakalimutan mag-punch" (`saveAll`), then the reply says it was sent for approval (not a 403), one pending request holds employee, date, the four values, the reason, requester H and when it was asked, and there is no day row, no override row, and payroll is unchanged | `RequestTimesTest::test_s_05_1_times_on_a_day_without_a_row_become_a_request` | Passed · 2026-10-14 · auto |
| S-05.2 | happy | Given Dr (time_in 08:03), when H changes time_in to 08:00, then a pending request holds old 08:03:00 and new 08:00:00, and the day row (values, `updated_at`), its totals and the override table are unchanged | `RequestTimesTest::test_s_05_2_change_on_a_day_with_a_row_leaves_the_row_untouched` | Passed · 2026-10-14 · auto |
| S-05.3 | happy | Given Dr, when H edits one field from the modal (`overrideTime`), then a pending request exists and the day row is untouched | `RequestTimesTest::test_s_05_3_single_field_edit_becomes_a_request` | Passed · 2026-10-14 · auto |
| S-05.4 | happy | Given Dn, when H uses the add-time flow of the modal (today: create day, then enter times), then exactly one request holding the times exists, no blank day row exists, no "CEO access required" message shows, and no request without values is listed | `RequestTimesTest::test_s_05_4_add_time_flow_makes_no_blank_day_row` | Passed · 2026-10-14 · auto |
| S-05.5 | happy | Given Dn and a shift for E1, when H uses Fill from Shift, then one pending request holds the four times of the shift as they were at the request, and there is no day row | `RequestTimesTest::test_s_05_5_fill_from_shift_becomes_a_request` | Passed · 2026-10-14 · auto |
| S-05.6 | happy | Given the same as S-05.1, when admin M saves, then the result is the same, with requester M, and M can never approve | `RequestTimesTest::test_s_05_6_admin_asks_like_hr_staff` | Passed · 2026-10-14 · auto |
| S-05.7 | negative | Given a reason that is empty or 2 characters, when H saves, then it is refused (422) and nothing is stored | `RequestTimesTest::test_s_05_7_short_reason_is_refused` | Passed · 2026-10-14 · auto |
| S-05.8 | negative | Given Dl, when H saves through any entry point (`saveAll`, `overrideTime`, `createDay`, `fillFromShift`), then it is refused (423) and no request exists | `RequestTimesTest::test_s_05_8_locked_date_takes_no_request` | Passed · 2026-10-14 · auto |
| S-05.9 | negative | Given Dr, when H saves the values the day already has, then the reply says "No changes to save." and no request is created | `RequestTimesTest::test_s_05_9_unchanged_values_create_no_request` | Passed · 2026-10-14 · auto |
| S-05.10 | negative | Given a time such as `25:61`, an unknown employee id or a value that is not a date, when H saves, then it is refused (422) and nothing is stored | `RequestTimesTest::test_s_05_10_bad_input_is_refused` | Passed · 2026-10-14 · auto |
| S-05.11 | edge | Given H sends `saveAll` on Dn with times and `shift_id` together, when it is processed, then nothing official is written: no day row and no `employee_shift_assignments` row (today the shift row is written before the refusal) | `RequestTimesTest::test_s_05_11_times_with_a_shift_write_nothing_official` | Passed · 2026-10-14 · auto |
| S-05.12 | edge | Given Dr, when H clears time_out (`clear_fields`), then the request records "clear" with old value 17:00:00, and the Approvals page shows "cleared", not a blank | `RequestTimesTest::test_s_05_12_clear_is_recorded_as_clear` | Passed · 2026-10-14 · auto |
| S-05.13 | edge | Given a reason of exactly 500 characters, then it is accepted; at 501 it is refused. A reason in Filipino and non-ASCII ("Hindi nakapag-punch dahil sira ang makina, Ñoño") is stored and shown intact in the modal and on the Approvals page | `RequestTimesTest::test_s_05_13_reason_length_and_characters` | Passed · 2026-10-14 · auto |
| S-05.14 | edge | Given the same save is sent twice (double click), then one request exists | `RequestTimesTest::test_s_05_14_double_click_leaves_one_request` | Passed · 2026-10-14 · auto |
| S-05.15 | edge | Given the server saved but the reply was lost (failed network), when H reloads the calendar, then the day shows pending once, and saving again does not create a second request (rule in S-15) | `RequestTimesTest::test_s_05_15_lost_reply_then_save_again` | Passed · 2026-10-14 · auto |
| S-05.16 | edge | Given the HR modal opened on a phone (390 × 844), when H saves, then the confirmation, the pending marker and the reason field are all visible without horizontal scrolling | `RequestTimesTest::test_s_05_16_modal_parts_for_a_phone` | Passed · 2026-10-14 · auto |

### S-06 Overtime, SIL and shift changes also wait (P1)
As an HR staff member, I want every kind of change to a day to wait for approval, so that nothing that moves pay is decided by me alone.
Independent test: H applies SIL for E1 on Dn; a pending request exists and there is no `sil_applications` row.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-06.1 | happy | Given Dr, when H sets approved overtime to 2 hours, then a pending request holds 2 h, and `approved_overtime_minutes`, the totals and the override table are unchanged. "0" (approve no overtime) and "back to automatic" are two different requests, shown as "0 h" and "automatic" | `RequestOtherKindsTest::test_s_06_1_overtime_becomes_a_request` | Passed · 2026-10-14 · auto |
| S-06.2 | happy | Given E1 is SIL-eligible with balance, when H applies SIL for Dn, then a pending request exists, no `sil_applications` row exists, the remaining balance is unchanged, and payroll is unchanged | `RequestOtherKindsTest::test_s_06_2_sil_apply_becomes_a_request` | Passed · 2026-10-14 · auto |
| S-06.3 | happy | Given SIL applied on a date, when H removes it with a reason, then a pending removal exists and the SIL is still applied and counted until approval. Without a reason the removal is refused (422): every request carries a reason | `RequestOtherKindsTest::test_s_06_3_sil_removal_becomes_a_request` | Passed · 2026-10-14 · auto |
| S-06.4 | happy | Given a date with the current shift, when H assigns another shift for that date with a reason (`assignShiftForDate`), then a pending request exists, no new `employee_shift_assignments` row exists, and `getShiftForDate` still returns the old shift. Without a reason it is refused (422) | `RequestOtherKindsTest::test_s_06_4_shift_becomes_a_request` | Passed · 2026-10-14 · auto |
| S-06.5 | negative | Given E1 is not SIL-eligible, or the balance is 0, or SIL is already applied on that date, when H applies SIL, then it is refused (422) with the existing message and no request is created | `RequestOtherKindsTest::test_s_06_5_sil_that_cannot_be_applied` | Passed · 2026-10-14 · auto |
| S-06.6 | edge | Given a balance of 1 and two pending SIL requests for two different dates, when C approves the first, then the second cannot be approved ("No remaining SIL days") and nothing is applied for it | `RequestOtherKindsTest::test_s_06_6_last_sil_day_goes_once` | Passed · 2026-10-14 · auto |
| S-06.7 | edge | Given H saves times, overtime and a shift for E1/Dr in one save, then one request per field is created, the Approvals page shows ONE entry for E1/Dr holding all of them, and Approve or Reject applies to all of them together | `RequestOtherKindsTest::test_s_06_7_one_save_one_entry` | Passed · 2026-10-14 · auto |
| S-06.8 | edge | Given a SIL request on a date where E1 already has a day row with times, then the Approvals page shows both, so the CEO sees what SIL will replace | `RequestOtherKindsTest::test_s_06_8_sil_request_shows_the_day` | Passed · 2026-10-14 · auto |

### S-07 Rest day changes (P1)
As an HR staff member, I want turning an absent day into a rest day to take effect at once, and a rest day change on a day that already has a record to wait for the CEO, so that a mis-scheduled day is fixed fast but a worked day can't be taken out of pay alone.
Independent test: H marks E1/Dn (no day row) as a rest day; `day_offs` and `day_off_logs` have rows and no request exists.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-07.1 | happy | Given Dn with no day row, when H adds a day off with a reason, then a `day_offs` row (type day off) exists, one `day_off_logs` row names H and the reason, there is no request, and the calendar shows the rest day | `RequestOtherKindsTest::test_s_07_1_rest_day_on_an_absent_day_is_direct` | Passed · 2026-10-14 · auto |
| S-07.2 | happy | Given a rest day on Dn with no day row, when H cancels it ("must work"), then it is direct, with a log row, and no request | `RequestOtherKindsTest::test_s_07_2_cancel_on_an_absent_day_is_direct` | Passed · 2026-10-14 · auto |
| S-07.3 | happy | Given a day-off override on Dn with no day row, when H removes the override, then it is direct, with a log row, and no request | `RequestOtherKindsTest::test_s_07_3_remove_on_an_absent_day_is_direct` | Passed · 2026-10-14 · auto |
| S-07.4 | happy | Given Dr (E1 has a day row), when H adds a day off, then a pending request exists, nothing is official yet (no `day_offs` row, no log row, day row and totals unchanged, payroll unchanged), and the cell is marked pending | `RequestOtherKindsTest::test_s_07_4_rest_day_on_a_worked_day_becomes_a_request` | Passed · 2026-10-14 · auto |
| S-07.5 | happy | Given a rest day and a day row on the same date, when H cancels the rest day or removes the override, then each is a pending request and nothing changes until approved | `RequestOtherKindsTest::test_s_07_5_cancel_or_remove_on_a_worked_day_becomes_a_request` | Passed · 2026-10-14 · auto |
| S-07.6 | happy | Given the S-07.4 request, when C approves, then the `day_offs` row and one `day_off_logs` row exist as if C had set them, the log names C as the one who set it, the request and the day's history name H as the one who asked, and payroll equals the payroll after C sets the same rest day directly | `RequestOtherKindsTest::test_s_07_6_approved_rest_day_equals_a_direct_one` | Passed · 2026-10-14 · auto |
| S-07.7 | negative | Given Dl, when H toggles a day off (with or without a day row), then it is refused (423) as today. A missing or 2-character reason is refused (422) | `RequestOtherKindsTest::test_s_07_7_locked_date_and_short_reason` | Passed · 2026-10-14 · auto |
| S-07.8 | edge | Given a pending times request for E1/Dn, when H makes Dn a rest day directly (no day row), then the rest day stands and the request is flagged "now a rest day", cannot be approved and can be rejected (S-17.4) | `RequestOtherKindsTest::test_s_07_8_times_request_then_rest_day` | Passed · 2026-10-14 · auto |
| S-07.9 | edge | Given a bulk day-off action by H over 3 employees where 2 have no day row on that date and 1 has one, then the 2 are direct (one log row each) and the third becomes a pending request, and the reply says "2 done, 1 sent for approval" | `BulkAndBatchTest::test_s_07_9_bulk_day_off_mixed` | Passed · 2026-10-14 · auto |
| S-07.10 | edge | Given a pending rest-day request on Dr, when H asks the same again, then one request remains (rule in S-15) | `RequestOtherKindsTest::test_s_07_10_same_rest_day_request_again` | Passed · 2026-10-14 · auto |
| S-07.11 | edge | Given the CEO sets or cancels a rest day on a date with or without a day row, then it is direct and no request exists | `RequestOtherKindsTest::test_s_07_11_ceo_rest_day_is_direct` | Passed · 2026-10-14 · auto |
| S-07.12 | negative | Given H or M on the Day Off Calendar (`/day-off-calendar/toggle`), when they change a rest day on Dl, then it is refused (423); on Dr (E1 has a day row) it is refused with "This day has attendance. Change it on the Attendance Calendar." and nothing is written; on Dn (no day row) it is direct as before. The CEO is unchanged | `RequestOtherKindsTest::test_s_07_12_day_off_calendar_guard` | Passed · 2026-10-14 · auto |
| S-07.13 | negative | Given H or M on the Employee page, when they add, change or delete a day-off override, then the same guard applies: 423 on a locked date, the same refusal on a date with a day row (also for the date an override is moved to), direct with no day row. The CEO is unchanged | `RequestOtherKindsTest::test_s_07_13_employee_page_guard` | Passed · 2026-10-14 · auto |

### S-08 The CEO's own edits stay direct (P1)
As the CEO, I want my own edits to apply at once, so that I never have to approve myself.
Independent test: C saves times on E1/Dn; the day row and override rows exist and no request exists.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-08.1 | happy | Given Dn, when C saves four times with a reason, then the day row and one override row per field exist (`updated_by` = C), and no request is created | `RequestTimesTest::test_s_08_1_ceo_saves_times_directly` | Passed · 2026-10-14 · auto |
| S-08.2 | happy | Given C changes a time on Dr, approved overtime, SIL, a shift for a date, a rest day, or runs a bulk action, then each is direct as today and no request is created | `RequestOtherKindsTest::test_s_08_2_ceo_changes_are_direct` | Passed · 2026-10-14 · auto |
| S-08.3 | negative | Given Dl, when C edits through the calendar, then it is refused (423) | `RequestTimesTest::test_s_08_3_ceo_is_refused_on_a_locked_date` | Passed · 2026-10-14 · auto |
| S-08.4 | edge | Given a user who was admin when they asked and is now CEO, when they edit, then the edit is direct (the role is read at each call), and their earlier request follows S-19.5 | `RequestOtherKindsTest::test_s_08_4_role_is_read_at_each_call` | Passed · 2026-10-14 · auto |
| S-08.5 | edge | Given C's direct edit on a date where H has a pending request, then the request follows S-17.2 and S-17.3 | `RequestOtherKindsTest::test_s_08_5_direct_edit_beside_a_pending_request` | Passed · 2026-10-14 · auto |

### S-09 The calendar and the old Attendance page show pending (P1)
As an HR staff member or admin, I want to see that a day is waiting, so that I don't enter it twice or think it was lost.
Independent test: after S-05.1, the E1/Dn cell says pending, not absent.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-09.1 | happy | Given S-05.1, when H opens the calendar, then the E1/Dn cell is marked "pending" and its modal shows the requested times, reason, requester and when asked. Totals show nothing worked | `ApprovalsPageTest::test_s_09_1_cell_is_marked_pending` | Passed · 2026-10-14 · auto |
| S-09.2 | happy | Given a pending change on Dr, then the cell shows the official values with a "pending" marker, and the modal shows requested next to official. Totals are those of the day row | `ApprovalsPageTest::test_s_09_2_official_values_with_a_marker` | Passed · 2026-10-14 · auto |
| S-09.3 | negative | Given the pending day, then calendar summaries (present and absent counts, per-employee and per-day totals) and any calendar print or export count Dn as absent as before, and Dr with its official values | `ApprovalsPageTest::test_s_09_3_summaries_count_official_days_only` | Passed · 2026-10-14 · auto |
| S-09.4 | negative | Given E1 has a pending request, then E2's cells and E1's other dates are unmarked | `ApprovalsPageTest::test_s_09_4_only_the_requested_day_is_marked` | Passed · 2026-10-14 · auto |
| S-09.5 | happy | Given the same pending day, when H opens the old Attendance page (`/attendance`), then the row for E1/Dn or Dr is marked pending and keeps its official values. Its CSV export and print view contain official values only | `OldAttendancePageTest::test_s_09_5_old_page_marks_pending_rows` | Passed · 2026-10-14 · auto |
| S-09.6 | edge | Given the page is reloaded, then the pending state comes from the server and not from the page's script | `ApprovalsPageTest::test_s_09_6_pending_state_comes_from_the_server` | Passed · 2026-10-14 · auto |
| S-09.7 | edge | Given a request is approved, withdrawn or rejected, then after a reload the pending marker is gone; approved shows the real times, rejected shows "Rejected: <reason>" (S-13.2) | `ApprovalsPageTest::test_s_09_7_marker_goes_after_a_decision` | Passed · 2026-10-14 · auto |
| S-09.8 | edge | Given a request made at 23:30 Manila time, then it is on the work date asked for (not the `created_at` date), and "asked at" shows Manila time | `ApprovalsPageTest::test_s_09_8_late_evening_request` | Passed · 2026-10-14 · auto |
| S-09.9 | edge | Given a phone width (390 × 844), then the pending marker is visible on the cell and in the modal, and the calendar is still usable | `ApprovalsPageTest::test_s_09_9_marker_for_a_phone` | Passed · 2026-10-14 · auto |
| S-09.10 | edge | Given a pending rest-day request on Dr, then the cell is marked pending, still shows the worked day with its official times, and is still counted as worked | `RequestOtherKindsTest::test_s_09_10_pending_rest_day_keeps_the_worked_day` | Passed · 2026-10-14 · auto |

### S-10 The CEO sees what is waiting (P1)
As the CEO, I want an "Approvals" link with a count and a list, so that I can see at a glance what needs me.
Independent test: with 3 entries pending, C sees "Approvals 3" and a list of 3 rows.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-10.1 | happy | Given 3 pending entries (3 employee-and-date pairs, one of them holding 2 fields), when C loads any page, then the nav shows "Approvals" with 3. The list shows, per entry, employee, date, the kind of change, the values put in with the official value next to them, the reason, who asked and when | `ApprovalsPageTest::test_s_10_1_nav_count_and_list` | Passed · 2026-10-14 · auto |
| S-10.2 | happy | Given C approves or rejects one entry, then on the next load the count is 2 and the row is gone from the pending list | `ApprovalsPageTest::test_s_10_2_count_follows_decisions` | Passed · 2026-10-14 · auto |
| S-10.3 | negative | Given H, H2 or M, then there is no Approvals link, and GET of the Approvals address returns 403. The nav does not leak the count | `ApprovalsPageTest::test_s_10_3_other_roles_see_nothing` | Passed · 2026-10-14 · auto |
| S-10.4 | negative | Given nothing pending, then the page says nothing is waiting and the nav shows no badge | `ApprovalsPageTest::test_s_10_4_nothing_waiting` | Passed · 2026-10-14 · auto |
| S-10.5 | edge | Given a feature permission is granted to hr_staff or admin for any nav link, then the Approvals link and address still stay CEO-only | `ApprovalsPageTest::test_s_10_5_feature_permissions_do_not_open_approvals` | Passed · 2026-10-14 · auto |
| S-10.6 | edge | Given enough entries to page, then the count is the total pending entries and not the page size. Withdrawn, approved and rejected are not counted. Entries on locked dates or deactivated employees are counted and flagged | `ApprovalsPageTest::test_s_10_6_count_is_the_total` | Passed · 2026-10-14 · auto |
| S-10.7 | edge | Given the filters (P2), then the list can be filtered by status (pending, approved, rejected, withdrawn), employee, requester, work-date range and kind of change. The default is pending, oldest first. A "decided" view shows who approved or rejected, when, and the CEO's reason | `ApprovalsPageTest::test_s_10_7_filters` | Passed · 2026-10-14 · auto |
| S-10.8 | edge | Given an employee name with Ñ or ñ, and a reason with `<script>` or a long unbroken word, then both show as plain text and the layout holds | `ApprovalsPageTest::test_s_10_8_text_is_escaped` | Passed · 2026-10-14 · auto |
| S-10.9 | edge | Given a phone width (390 × 844), then every row is readable and the approve and reject controls are reachable | `ApprovalsPageTest::test_s_10_9_entries_are_cards_with_controls` | Passed · 2026-10-14 · auto |
| S-10.10 | edge | Given a CEO who opens the page in a stale tab, then loading again shows current data, and a stale click is handled by S-16 | `ApprovalsPageTest::test_s_10_10_reload_shows_current_data` | Passed · 2026-10-14 · auto |
| S-10.11 | edge | Given an entry holding fields asked by H and H2, then the row shows each field with its own requester | `ApprovalsPageTest::test_s_10_11_each_field_with_its_requester` | Passed · 2026-10-14 · auto |

### S-11 The CEO approves a request (P1)
As the CEO, I want approval to make the change real exactly as if I had entered it, so that payroll and reports treat it like any other attendance.
Independent test: approve the S-05.1 request; E1/Dn has the same day row as if C had saved those times.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-11.1 | happy | Given the S-05.1 request, when C approves, then the day row exists with the four times, one override row per field with the requester's reason, and the totals (work, late, early, overtime minutes) equal those of a control employee and date where C entered the same times. The request is approved with approver C and the decision time, and H is still recorded as the requester. The calendar is no longer pending and payroll counts the day | `ApprovalFlowTest::test_s_11_1_approval_equals_a_direct_entry` | Passed · 2026-10-14 · auto |
| S-11.2 | happy | Given a time_in request on Dr, when approved, then only time_in changes, the override row has old and new value, and the other fields are untouched | `ApprovalFlowTest::test_s_11_2_only_the_requested_field_changes` | Passed · 2026-10-14 · auto |
| S-11.3 | happy | Given an overtime, SIL apply, SIL removal or shift request, when approved, then the same official data exists as the direct path creates, and the SIL balance moves by exactly one day | `RequestOtherKindsTest::test_s_11_3_other_kinds_equal_the_direct_path` | Passed · 2026-10-14 · auto |
| S-11.4 | happy | Given an approved request, then the day's history (modal and logs) shows who asked and who approved, with the reason | `ApprovalFlowTest::test_s_11_4_history_names_requester_and_approver` | Passed · 2026-10-14 · auto |
| S-11.5 | negative | Given the date is locked at approval time, then the approval is refused with the lock message, the request stays pending and nothing is written | `ApprovalFlowTest::test_s_11_5_locked_at_approval_time` | Passed · 2026-10-14 · auto |
| S-11.6 | negative | Given the approval fails halfway (the recompute throws), then nothing is half-written: no new day row, no override row, and the request is still pending with an error shown | `ApprovalFlowTest::test_s_11_6_failed_approval_writes_nothing` | Passed · 2026-10-14 · auto |
| S-11.7 | edge | Given an approved clear-time request, then the field becomes empty and the old value is in the log | `ApprovalFlowTest::test_s_11_7_approved_clear` | Passed · 2026-10-14 · auto |
| S-11.8 | edge | Given an approved day, when an import later brings punches for it, then the approved fields are kept as overrides (the same as a CEO-entered edit; S-02.1) | `ApprovalFlowTest::test_s_11_8_later_import_keeps_approved_fields` | Passed · 2026-10-14 · auto |
| S-11.9 | edge | Given a request that is already approved, rejected or withdrawn, when approval is attempted, then it is refused and nothing changes | `ApprovalFlowTest::test_s_11_9_decided_request_cannot_be_approved` | Passed · 2026-10-14 · auto |
| S-11.10 | edge | Given an entry with 3 pending fields, when C approves, then all 3 are applied together; none is applied alone | `ApprovalFlowTest::test_s_11_10_entry_is_applied_together` | Passed · 2026-10-14 · auto |
| S-11.11 | edge | Given an entry with a field asked by H and a field asked by H2, when C approves, then each override row and the history name the right requester for that field and C as approver | `ApprovalFlowTest::test_s_11_11_shared_entry_names_each_requester` | Passed · 2026-10-14 · auto |

### S-12 The CEO approves several at once (P2)
As the CEO, I want to approve many entries in one go, so that a pay period's batch doesn't take 40 clicks.
Independent test: select 3 pending entries and approve; all 3 are approved.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-12.1 | happy | Given 3 entries selected, when C approves, then all 3 are applied and approved and the reply says "3 approved" | `BulkAndBatchTest::test_s_12_1_approve_three_selected` | Passed · 2026-10-14 · auto |
| S-12.2 | negative | Given 1 of the 3 is on a locked date, then it stays pending with the reason named, the other 2 are applied, and the reply says "2 approved, 1 not approved: date locked" | `BulkAndBatchTest::test_s_12_2_one_of_three_is_locked` | Passed · 2026-10-14 · auto |
| S-12.3 | edge | Given one entry was withdrawn after the list loaded, then it is skipped with a message and the rest are applied | `BulkAndBatchTest::test_s_12_3_one_was_withdrawn_meanwhile` | Passed · 2026-10-14 · auto |
| S-12.4 | edge | Given a filter is on, then "approve all" affects only the filtered rows | `BulkAndBatchTest::test_s_12_4_selection_follows_the_filter` | Passed · 2026-10-14 · auto |
| S-12.5 | edge | Given 50 selected with one failing (a SIL request whose balance is gone), then each is isolated: one failure does not undo the others, and the page does not time out | `BulkAndBatchTest::test_s_12_5_fifty_with_one_failing` | Passed · 2026-10-14 · auto |
| S-12.6 | edge | Given reject selected, then one reason is required and is stored on each rejected entry | `BulkAndBatchTest::test_s_12_6_reject_selected` | Passed · 2026-10-14 · auto |
| S-12.7 | edge | Given a batch made by a bulk action (S-22), when C approves the batch in one action, then each of its requests is applied or, if blocked (lock, rest day, SIL), stays pending with its reason named, and the others are applied | `BulkAndBatchTest::test_s_12_7_batch_with_blocked_requests` | Passed · 2026-10-14 · auto |

### S-13 The CEO rejects with a reason (P1)
As the CEO, I want to reject with a reason the requester can read, so that they know what to fix.
Independent test: reject a request with "Walang katibayan"; the day is as before and H sees the reason.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-13.1 | happy | Given a pending entry, when C rejects with a reason, then the status is rejected with the reason, rejecter C and time, and the day (day row, values, `updated_at`, overrides, rest day, SIL, shift) and payroll are unchanged | `ApprovalFlowTest::test_s_13_1_rejection_changes_nothing_official` | Passed · 2026-10-14 · auto |
| S-13.2 | happy | Given S-13.1, when H opens the day in the calendar, then the modal shows "Rejected: Walang katibayan" with the values H asked for. Nothing is sent by email or chat | `ApprovalFlowTest::test_s_13_2_requester_sees_the_rejection` | Passed · 2026-10-14 · auto |
| S-13.3 | negative | Given the reason is empty or fewer than 3 characters, then the rejection is refused and the request stays pending | `ApprovalFlowTest::test_s_13_3_rejection_needs_a_reason` | Passed · 2026-10-14 · auto |
| S-13.4 | negative | Given a request already decided or withdrawn, then reject is refused with no change | `ApprovalFlowTest::test_s_13_4_decided_request_cannot_be_rejected` | Passed · 2026-10-14 · auto |
| S-13.5 | edge | Given an entry on a locked date, then C can still reject it | `ApprovalFlowTest::test_s_13_5_reject_on_a_locked_date` | Passed · 2026-10-14 · auto |
| S-13.6 | edge | Given a rejected request, then H can ask again on the same employee, date and field. The rejected one stays in history, the new one replaces the "Rejected" notice in the modal, and the count has only the new one | `ApprovalFlowTest::test_s_13_6_ask_again_after_a_rejection` | Passed · 2026-10-14 · auto |
| S-13.7 | edge | Given a 501-character reason, then it is refused. Given a Filipino and non-ASCII reason, then it is shown intact to H | `ApprovalFlowTest::test_s_13_7_rejection_reason_length_and_characters` | Passed · 2026-10-14 · auto |
| S-13.8 | edge | Given C rejects an entry with 2 fields, then both fields are rejected with the same reason | `ApprovalFlowTest::test_s_13_8_reject_covers_every_field` | Passed · 2026-10-14 · auto |
| S-13.9 | edge | Given the requester's rejected items badge (P2), when H has 2 rejected entries not yet replaced, then the calendar page shows a small list of them with date, employee and reason, and an item leaves the list once a new request replaces it | `ApprovalsPageTest::test_s_13_9_rejected_list_of_the_requester` | Passed · 2026-10-14 · auto |

### S-14 The requester changes or withdraws a pending request (P1)
As the person who asked, I want to correct or take back my request while it waits, so that a typo doesn't need the CEO to reject it.
Independent test: H withdraws; the request leaves the list and the count.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-14.1 | happy | Given H's pending request, when H changes the times and reason, then the same request holds the new values (same id), "changed at" is set, and the CEO's list shows the new values | `ApprovalFlowTest::test_s_14_1_requester_changes_the_request` | Passed · 2026-10-14 · auto |
| S-14.2 | happy | Given H's pending request, when H withdraws, then the status is withdrawn, the request is out of the list and the count, and the cell returns to absent or to Dr's official values. No day row exists | `ApprovalFlowTest::test_s_14_2_requester_withdraws` | Passed · 2026-10-14 · auto |
| S-14.3 | negative | Given H's request, when H2 or M tries to change or withdraw it (including by posting the id directly), then 403 and no change. In an entry holding fields of H and H2, each can change or withdraw only their own fields | `ApprovalFlowTest::test_s_14_3_other_users_cannot_change_or_withdraw` | Passed · 2026-10-14 · auto |
| S-14.4 | negative | Given the request is approved or rejected, when H changes or withdraws it, then it is refused ("already decided") | `ApprovalFlowTest::test_s_14_4_decided_request_cannot_be_changed` | Passed · 2026-10-14 · auto |
| S-14.5 | negative | Given the date got locked, when H changes the request, then it is refused (423); when H withdraws it, then it is withdrawn | `ApprovalFlowTest::test_s_14_5_locked_date_allows_withdraw_only` | Passed · 2026-10-14 · auto |
| S-14.6 | negative | Given an edit with the reason missing or a bad time, then 422 and the request is unchanged | `ApprovalFlowTest::test_s_14_6_bad_change_is_refused` | Passed · 2026-10-14 · auto |
| S-14.7 | edge | Given an edit whose values equal the official ones, then it is refused as "No changes" and the request is kept as it was | `ApprovalFlowTest::test_s_14_7_change_to_the_official_value` | Passed · 2026-10-14 · auto |
| S-14.8 | edge | Given withdraw sent twice, then no error 500, and the final state is withdrawn | `ApprovalFlowTest::test_s_14_8_double_withdraw` | Passed · 2026-10-14 · auto |
| S-14.9 | edge | Given a withdrawn request, then H can ask again later as a new request | `ApprovalFlowTest::test_s_14_9_ask_again_after_a_withdrawal` | Passed · 2026-10-14 · auto |
| S-14.10 | edge | Given C wants a pending request gone, then C cannot withdraw it (only reject it) | `ApprovalFlowTest::test_s_14_10_ceo_cannot_withdraw` | Passed · 2026-10-14 · auto |

### S-15 A second request for the same employee, date and field (P1)
As the CEO, I want only one pending request per employee, date and field, so that I never approve two conflicting values.
Independent test: H asks twice for E1/Dn time_in; one pending request remains.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-15.1 | happy | Given H has a pending time_in for E1/Dn, when H asks again with a new value, then that request is updated (not duplicated), one remains pending, and the count is 1 | `RequestTimesTest::test_s_15_1_same_requester_updates_the_request` | Passed · 2026-10-14 · auto |
| S-15.2 | negative | Given H has a pending time_in for E1/Dn, when H2 or M asks for the same employee, date and field, then it is refused with a message naming H as the one who asked, and the one request remains unchanged | `RequestTimesTest::test_s_15_2_another_requester_is_refused` | Passed · 2026-10-14 · auto |
| S-15.3 | edge | Given a pending time_in for E1/Dn, when H2 asks for time_out, SIL, overtime or a rest day on the same day, then it is accepted as a request of its own and the Approvals page shows ONE entry for E1/Dn holding both fields, each with its requester | `RequestTimesTest::test_s_15_3_other_field_same_day_is_accepted` | Passed · 2026-10-14 · auto |
| S-15.4 | edge | Given the same field on another date, or another employee, then it is independent and a separate entry | `RequestTimesTest::test_s_15_4_other_date_or_employee_is_independent` | Passed · 2026-10-14 · auto |
| S-15.5 | edge | Given the first one was approved, rejected or withdrawn, then a new request for the same field is allowed and is compared with the new official value | `RequestTimesTest::test_s_15_5_new_request_after_a_decision` | Passed · 2026-10-14 · auto |
| S-15.6 | edge | Given two identical requests arrive at the same instant, then one row exists (a database-level guard) and the other gets the "already pending" reply | `RequestTimesTest::test_s_15_6_database_refuses_a_second_pending_row` | Passed · 2026-10-14 · auto |

### S-16 Two people acting at once (P1)
As the CEO, I want a double click or two tabs never to apply a change twice or halfway, so that the records stay right.
Independent test: approve the same entry twice at the same instant; the data is applied once.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-16.1 | negative | Given two approvals of the same entry at once (double click, two tabs), then one set of rows exists (one day row, one override per field, one SIL application), and the second gets "already approved", not an error 500 | `ApprovalFlowTest::test_s_16_1_double_approval_applies_once`, `ApprovalFlowTest::test_s_16_1_approval_locks_its_rows` | Passed · 2026-10-14 · auto |
| S-16.2 | edge | Given approve and reject at once, then exactly one wins, and the final data matches the status: approved means data written; rejected means none | `ApprovalFlowTest::test_s_16_2_approve_and_reject_at_once` | Passed · 2026-10-14 · auto |
| S-16.3 | edge | Given C opened values V1 and H changes the request to V2 before C approves, then the approval is refused with "request changed, review again". V2 is never applied unseen | `ApprovalFlowTest::test_s_16_3_changed_request_needs_a_new_look` | Passed · 2026-10-14 · auto |
| S-16.4 | edge | Given H withdraws while C approves, then one wins, and no data is written if withdrawn won | `ApprovalFlowTest::test_s_16_4_withdraw_while_approving` | Passed · 2026-10-14 · auto |
| S-16.5 | edge | Given C is approving an entry holding time_in and H2 adds time_out to it at the same moment, then the approval is refused with "request changed, review again", nothing is written, and the entry then holds both fields | `ApprovalFlowTest::test_s_16_5_field_added_while_approving` | Passed · 2026-10-14 · auto |
| S-16.6 | edge | Given an approval and C's direct creation of the same E1/Dn day row at once, then one day row only exists, both actions are logged, and no duplicate-key error is shown | `ApprovalFlowTest::test_s_16_6_approval_meets_a_fresh_day_row` | Passed · 2026-10-14 · auto |

### S-17 The day changes between request and approval (P1)
As the CEO, I want a stale request to be flagged or blocked, so that I never approve something that no longer fits the day.
Independent test: a request on E1/Dn; the date becomes a rest day; Approve is blocked and Reject works.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-17.1 | happy | Given a pending four-times request on Dn, when an import brings punches for Dn (a day row appears with times), then the requests whose field now differs stay pending and the entry shows "was X, now Y" for each; a requested field the punches already equal (here lunch out and lunch in) closes itself as "no longer needed" when the page loads (spec 007). Approve needs the CEO's confirmation, and then the requested fields are applied to the existing day row (still one day row) | `ApprovalFlowTest::test_s_17_1_import_after_the_request` | Passed · 2026-10-30 · auto |
| S-17.2 | edge | Given C changed the same field directly after the request, then the entry shows "was X, now Y" (X is the value the request saw), Approve needs the CEO's confirmation, and the log then shows C's edit first, then the approved change | `ApprovalFlowTest::test_s_17_2_ceo_edit_of_the_same_field` | Passed · 2026-10-14 · auto |
| S-17.3 | edge | Given C changed a different field than the request holds, or the requested field's official value is still what the request saw, then no confirmation is asked and approval applies the request's own fields only | `ApprovalFlowTest::test_s_17_3_ceo_edit_of_another_field` | Passed · 2026-10-14 · auto |
| S-17.4 | negative | Given the date became a rest day, then Approve is blocked ("now a rest day"), Reject works, and nothing is written. If the rest day is undone, Approve works again | `RequestOtherKindsTest::test_s_17_4_became_a_rest_day` | Passed · 2026-10-14 · auto |
| S-17.5 | negative | Given SIL was applied for that date, then Approve of a times request is blocked, Reject works, and nothing is written. If the SIL is removed, Approve works again | `RequestOtherKindsTest::test_s_17_5_sil_applied_meanwhile` | Passed · 2026-10-14 · auto |
| S-17.6 | negative | Given E1 was deactivated, then the request stays visible with the flag "Inactive employee", and both Approve and Reject work, as the CEO can also correct the day of an inactive employee directly (the last days of someone who left are corrected for final pay). There is no payroll effect: payroll leaves inactive employees out | `ApprovalFlowTest::test_s_17_6_deactivated_employee_is_flagged` | Passed · 2026-10-14 · auto |
| S-17.7 | edge | Given E1's shift for the date changed meanwhile, then approval writes the requested times and recomputes with the current shift | `ApprovalFlowTest::test_s_17_7_shift_changed_meanwhile` | Passed · 2026-10-14 · auto |
| S-17.8 | edge | Given a CEO force-recompute of the range, then pending requests are neither applied nor deleted | `ApprovalFlowTest::test_s_17_8_force_recompute_leaves_requests_alone` | Passed · 2026-10-14 · auto |
| S-17.9 | edge | Given a SIL request and E1's balance reached 0, then Approve is refused with the reason and nothing is written; given the date became SIL since, then Approve closes the request as "no longer needed" and writes nothing (spec 007) | `RequestOtherKindsTest::test_s_17_9_sil_request_after_the_balance_changed` | Passed · 2026-10-30 · auto |
| S-17.10 | edge | Given a pending rest-day request on Dr and the day row is then removed or changed, then approval still applies the rest-day change as the request saw it, and is refused only by the blocks above | `RequestOtherKindsTest::test_s_17_10_rest_day_request_after_the_day_row_changed` | Passed · 2026-10-14 · auto |

### S-18 Locked dates (P1)
As the CEO, I want a locked date to take no request and no approval, so that closed pay periods stay closed.
Independent test: a locked date takes no request, and a request pending when the date gets locked cannot be approved.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-18.1 | negative | Given Dl, when H or M uses any entry point (`saveAll`, `overrideTime`, `createDay`, `fillFromShift`, `assignShiftForDate`, `applySil`, `removeSil`, `toggleDayOff`, bulk, and the old Attendance page; S-20), then 423 and no request, no day row, no log | `LocksTest::test_s_18_1_every_entry_point_refuses_a_locked_date` | Passed · 2026-10-14 · auto |
| S-18.2 | negative | Given a request pending and the date is then locked (single lock or range lock), when C approves, then it is refused with the lock message, nothing is written, and the request stays pending and is flagged "date locked" in the list and on the cell | `LocksTest::test_s_18_2_locked_after_the_request` | Passed · 2026-10-14 · auto |
| S-18.3 | negative | Given the same, then Reject still works, H can withdraw it, and H cannot change it (423) | `LocksTest::test_s_18_3_reject_and_withdraw_on_a_locked_date` | Passed · 2026-10-14 · auto |
| S-18.4 | edge | Given the date is unlocked again, then the request is approvable again | `LocksTest::test_s_18_4_unlock_makes_it_approvable` | Passed · 2026-10-14 · auto |
| S-18.5 | negative | Given a payroll run is finalized while the request is pending (finalizing auto-locks its dates), then it behaves as S-18.2, the finalized payroll has the day as it was, and the request is still counted | `LocksTest::test_s_18_5_finalized_run_locks_the_request` | Passed · 2026-10-14 · auto |
| S-18.6 | edge | Given a pending request inside the period of the run being finalized, when C opens the finalize screen, then it shows a warning with the count of pending requests in the period, and finalizing is not blocked | `LocksTest::test_s_18_6_finalize_warns_and_does_not_block` | Passed · 2026-10-14 · auto |
| S-18.7 | edge | Given a lock on another date or another period, then it does not block this one. The lock check uses the work date, not today | `LocksTest::test_s_18_7_only_the_work_date_counts` | Passed · 2026-10-14 · auto |

### S-19 Only the CEO approves, on the server (P1)
As the owner, I want approval to be impossible for anyone but the CEO, even by calling the address directly, so that the control cannot be bypassed.
Independent test: H posts an approval for a real request id; it is refused and nothing changes.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-19.1 | negative | Given a real pending id, when H posts to approve, reject or approve-several, then 403, the request stays pending and nothing is written | `ApprovalSecurityTest::test_s_19_1_hr_staff_cannot_approve` | Passed · 2026-10-14 · auto |
| S-19.2 | negative | Given the same with admin M, then 403 with no change | `ApprovalSecurityTest::test_s_19_2_admin_cannot_approve` | Passed · 2026-10-14 · auto |
| S-19.3 | negative | Given a signed-out caller, then redirect to sign-in (or 401) with no change | `ApprovalSecurityTest::test_s_19_3_signed_out_caller` | Passed · 2026-10-14 · auto |
| S-19.4 | negative | Given H posts a save with extra fields such as `status=approved`, `approved_by` or `requested_by`, then they are ignored: the request is pending and its requester is the signed-in user | `RequestTimesTest::test_s_19_4_extra_fields_are_ignored` | Passed · 2026-10-14 · auto |
| S-19.5 | negative | Given the user who made a request (or one field of an entry) is now a CEO (role changed), when they approve an entry containing it, then it is refused ("cannot approve your own request"), and another CEO can approve it | `ApprovalSecurityTest::test_s_19_5_own_request_cannot_be_approved` | Passed · 2026-10-14 · auto |
| S-19.6 | negative | Given H posts approve or reject with an id that does not exist, then 403, the same answer as for an id that exists (the address does not tell which ids exist), and no error 500. Given C does, then 404 | `ApprovalSecurityTest::test_s_19_6_unknown_id` | Passed · 2026-10-14 · auto |
| S-19.7 | edge | Given a user deactivated or demoted after their page loaded, when they click, then the role is checked at that call and the action is refused | `ApprovalSecurityTest::test_s_19_7_role_is_read_at_each_call` | Passed · 2026-10-14 · auto |
| S-19.8 | edge | Given a request without the CSRF token, then 419 and no change | `ApprovalSecurityTest::test_s_19_8_missing_csrf_token` | Passed · 2026-10-14 · auto |

### S-20 The old Attendance page follows the same rule (P1)
As the owner, I want every way to change a day to follow one rule, so that no old screen is a back door.
Independent test: H posts `/attendance/override` on Dr; a pending request exists and the day row is unchanged.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-20.1 | happy | Given Dr, when H or M posts a time field to `/attendance/override`, then a pending request is created, and the day row, its totals and the override table are unchanged (today it saves directly with no role check) | `OldAttendancePageTest::test_s_20_1_old_page_time_edit_becomes_a_request` | Passed · 2026-10-14 · auto |
| S-20.2 | happy | Given H posts `shift_id` there, then it is a pending request and the day's shift is unchanged | `OldAttendancePageTest::test_s_20_2_old_page_shift_becomes_a_request` | Passed · 2026-10-14 · auto |
| S-20.3 | happy | Given C posts there, then a time is saved and recomputed as today, with an override row, and a shift is saved as a single-day shift assignment and the day is recomputed with it | `OldAttendancePageTest::test_s_20_3_old_page_ceo_is_direct` | Passed · 2026-10-14 · auto |
| S-20.4 | negative | Given Dl, when anyone including C posts `/attendance/override` or `/attendance/create-day`, then 423 and nothing is written (today neither route checks the lock) | `OldAttendancePageTest::test_s_20_4_old_page_checks_the_lock` | Passed · 2026-10-14 · auto |
| S-20.5 | negative | Given H posts the old `createDay`, then no day row and no blank request is created | `OldAttendancePageTest::test_s_20_5_old_create_day_by_hr_staff` | Passed · 2026-10-14 · auto |
| S-20.6 | edge | Given a clear (empty `new_value`) by H there, then the request is "clear", the same as S-05.12 | `OldAttendancePageTest::test_s_20_6_old_page_clear` | Passed · 2026-10-14 · auto |
| S-20.7 | edge | Given the same change by H through the calendar and through the old page, then the requests are the same in kind and fields, so the Approvals page shows no difference | `OldAttendancePageTest::test_s_20_7_same_request_from_both_pages` | Passed · 2026-10-14 · auto |
| S-20.8 | edge | Given H runs "Compute" on a range with pending requests, then they are neither applied nor deleted, and existing overrides are still kept | `OldAttendancePageTest::test_s_20_8_compute_leaves_requests_and_overrides` | Passed · 2026-10-14 · auto |

### S-21 Nothing pending reaches payroll or totals (P1)
As the owner, I want unapproved changes to have no effect on pay, so that a request can never pay or dock anyone.
Independent test: with a pending request on E1/Dn, E1's payroll equals the baseline to the centavo.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-21.1 | happy | Given a pending times request on Dn, when payroll is computed for the period, then E1's days, gross and deductions equal the baseline, and Dn counts as absent as before | `RequestTimesTest::test_s_21_1_pending_request_does_not_reach_payroll` | Passed · 2026-10-14 · auto |
| S-21.2 | happy | Given a pending change on Dr, then payroll uses Dr's official times | `RequestTimesTest::test_s_21_2_pending_change_keeps_official_times_in_payroll` | Passed · 2026-10-14 · auto |
| S-21.3 | happy | Given a pending overtime, SIL, shift or rest-day request, then OT pay, SIL pay, late and OT minutes and the worked days equal the baseline, and the SIL balance is unchanged | `PayrollUntouchedTest::test_s_21_3_other_kinds_pending_do_not_reach_payroll` | Passed · 2026-10-14 · auto |
| S-21.4 | negative | Given the request is rejected or withdrawn, then payroll equals the baseline | `PayrollUntouchedTest::test_s_21_4_rejected_or_withdrawn_does_not_reach_payroll` | Passed · 2026-10-14 · auto |
| S-21.5 | happy | Given the request is approved, then payroll equals what it would be if C had entered the same values directly (same amounts to the centavo) | `PayrollUntouchedTest::test_s_21_5_approved_equals_direct_in_payroll` | Passed · 2026-10-14 · auto |
| S-21.6 | negative | Given any number of pending requests, then E1's `attendance_days` row count for the period is unchanged, and so are the dashboard and report counts and every CSV and PDF export | `PayrollUntouchedTest::test_s_21_6_counts_and_exports_are_unchanged` | Passed · 2026-10-14 · auto |
| S-21.7 | edge | Given an approved overtime of 0.333 and of 1.25 hours, then the minutes are 20 and 75 (the same rounding as the direct path), and payroll matches the direct path | `PayrollUntouchedTest::test_s_21_7_overtime_rounding` | Passed · 2026-10-14 · auto |
| S-21.8 | edge | Given a draft payroll run exists, when a request is approved afterwards, then the run changes only when it is recalculated, as after a CEO edit today | `PayrollUntouchedTest::test_s_21_8_draft_run_changes_on_recalculation_only` | Passed · 2026-10-14 · auto |

### S-22 Bulk actions by HR or admin (P1)
As an HR staff member, I want a bulk action to send requests rather than change the days, so that many employees can be fixed without bypassing the CEO.
Independent test: H runs bulk set times on 3 employees on Dn; 3 pending requests exist and no day rows.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-22.1 | happy | Given 3 employees on Dn, when H runs "set times" with a reason, then 3 pending requests exist (one per employee and date), grouped as one batch, the reply says "3 requests sent for approval", and there are no day rows | `BulkAndBatchTest::test_s_22_1_bulk_set_times_sends_requests` | Passed · 2026-10-14 · auto |
| S-22.2 | happy | Given H runs "fill from shift", then it is the same, with the shift times as at the request. An employee with no shift is listed as a failure as today | `BulkAndBatchTest::test_s_22_2_bulk_fill_from_shift` | Passed · 2026-10-14 · auto |
| S-22.3 | happy | Given H runs a bulk add day off, cancel day off or remove override, then each employee-day follows the rest day rule: direct when that employee has no day row on the date, a pending request in the batch when there is one (S-07.9) | `BulkAndBatchTest::test_s_22_3_bulk_rest_day_follows_the_rule` | Passed · 2026-10-14 · auto |
| S-22.4 | negative | Given Dl, then the whole action is refused (423) and no request is created | `BulkAndBatchTest::test_s_22_4_bulk_on_a_locked_date` | Passed · 2026-10-14 · auto |
| S-22.5 | negative | Given H runs a bulk set times on days that already have day rows, then no day row changes (today `bulkApplySetTimes` writes directly on existing rows) and requests are created | `BulkAndBatchTest::test_s_22_5_bulk_set_times_on_existing_rows` | Passed · 2026-10-14 · auto |
| S-22.6 | edge | Given one employee already has a pending request for that field from the same person, then it is updated (S-15.1); if it is from another person, that employee is reported as a failure naming who asked; the others get requests. The reply lists both counts | `BulkAndBatchTest::test_s_22_6_bulk_meets_existing_requests` | Passed · 2026-10-14 · auto |
| S-22.7 | edge | Given C runs the bulk action, then it is direct as today and no requests exist | `BulkAndBatchTest::test_s_22_7_ceo_bulk_is_direct` | Passed · 2026-10-14 · auto |
| S-22.8 | edge | Given an empty employee list, or `set_times` with no times, then nothing is created (422 or no change) | `BulkAndBatchTest::test_s_22_8_empty_bulk` | Passed · 2026-10-14 · auto |
| S-22.9 | edge | Given 100 employees, then it completes without timing out, and a failure on one does not drop the rest | `BulkAndBatchTest::test_s_22_9_hundred_employees` | Passed · 2026-10-14 · auto |
| S-22.10 | edge | Given a batch from H, then C can approve the whole batch with one action or each request separately (S-12.7) | `BulkAndBatchTest::test_s_22_10_batch_is_approved_in_one_action` | Passed · 2026-10-14 · auto |
| S-22.11 | edge | Given a batch from H, when C rejects the whole batch with one reason, then every request in it is rejected with that reason and no day changes | `BulkAndBatchTest::test_s_22_11_batch_is_rejected_with_one_reason` | Passed · 2026-10-14 · auto |

## Slice 004 – Approving a change rechecks the day

Slice 004: 7 passed, 0 failed, 0 blocked, 0 skipped, 0 not run

Tests live in `tests/Feature/Approvals/RecheckAfterApproveTest.php`, with the fixtures of Slice 002. Dx is an unlocked weekday on which the import made E1's (or E2's) day from two punches, 07:58 and 12:02: needs review, note "Lunch punches could not be inferred. No Time Out recorded (missed timeout)."

### S-004 Approving a change rechecks the day (P1)
As the CEO, I want a day I approved complete to stop showing as a problem, so that the Attendance Viewer and the Calendar show only real problems.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-004.1 | happy | Given Dx flagged "No Time Out", when C approves H's time out and lunch pair, then needs review is 0 and the note no longer names the fixed problems; a day the approval creates from nothing is checked the same way | `RecheckAfterApproveTest::test_s_004_1_completed_day_is_no_longer_flagged`, `test_s_004_1_day_created_by_the_approval_is_checked` | Passed · 2026-10-29 · auto |
| S-004.2 | edge | Given Dx, when C approves only a lunch in, then needs review stays 1 and the note names only the missing time out; approved times out of order, or a cleared time in, stay flagged with that problem | `RecheckAfterApproveTest::test_s_004_2_still_incomplete_day_names_only_what_is_left`, `test_s_004_2_anomalies_in_approved_times_stay_flagged` | Passed · 2026-10-29 · auto |
| S-004.3 | happy | Given two flagged days, when C approves them with "approve selected" or as a batch, then each day is rechecked once | `RecheckAfterApproveTest::test_s_004_3_approve_several_rechecks_each_day_once`, `test_s_004_3_batch_approve_rechecks_each_day_once` | Passed · 2026-10-29 · auto |
| S-004.4 | edge | Given approved overtime, a CEO override, a rest day on the date and SIL on another date, when an approval rechecks the day, then all of them are as before, and no stored time moves to another slot (a shift approval included) | `RecheckAfterApproveTest::test_s_004_4_recheck_keeps_what_was_decided_before`, `test_s_004_4_shift_approval_keeps_the_stored_times` | Passed · 2026-10-29 · auto |
| S-004.5 | happy | Given a day approved before this change (complete, still flagged), then `attendance:recheck-approved` lists it with before → after and writes nothing; `--apply` writes; a second `--apply` changes nothing; a locked date is skipped; a day whose shift or minutes would also change is held unless `--allow-other-changes` | `RecheckAfterApproveTest::test_s_004_5_command_fixes_days_approved_before`, `test_s_004_5_command_skips_locked_dates`, `test_s_004_5_command_holds_days_whose_pay_would_change` | Passed · 2026-10-29 · auto |
| S-004.6 | negative | Given a pending entry, when C rejects it, then the day is not rechecked and nothing official changes | `RecheckAfterApproveTest::test_s_004_6_reject_does_not_recheck` | Passed · 2026-10-29 · auto |
| S-004.7 | negative | Given the existing suites, then they pass (one Slice 002 test changed on purpose: an approved day's note is now the recheck's) | full suite | Passed · 2026-10-29 · auto |

## Slice 005 – The approval preview

Slice 005: 9 passed, 0 failed, 0 blocked, 0 skipped, 0 not run

Tests live in `tests/Feature/Approvals/ApprovalPreviewTest.php`, with the fixtures of Slice 002. Each case makes its day from invented punches, has H ask for the change, and reads the preview the CEO's `/approvals` page shows beside the entry ("Now" and "If approved").

### S-005 The CEO sees the whole day as it will be, before approving (P1)
As the CEO, I want to see the employee's day as Approve would save it, beside the request, so that I decide with the hours, the lunch, late, undertime, overtime and any remaining problem in view.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-005.1 | happy | Given a day with time in and the lunch pair but no time out (needs review), when H asks for time out 17:00, then the preview shows Now: no time out, 4 h worked, needs review Yes; If approved: 5:00 PM, 8 h, needs review No, the changed lines highlighted | `ApprovalPreviewTest::test_s_005_1_missed_timeout_shows_the_completed_day` | Passed · 2026-10-29 · auto |
| S-005.2 | happy | Given lunch back at 2:00 PM, when H asks for lunch in 13:00, then the preview shows lunch 2 h → 1 h, worked 7 h → 8 h, late 1 h → 0; a lunch still longer than the shift's, lunch in before lunch out, time out before time in, a missing punch and hours under the shift are warned in one line each | `test_s_005_2_lunch_fix_shows_the_new_lunch_length_and_hours`, `test_s_005_2_lunch_still_too_long_is_warned`, `test_s_005_2_other_unusual_results_are_warned` | Passed · 2026-10-29 · auto |
| S-005.3 | happy | Given 2 h of overtime worked, when H asks for 1.5 h approved, then the preview shows approved overtime "not set" → 1 h 30 m and warns that 30 m is not covered; asked for 2 h, no warning | `test_s_005_3_overtime_request_shows_the_approved_overtime` | Passed · 2026-10-29 · auto |
| S-005.4 | happy | Given Dr (8:03 AM to 5:00 PM on the 8-to-5 shift), when H asks for the 9-to-6 shift, then the preview shows the shift line Day shift → Late shift, late 3 m → 0, undertime 0 → 2 h, worked 7 h 57 m → 6 h | `test_s_005_4_shift_change_shows_the_new_shift_and_recomputed_late_and_undertime` | Passed · 2026-10-29 · auto |
| S-005.5 | negative | Given seven entries of every kind (times, a new day, overtime, a shift from the calendar, a rest day, SIL, a lunch fix), when C loads the page twice, then every row of every table (and the id counters) is unchanged, no job is queued, no transaction is left open, and every entry has its preview; a SIL entry whose employee has no balance row creates none; a dry run whose transaction the database ended runs no further statement | `test_s_005_5_rendering_the_page_writes_nothing`, `test_s_005_5_a_sil_entry_without_a_balance_row_writes_none`, `test_s_005_5_a_dry_run_that_lost_its_transaction_runs_nothing_more` | Passed · 2026-10-29 · auto |
| S-005.6 | happy | Given entries for a missed time out, a lunch fix, overtime, a shift change (from Save All and from the calendar's shift control), a new day, a rest day and SIL, when C approves each, then the saved day equals the preview shown before, read both through the preview's code and straight from the table | `test_s_005_6_preview_equals_the_day_after_approval` | Passed · 2026-10-29 · auto |
| S-005.7 | negative | Given an employee without any shift, then the entry shows "Preview not available" with the reason and can still be approved; given an error inside the preview, then the page answers 200 with "Preview not available" and writes nothing | `test_s_005_7_preview_that_cannot_be_computed_says_so_and_can_still_be_approved`, `test_s_005_7_an_error_in_the_preview_never_breaks_the_page` | Passed · 2026-10-29 · auto |
| S-005.8 | edge | Given a 390 px screen, then the preview is below the request (request, preview, buttons) with no sideways scroll; from 992 px it is a right-hand column | `test_s_005_8_preview_sits_between_the_request_and_the_buttons` (order and CSS in the HTML); the rendered page at 390 px and 1280 px, see the result | Passed · 2026-10-29 · auto + browser |
| S-005.9 | negative | Given the existing suites, then they pass | full suite | Passed · 2026-10-29 · auto |

## Slice 006 – The device punches beside each approval

Slice 006: 9 passed, 0 failed, 0 blocked, 0 skipped, 0 not run

Tests live in `tests/Feature/Approvals/ApprovalPunchesTest.php`, with the fixtures of Slice 002. Each case stores invented punches in `attendance_logs`, has H ask for a change, and reads the "Device punches" block the CEO's `/approvals` page shows above the preview of each pending entry.

### S-006 The CEO sees what the device recorded, beside each request (P1)
As the CEO, I want to see the punches the biometric device stored for the employee on the request's date, and which of them the day uses, so that I judge a missed time out, a lunch fix or an overtime claim against what was actually recorded.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-006.1 | happy | Given punches on Dr stored out of order with seconds, at 00:00:00 and 23:59:59, and one at 23:59:59 the day before, then the block lists the seven punches of Dr in time order as "8:03 AM" (no seconds), and not the one of the day before | `test_s_006_1_shows_every_punch_of_the_date_in_time_order_in_12_hour_format` | Passed · 2026-10-30 · auto |
| S-006.2 | edge | Given a punch stored twice (same employee, same time), then it is listed once | `test_s_006_2_a_punch_stored_twice_is_listed_once` | Passed · 2026-10-30 · auto |
| S-006.3 | edge | Given next-day punches at 1:11 AM, 5:59:59 AM, 6:00 AM and 6:30 AM, then "Next day: 1:11 AM, 5:59 AM" is shown on its own muted line; the day's list, the preview and the day row are unchanged | `test_s_006_3_early_next_day_punches_are_on_their_own_line_and_change_nothing` | Passed · 2026-10-30 · auto |
| S-006.4 | negative | Given a date without punches, then "No punches from the device on this date." (and the next-day line if any) | `test_s_006_4_a_date_without_punches_says_so`, `test_s_006_4_a_date_without_punches_still_shows_the_next_day_line` | Passed · 2026-10-30 · auto |
| S-006.5 | happy | Given the day's official times now, then each punch equal to one (to the minute) carries its label ("8:03 AM · Time in"); an extra punch in between, and a punch the day no longer uses, are plain | `test_s_006_5_the_punches_the_day_uses_now_carry_their_label`, `test_s_006_5_labels_follow_the_official_times_now_not_the_punches` | Passed · 2026-10-30 · auto |
| S-006.6 | negative | Given E2 punches on the same date, then they never appear in E1's entry; given E1 entries on Dr and Dn, a 1:00 AM punch on Dn is Dr's next-day line and Dn's own first punch | `test_s_006_6_another_employees_punches_never_appear`, `test_s_006_6_entries_of_one_employee_on_consecutive_dates_each_get_their_own_punches` | Passed · 2026-10-30 · auto |
| S-006.7 | negative | Given pending entries with punches, when C loads the page twice, then every row of every table is unchanged and no job is queued; with 1 and with 31 entries, the page runs exactly one query on `attendance_logs` | `test_s_006_7_rendering_the_punches_writes_nothing`, `test_s_006_7_the_punch_queries_do_not_grow_with_the_groups` | Passed · 2026-10-30 · auto |
| S-006.8 | edge | Given a 390 px screen, then the block is below the request (request, punches, preview, buttons), wraps and sets no width | `test_s_006_8_the_block_sits_below_the_request_and_never_widens_the_page` (order and CSS in the HTML); the rendered page in a 390 px frame, see the result | Passed · 2026-10-30 · auto + browser (offline) |
| S-006.9 | negative | Given the existing suites, then they pass | full suite | Passed · 2026-10-30 · auto |

## Slice 007 – One approval per shift change; requests that change nothing close themselves

Slice 007: 29 passed, 0 failed, 0 blocked, 0 skipped, 0 not run (S-007.9 is checked in the HTML only; a real phone is an owner check)

Tests live in `tests/Feature/Approvals/ShiftGroupsTest.php`, `AutoCloseTest.php` and `AssignmentRequestTest.php`, on the fixtures of Slice 002 (`ShiftChangeTestCase` adds the same punched days for E1 and E2 on Dr, Dx and D9). "A change" means H asks the late shift for several dates through the calendar's per-date shift save, all in the minute 2026-10-26 4:44 PM.

### S-007 One approval per shift change, and requests that change nothing close themselves (P1)
As the CEO, I want a shift change asked for many days to be one card with one Approve, and requests that the official value already satisfies to leave my list by themselves, so that I decide each change once and only what still changes something.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-007.1 | happy | Given a change for E1 on Dr, Dx, D9, then one card shows "Shift → Late shift · Oct 05–Oct 09 (3 days)", "Oct 05, Oct 08–09", who asked and when, the reason, totals, and "See each day" with each day's preview; another requester, another minute, another value, or a day with a shift and a time stay per-day cards | `ShiftGroupsTest::test_s_007_1_grouping_keys` | Passed · 2026-10-30 · auto |
| S-007.1b | edge | Given shift requests with one batch id asked minutes apart, then one card; same minute but different batch ids, then not | `ShiftGroupsTest::test_s_007_1_batch_id_groups` | Passed · 2026-10-30 · auto |
| S-007.2 | happy | Given the same change for E1 and E2, when E1's card is approved at once and E2's days one by one, then shift assignments, day rows, payroll and the request log (approved by C, asked by H, applied row type) are the same | `ShiftGroupsTest::test_s_007_2_approve_all_equals_one_by_one` | Passed · 2026-10-30 · auto |
| S-007.2b | happy | When the card is rejected with a reason, then every day is rejected with it and nothing official changes | `ShiftGroupsTest::test_s_007_2_reject_all` | Passed · 2026-10-30 · auto |
| S-007.2c | edge | A day inside the card can be approved on its own; the card then lists the other days | `ShiftGroupsTest::test_s_007_2_per_day_inside_the_card` | Passed · 2026-10-30 · auto |
| S-007.3 | negative | Given Dx locked after asking, then the card says "Oct 08 cannot be approved: date locked"; Approve on the card approves Dr and D9 and leaves Dx pending | `ShiftGroupsTest::test_s_007_3_locked_day_inside_a_group` | Passed · 2026-10-30 · auto |
| S-007.4 | happy | Selecting a card selects its days for "Approve selected"; the badge shows 2 for one 3-day change and one times request; the count a decision returns counts cards | `ShiftGroupsTest::test_s_007_4_select_all_and_badge`, `test_s_007_4_json_count_is_cards` | Passed · 2026-10-30 · auto |
| S-007.5 | edge | Times asked for three days in one minute stay three per-day cards | `ShiftGroupsTest::test_s_007_5_other_kinds_are_not_grouped` | Passed · 2026-10-30 · auto |
| S-007.T | negative | A group post with a day of another requester, a times request, a made-up key, a day that gained a times request, or by HR, is refused and approves nothing; a change of 55 days is two cards (50 + 5) with one key | `ShiftGroupsTest::test_s_007_group_post_is_checked_on_the_server`, `test_s_007_group_day_must_be_alone`, `test_s_007_long_change_is_cut_into_cards` | Passed · 2026-10-30 · auto |
| S-007.6 | happy | Given the change, when the CEO adds the late shift on E1's profile, then loading Approvals closes the three requests as "no longer needed" (no decider, the requester and reason kept, the reason why), writes no single-day assignment, and lists them under the "No longer needed" tab; times the CEO already entered close too; a locked date stays pending; a request that still changes something stays | `AutoCloseTest::test_s_007_6_closes_on_page_load`, `test_s_007_6_same_edit_and_locked_date`, `test_s_007_6_different_value_stays` | Passed · 2026-10-30 · auto |
| S-007.7 | edge | Given the card loaded, when the CEO sets the late shift on Dx directly, then Approve on the card approves Dr and D9 and closes Dx without a second assignment | `AutoCloseTest::test_s_007_7_partial_group` | Passed · 2026-10-30 · auto |
| S-007.8 | happy | The closed days of E1, computed with the day shift, are recomputed with the late shift and end with the same minutes, needs-review and note as E2's approved days (Dr: late 0, undertime 60); a closed day on a locked date is left as it is | `AutoCloseTest::test_s_007_8_closed_days_are_recomputed`, `test_s_007_8_locked_day_is_not_recomputed` | Passed · 2026-10-30 · auto |
| S-007.9 | edge | Given a 390 px screen, then the card's header, dates, totals and buttons wrap and set no width | rendered HTML in `docs/specs/007-evidence/` (flex-wrap, fluid grid, no fixed width) | Passed · 2026-10-30 · HTML only |
| S-007.10 | happy | A profile assignment by HR (late shift from Oct 01, ongoing) is one pending card with the preview of the computed days in the range and the locked day named; nothing is in effect and the profile shows "Pending approval"; a reason is required | `AssignmentRequestTest::test_s_007_10_request_is_one_card`, `test_s_007_10_reason_required` | Passed · 2026-10-30 · auto |
| S-007.10b | happy | Approve creates the same rows as the CEO's direct path (schedule mode included, the previous open assignment closed) and recomputes the computed days except the locked one; reject changes nothing; the CEO's own addition is direct | `AssignmentRequestTest::test_s_007_10_approve_is_the_direct_path_plus_recompute`, `test_s_007_10_previous_open_assignment_closed`, `test_s_007_10_reject`, `test_s_007_10_ceo_direct` | Passed · 2026-10-30 · auto |
| S-007.10c | negative | An overlap by the time of approval is refused and stays pending; an overlap, or a second different change from the same date, is refused when asked; an edit of an assignment the CEO changed since needs a confirm on the card; an addition with an end date that closes an open assignment recomputes past its end | `AssignmentRequestTest::test_s_007_10_overlap_at_approval`, `test_s_007_10_refused_when_asked`, `test_s_007_10_changed_since_needs_confirm`, `test_s_007_10_range_reaches_past_the_end` | Passed · 2026-10-30 · auto |
| S-007.10d | happy | Editing or removing an assignment by HR is a request ("Change pending approval", "Removal pending approval" on the profile, "Remove shift assignment" on the card); approving applies it and recomputes the range | `AssignmentRequestTest::test_s_007_10_edit_and_delete_are_requests` | Passed · 2026-10-30 · auto |
| S-007.11 | negative | Given the existing suites, then they pass (two assertions changed, see S-17.1 and S-17.9) | full suite: 319 tests | Passed · 2026-10-30 · auto |

## Slice 009 – A time-out after midnight belongs to the day it started

Slice 009: 21 passed, 0 failed, 0 blocked, 0 skipped; S-009.4 is an open question (the app has no night-differential computation)

Tests live in `tests/Feature/Overnight/`, on the fixtures of Slice 002 plus the warehouse shift (12:00–21:00, lunch 16:00–17:00, 8 h) and W, daily rate 718. "The live day" is In 11:12 · Lunch out 16:00 · Lunch in 16:59 on Oct 12, and Out 01:11 on Oct 13.

### S-009 A time out after midnight counts on the day it started (P1)
As HR and the CEO, we want a punch after midnight to be the time out of the day it started, so that a person who worked past midnight is paid a full day and their overtime can be approved.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-009.1 | happy | Given the live day's punches, when computed (Recompute, the import of one file, or a later file with only 01:11), then the time out is Oct 13 01:11, regular 8 h, late 0, undertime 0, overtime 4 h 11 m recorded and not approved, and no day is made for Oct 13; a locked start date stays as it is | `OvernightComputeTest::test_s_009_1_*` (4 tests) | Passed · 2026-10-31 · auto |
| S-009.1b | happy | Given the three punches only, when the CEO types time out 01:11, then it is stored on Oct 13 and the day is 8 h; the edit keeps "01:11:00"; a time out typed at 07:00 stays on Oct 12 | `OvernightApprovalsTest::test_s_009_1_typed_time_out_*` | Passed · 2026-10-31 · auto |
| S-009.2 | happy | Given punches on Oct 13 at 01:11, 11:05, 16:00, 17:00 and 21:00, then Oct 13's time in is 11:05 and its day is 8 h; each date computed on its own gives the same rows; six nights in a row give six full days by range, day by day or import; Recompute removes an earlier Oct 13 day made from 01:11 alone, unless it has an edit of its own or the date before is locked | `OvernightComputeTest::test_s_009_2_*` (3 tests) | Passed · 2026-10-31 · auto |
| S-009.3 | edge | 05:59 is the start date's time out and 06:00 is not; with an 08:00 shift on Oct 13, 04:59 is and 05:00 is not | `OvernightComputeTest::test_s_009_3_window_edges` | Passed · 2026-10-31 · auto |
| S-009.4 | edge | Night differential for an eligible employee counts 22:00–01:11 | none: the app has no night-differential computation (only the flag) | Open question |
| S-009.5 | negative | A normal day computes exactly as before; an early punch on the next date stays that date's when the start date has its own time out, or no punches | `OvernightComputeTest::test_s_009_5_*` | Passed · 2026-10-31 · auto |
| S-009.6 | happy | The payroll of Oct 1–15 shows the live day as 01:11, 8 h, 718.00, undertime 0, OT 0; rice allowance counts 1 day; after approving 4.1833 h, OT 251 m pays 469.32 | `OvernightPayrollTest::test_s_009_6_*` | Passed · 2026-10-31 · auto |
| S-009.7 | happy | A 01:11 time out asked by HR previews "1:11 AM (next day)", 8 h, undertime 0, no "Time out is before time in", and approving saves it on Oct 13; an overtime request on the live day shows Now/If approved with the next-day time out and approved overtime 4 h | `OvernightApprovalsTest::test_s_009_7_*` | Passed · 2026-10-31 · auto |
| S-009.8 | happy | The repair command's dry run lists each change and writes nothing; --apply writes them, skips the locked date, removes the next-date day made from the 01:11, keeps one with an edit, and a second run changes nothing | `OvernightRepairTest::test_s_009_8_*` | Passed · 2026-10-31 · auto |
| S-009.9 | happy (P2) | The calendar cell shows "01:11 AM (next day)" (raw value 01:11:00) and the attendance viewer shows "01:11 (next day)" | `OvernightDisplayTest::test_s_009_9_*` | Passed · 2026-10-31 · auto |
| S-009.10 | negative | Given the existing suites, then they pass | full suite: 340 tests (319 before) | Passed · 2026-10-31 · auto |

## Owner checks

- [ ] S-04.6 Import page: the Stats cell shows "Computed: …" when the badge turns DONE, without a reload (browser, after release)
- [ ] S-11.1 CEO approves a request on a real phone and sees the day become real attendance (staging)
- [ ] S-006.8 The Device punches block on a real phone (390 px, with the site's styles loaded): below the request, no sideways scroll (staging)
- [ ] S-10.1 The Approvals link and the count look right in the nav on the CEO's account (staging)
- [ ] S-13.2 The requester sees the CEO's rejection reason on the calendar, on HR's account (staging)
- [ ] S-007.9 The shift change card on a real phone (390 px, with the site styles): no sideways scroll, "See each day" opens (staging)
- [ ] S-007.10 HR adds a shift assignment on a profile, it shows as pending there, and the CEO approves it from Approvals (staging)
- [ ] S-009.1 After deploy, the repair dry run on production lists the live Oct 16 day; the owner decides on --apply (production)
