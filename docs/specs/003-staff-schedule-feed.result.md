# Spec 003 result: a read-only staff schedule feed

Branch `feat/003-staff-schedule-feed`, from `main` at `c07a1ee`. Not pushed.

| Commit | What |
|---|---|
| `774d42c` | the brief, as `docs/specs/003-staff-schedule-feed.md` |
| `b188f20` | `ScheduleResolver` extracted; the Employee model, Holiday model and the Attendance Calendar use it; the calendar pin test |
| `d60deb8` | the feed: controller, key middleware, named limiter, config key, `.env.example`, route, tests |
| (this file) | the result |

## Where the schedule rules were, and where they are now

They were not in a controller but in the models, one query per question:
`Employee::getShiftForDate` (employee shift assignment by `effective_date`, else the default shift),
`Employee::isDayOff` (a `day_offs` row, else the rest-day patterns), `Employee::isHolidayEligibleForDate`
(employment status history), `Holiday::getHolidayForDate` (exact date, else recurring). The calendar called
them per cell; payroll and compute call them per day.

They now live once in `app/Services/ScheduleResolver.php`. It is built for a set of employees and a date
range and loads each kind of data with one query the first time it is needed. The model methods above are
now one line each that build a resolver for one employee and one date and ask it (same queries per call as
before), so payroll, compute and the approval code go through the same rules unchanged. The old copies
(`getRestDayPatternsForDate`, `EmployeeShiftAssignment::getActiveShift`) are removed; nothing else called
them. The calendar index builds one resolver for its visible employees and range.

**Department shifts.** The app never reads `department_shift_assignments` when it decides a day: setting a
department's shift copies it into `employee_shift_assignments` of the employees in `department` mode
(`DepartmentController::replicateShiftToEmployees`). The resolver follows that; the test sets the
department's shift through the real department page and checks the feed.

## Precedence as built

The first rule that applies wins:

1. **inactive**: the `employee_active_statuses` row covering the date says inactive; with no row covering the
   date, the cached `employees.status` decides (an employee with no history is active, as
   `EmployeeActiveStatus::syncEmployeeStatus` sets it).
2. **leave**: an `sil_applications` row on the date. Only an approved SIL has a row (since spec 002 a request
   by HR staff waits in `attendance_change_requests`), so pending and rejected SIL stay scheduled. Placed
   above rest days because the calendar shows SIL over a rest day.
3. **day_off** (`day_off_type: "day_off"`), a `day_offs` row of type `day_off`; or **rest_day**, a rest-day
   pattern of the weekday not cancelled by a `cancel_day_off` row.
4. **holiday** (`holiday_name`), only if the employee is holiday-eligible on the date: payroll counts a rest
   day before a holiday, and treats a holiday as a working day for an employee whose status is not eligible
   (for example Training). So a non-eligible employee is `scheduled: true` on a holiday.
5. otherwise **scheduled**, with the shift of the date (`shift: null` when there is no assignment and no
   default shift).

## Name field

`name` is `Employee::display_name`: `actual_name` when set, else `full_name` (the device name), the field
the calendar and the employee pages show.

## Shape

```json
{
  "from": "2026-10-05",
  "to": "2026-10-05",
  "generated_at": "2026-10-05T07:00:12+08:00",
  "days": [
    {
      "date": "2026-10-05",
      "employees": [
        { "employee_id": 11, "name": "Employee One", "department": "Production", "date": "2026-10-05",
          "scheduled": true,
          "shift": { "name": "Day shift", "start": "08:00", "end": "17:00",
                     "lunch_start": "12:00", "lunch_end": "13:00", "grace_in_minutes": 10 } },
        { "employee_id": 12, "name": "Employee Two", "department": "Production", "date": "2026-10-05",
          "scheduled": false, "reason": "rest_day" },
        { "employee_id": 13, "name": "Employee Three", "department": null, "date": "2026-10-05",
          "scheduled": false, "reason": "leave" }
      ]
    }
  ]
}
```

`day_off` adds `day_off_type`; `holiday` adds `holiday_name`. Employees are every row of `employees`, sorted
by department then name as the calendar sorts them (no department last). 422 answers carry
`{"message": "Invalid dates.", "errors": {...}}`; 401 has an empty body.

## Queries

A 7-day call for 30 employees, each with a rest-day pattern, a shift assignment, an active-status row and a
day off, plus a holiday, makes **11 queries**: employees, departments, default shifts, active statuses, SIL,
day offs, rest-day patterns, holidays, status history, shift assignments and their shifts. A 35-day call for
30 employees makes the same 11, and a one-day call for one employee no more
(`FeedShapeTest::test_queries_do_not_grow_with_employees_or_days`). An inactive-only range stops before the
later kinds are loaded, so it can be fewer.

## Tests

New, in `tests/Feature/ScheduleFeed/` (22 tests):

- `CalendarScheduleUnchangedTest::test_calendar_shows_the_same_schedule_for_a_sample_week`: written and
  passing on the old code before the move (status and shift name of eight calendar cells: punched, absent,
  explicit day off, SIL, new assignment, cancelled rest day, rest day with a one-day assignment, after it);
  still passing after.
