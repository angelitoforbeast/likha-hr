# Spec 003: a read-only staff schedule feed

Branch `feat/003-staff-schedule-feed`, cut from `main` at c07a1ee. Fast mode: short brief, tests must pass.

## Why

The owner's office (a separate system) monitors staff in one place and needs each employee's SCHEDULE per day: who is due to work, their shift, and why someone is not due. It must reuse the schedule logic the app already has (the same rules the Attendance Calendar and payroll use to decide a day's shift, rest day, day off, SIL and holiday), never a second copy of it. Punches and pay data must never leave through it.

## Decisions already made (do not reopen)

| Decision | Why |
|---|---|
| `GET /api/staff-schedule?date=YYYY-MM-DD` (default today, Asia/Manila) and `?from=YYYY-MM-DD&to=YYYY-MM-DD` (inclusive, at most 31 days; more is a 422) | Owner's office: one day or a week |
| Auth only by header `X-SCHEDULE-FEED-KEY` compared in constant time with `config('services.schedule_feed.key')` from env `SCHEDULE_FEED_KEY`; empty or missing config means every call is refused; wrong or missing header is 401 with no body detail. No session, no cookies, no CSRF. Add the env key, empty, to `.env.example` | Read-only machine feed |
| Rate limit 60 requests per hour per key (a named limiter), 429 beyond | Owner's office polls; abuse guard |
| Per employee per day: `employee_id`, `name` (the employee's actual / full name: use the field the app shows as the real name, e.g. `actual_name` when set, else the name; say which), `department`, `date`, `scheduled` true/false; when false, `reason`: `rest_day` / `day_off` (+ `day_off_type`) / `leave` (approved SIL only) / `holiday` (+ `holiday_name`) / `inactive`; when true, `shift`: { `name`, `start`, `end`, `lunch_start`, `lunch_end`, `grace_in_minutes` } (HH:MM). Precedence when several apply: write the rule the app already follows (inactive first, then …) and state it in the result | Owner's office needs who is due and why not |
| Never in the response: punches or time in/out, attendance results, pay, rates, deductions, cash advance, benefits, government ids, phone, email, address, photo, notes | Names and schedule only |
| Which employees: every employee the app lists as an employee, inactive ones included as `scheduled: false, reason: inactive` for the days they are inactive (`employee_active_statuses`); employees not yet hired / already separated by date per the app's own rule | One list the office can match by name |
| Top level: `{ "from", "to", "generated_at", "days": [ { "date", "employees": [ ... ] } ] }`; ISO dates; times Asia/Manila | Stable shape for the office to read |

## Do

- Find where the app resolves a day's shift for an employee (employee vs department shift assignment by `effective_date`, `schedule_mode`, rest day patterns, `day_offs`, SIL, holidays) and call that same code; if it is only inside a controller, extract it into a small service the controller and the feed both use, without changing its behaviour (the existing tests must still pass; add one that pins the old page's result for a sample week).
- Controller, route in `routes/api.php`, limiter, config key. One query plan per request: no query per employee per day (preload what the resolver needs for the range); say how many queries a 7-day call makes for 30 employees in a test.

## Tests

401 without / with a wrong key and when the config key is empty; 200 with the right key; 422 for a bad date and for a range over 31 days; 429 on the 61st call in an hour; one employee on a shift (times and grace right), a rest day, a day off with its type, an approved SIL day (a pending or rejected one stays scheduled), a holiday, an inactive day; employee vs department assignment with `effective_date` switching mid-range; the key-set test that pins the exact JSON keys and proves no forbidden field appears; the extracted resolver gives the same result as before for the calendar. Red first where natural. The whole suite must pass (name any test that already fails on `main`).

## Out of scope

Any page or UI; writes of any kind; punches; payroll; the office side.

## Rules of the work

Never `git push`; no remote hosts; the only download is `composer install` from the existing `composer.lock`; tests run with `php.bat vendor/phpunit/phpunit/phpunit --no-coverage`; commits and comments give the reason and name no people; no real names, keys or ids in tests or `.env.example`. The result goes in `docs/specs/003-staff-schedule-feed.result.md`.

## Final message

Commits; the precedence rule as built; which name field is used; the JSON shape with a fake example for one day and three employees (scheduled, rest day, leave); queries per 7-day call; test lines and suite result; the env key to set on the server; anything the brief got wrong.