- `FeedAccessTest`: 401 without the header, with a wrong key, with an empty header, and for every call when
  the config key is empty or missing (body empty each time); 200 with the key and no cookie set; default date
  is today in Manila (17:30 UTC on the 15th gives the 16th); 422 for `2026-02-30`, `abc`, `16-10-2026`, `from`
  without `to` and the reverse, `to` before `from`, `date` together with a range, `date[]`; 422 for 32 days and
  200 for 31; 429 on the 61st call in the hour (asked 5 minutes later so the general 60-a-minute api limit is
  not what answers), a wrong key does not count, and the next hour works again.
- `FeedScheduleTest`: shift day with times and grace and the `actual_name`; name falls back to the full name;
  rest day; day off with its type, and a cancelled rest day scheduled; approved SIL is leave, pending and
  rejected SIL requests stay scheduled; holiday with its name, a recurring one, and a non-eligible status
  scheduled; inactive days from the history and from the cached column; precedence on four days
  (inactive > leave > day off > holiday, rest day > holiday); department assignment (set through the
  department page, from Thursday) against an employee's own assignment (from Wednesday, with a one-day
  return on Saturday); no shift at all gives `shift: null`.
- `FeedShapeTest`: exact keys at each level and for each kind of entry, every key in the body within the
  allowed list and none in the forbidden list, and the rate, punch times, minutes, day note and device id of
  an employee with punches, a day row and a rate absent from the body; the query test above.

**Red first.** The calendar pin test was green on the old code and stayed green (that is its job). The feed
tests were written after the feed code, so they have no red line from the base; in its place, four mutations
of the finished code, each reverted after:

| # | Mutation | Test that turned red |
|---|---|---|
| M1 | key check without the "no key configured" guard | `FeedAccessTest::test_401_for_every_call_when_no_key_is_configured` |
| M2 | route without `throttle:schedule-feed` | `FeedAccessTest::test_429_on_the_61st_call_in_an_hour` |
| M3 | SIL checked before inactive | `FeedScheduleTest::test_precedence_inactive_then_leave_then_day_off_then_holiday` |
| M4 | oldest shift assignment wins instead of the latest | `CalendarScheduleUnchangedTest::…sample_week`, `FeedScheduleTest::test_employee_and_department_assignment_switching_mid_range` |

**Suite**, `php.bat vendor/phpunit/phpunit/phpunit --no-coverage`: on `main` `OK (229 tests, 1778
assertions)` (no test fails on `main`); with the pin test before the move `OK (230 tests, 1788 assertions)`;
at the end `OK (250 tests, 1954 assertions)` (22 new: 1 pin test, 21 feed tests).

## Rulings

Ruling: two assignments (or status rows) starting the same day are ordered by id, the later-created first — the old SQL took `orderByDesc('effective_date')->first()` with no tie order, so the database chose — on a tie the shown shift could differ from what the live database returned before; the assign code updates a same-date row instead of adding one, so ties should not exist.

Ruling: when no active-status row covers a date, the cached `employees.status` decides — the bulk "deactivate" action on the Employees page writes only that column, and no hire or separation date exists — an employee deactivated in bulk shows inactive on past dates too, and one whose history starts later shows the current status before it.

Ruling: "not yet hired / already separated" has no rule in the app beyond the active-status history (there is no hire or separation date), so every employee is listed every day and those days read `inactive` when the history says so — if the office wants them left out, that needs a date the app does not keep.

Ruling: `day_off_type` is always `"day_off"` — `day_offs.type` has only `day_off` and `cancel_day_off`, and the second means a working day — the field carries no more than `reason` does today; kept because the brief asks for it and it will carry a new type if one is added.

Ruling: the feed keeps the general `throttle:api` (60 a minute by IP) of the api group as well as its own hourly limit by key — it is the only guard against guessing keys, since the hourly bucket counts valid keys only — an office polling more than once a second would meet it first.

Ruling: date range columns are read with an upper bound of `to 23:59:59` — a date column that holds a time part (sqlite in tests) is still found, and MySQL compares a `DATE` with it correctly — none expected.

## What the brief got wrong or left open

1. The schedule logic was not in a controller but in the models (one query per question); there was no
   controller code to extract, so the models now delegate to the new service.
2. "Employee vs department shift assignment" is not decided when a day is read: department shifts are copied
   into the employees' own assignments when set. An employee switched to department mode after a department
   shift was set gets it only on the next department change or when added through the department page.
3. `day_off_type` has only one possible value today (ruling above).
4. There is no hire or separation date (ruling above).
5. Holiday eligibility changes the answer: a non-eligible employee is scheduled on a holiday, as payroll
   treats it.

## Server

Set `SCHEDULE_FEED_KEY` in the server's `.env` to a long random value (for example the output of
`php -r "echo bin2hex(random_bytes(32));"`), give the same value to the owner's office for the header
`X-SCHEDULE-FEED-KEY`, then `php artisan config:cache` if the config is cached. Empty or missing keeps the
feed off (every call 401). No migration.

**Merge danger:** two-way door. The feed is new and read-only. The model methods now route through the
resolver, which payroll and compute use: covered by the 229 existing tests, all passing, but not run on
MySQL. Revert: `git revert d60deb8 b188f20`.
