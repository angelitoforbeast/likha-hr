# Result: spec 012 biometrics feed

**Status:** done
**Date:** 2026-11-10
**Branch / PR:** `feat/012-biometrics-feed`, from main ad139cc. Not pushed; no PR.
**Preview or run link:** n/a. Nothing is deployed and no real key was set. The test runs and the checks are in `docs/specs/012-evidence/`.

## Summary
- **Four endpoints** (`BiometricsFeedController`, `routes/api.php`), behind `X-BIOMETRICS-FEED-KEY` (`BiometricsFeedKey`, `services.biometrics_feed.key` from `BIOMETRICS_FEED_KEY`) and their own limiter of 300 calls an hour per key (`biometrics-feed`):
  - `POST /api/biometrics/punches` takes `{agent_id, attlog}`.
  - `POST /api/biometrics/heartbeat` takes the agent and device state, and the daily clock drift.
  - `POST /api/biometrics/users` takes the device user list, which is only shown.
  - `GET /api/biometrics/status` is read-only: counts and warnings, no names, no punches.
  - Every JSON answer carries `server_time` (UTC, ms) and `last_punch_at`.
- **One import path.**
  - An agent post becomes a run row (`uploaded_by` NULL, `stats_json.source` "agent", `agent_id`). Its lines are written as the run's `attlog.dat` and `ZktecoParserService::parse` runs on them: the 3-minute de-duplication unchanged, then `computeEmployeeDays`.
  - The import job, agent posts and held-punch runs are serialized by `AttendanceIngestLock` (a cache lock, re-entrant inside one process).
- **Held punches (decision 4).**
  - The parser holds a line whose ID matches no employee as a `biometric_feed` row (`held`, zkteco_id, time), once. This covers agent posts and manual uploads alike.
  - When HR adds that employee (the `Employee` saved event: created, or zkteco_id changed), the held lines go through the same path as a run with source "held", and the rows are removed.
  - After every agent post and every manual import, a sweep applies any held punch whose employee now exists.
  - The upload preview no longer blocks unmatched IDs: they are listed with a checked "Hold" box.
- **Refused dates (decision 5).**
  - For agent and held lines, a punch before 2020-01-01 or more than 24 h after now (Manila) is refused: never stored, held or computed.
  - It is counted in the response, the run and the Import page (`refused` rows, each once), and once refused it stays refused.
- **Storage (decision 7).**
  - One new table, `biometric_feed` (kind, key, value, timestamps, unique on all three). It holds the agent state, held and refused punches, the device users not in HR, and the daily drift log.
  - One column change: `attendance_import_runs.uploaded_by` is now nullable. No other table.
- **The Import page** (`import/_biometrics-feed.blade.php`) shows:
  - last contact (Manila), Office PC and Device status, records against 50,000, clock drift, held punches per ID, the refused count, the latest punch, and the device users not in HR;
  - warnings: no contact (danger), device unreachable, at or over 40,000 records (warning), at 50,000 (danger), and a drift over 60 s.
- **Agent runs on the Import page.** They are listed as "Biometric agent (office-pc)" for everyone with the page's permission. Posts that changed nothing ("quiet") are hidden, and removed after 24 h.
- **The agent** (`tools/biometrics-agent/`): `agent.ps1`, `ZkProtocol.ps1`, `install.ps1`, `InstallHelpers.ps1`, `uninstall.ps1`, `config.example.json`, plus `mock/fake-zk.ps1`, `mock/fake-http.ps1` and `tests/run-agent-tests.ps1`. `mock/reference-reader.ps1` is committed unchanged. Each run is one poll:
  1. Read the device (connect, CMD_AUTH, sizes, users, attendance log, exit).
  2. Keep each record once in the monthly CSV.
  3. Post the window in batches of at most 1,000 through `outbox.txt`.
  4. Send a heartbeat, and the user list hourly or when it changes.
  5. Once a day from 06:00 Manila, do the clock check.

## Untrusted input (threat model)
- **Untrusted:** every byte of a `/api/biometrics/*` request (headers other than a matching key, query, body, `agent_id`, lines, names, counts); the device's replies (frames, sizes, record bytes, user names); the config file values.
  - A wrong or absent key gets an empty 401 before anything is read or written.
  - Inside, every field is validated: lines are parsed and never echoed, IDs and names are bounded, names are escaped on the page.
  - Device replies are checked: frame magic, length, checksum, buffer size and record size. A bad reply is dropped and nothing is posted.
- **Trusted:** HR users behind the login (the panel uses the Import page's `feature:nav_import`), and the office PC holding the key file (SYSTEM and Administrators only). The scripts sit in Program Files, which only administrators can change.

## Case table
| Case | Test | Result |
|---|---|---|
| S-012.1 punch arrives | `tests/Feature/Biometrics/PunchArrivesTest.php` (8) | pass: `✔ S-012.1 one punch with the right key: one stored punch for zk 1, a run with source agent, the day computed` and 7 more |
| S-012.2 key | `FeedKeyTest.php` (6) | pass: `✔ S-012.2 no key, a wrong key, other case, a trailing space, the key in the query or body: empty 401, nothing written`, `✔ S-012.2 the 301st call in an hour gets 429…`, `✔ S-012.2 the wrong method gets 405, never 500` |
| S-012.3 duplicates | `DuplicatesTest.php` (5); `012-evidence/concurrent-posts-file-lock.txt` | pass: `✔ S-012.3 the same punch posted twice is stored once; the second run says 1 duplicate`; 4 processes at once → 1 row (1 inserted, 3 duplicates, serialized) |
| S-012.5 outage | agent checks D, E, Q, Q5 | pass: `PASS Q5 5 days off: all 5 days are posted by themselves, no -Since, and computed (amendment 012-1)`, `PASS D after the reboot: the outbox is sent once and emptied`, `PASS E 5xx: six runs in six minutes call Likha HR only 3 times`, `PASS Q 3 days off: the backlog is posted once and the touched days computed` |
| S-012.6 clock | agent checks J, K, L; `RefusedDatesTest.php` (6); `HeartbeatAndStatusTest::test_s_012_6_clock_drift_is_shown` | pass: `PASS J clock within 60 s: the device clock is set to Manila time`, `PASS K PC 2 minutes off: the device clock is untouched`, `PASS L later that day: the clock check is retried…`, `✔ S-012.6 a punch refused as too far ahead stays refused when its date comes near` |
| S-012.7 new hire | `HeldPunchesTest.php` (10) | pass: `✔ S-012.7 creating employee 54 applies the held punches through the import path and computes the days` and 9 more |
| S-012.8 same as manual | `SameAsManualTest.php` (7) | pass: lock, override, approved overtime, pending request, overnight 02:10, compute error, inactive |
| S-012.9 heartbeat | `HeartbeatAndStatusTest.php` (10 of 12) | pass: `✔ S-012.9 a warning after 10 minutes of silence between 08:00 and 20:00, gone when contact returns`, `✔ S-012.9 no silence warning at night; it shows again at 08:00`, `✔ S-012.9 the agent alive but the device unreachable: both facts are shown` |
| S-012.10 recovery | agent checks F–H, I, G2 | pass: garbled, half, busy and dropped replies each end cleanly with nothing posted, and the next poll re-auths and posts; a garbled record is dropped; a punch during the poll is read |
| S-012.11 never clears | agent checks M, C; `test_s_012_11_record_count_warnings`; `012-evidence/no-clear-grep.txt` | pass: the device received only `50,201,202,1000,1001,1102,1502,1503,1504`; the send function refuses 14, 15, 18, 1013, 13; the spec's grep prints nothing |
| S-012.13 (P2) users | `DeviceUsersTest.php` (4); agent check A | pass: `PASS A users: device users not in HR are listed with UTF-8 names (S-13) [54=Juan Peña; 55=ÑANDO]` |
| S-012.14 (P2) install | agent checks O, P, N, R, S | pass: task definition, key-file ACL, log rotation, monthly CSV, no key in any file. The boot run with nobody signed in is an owner check (it needs the office PC) |

Server: `OK (58 tests, 776 assertions)` (`case-tests-green.txt`). Agent: `Agent tests: 52 passed, 0 failed` (`agent-tests-green.txt`; the 5-day check Q5 failed first, `red-amendment-1.txt`: `stored 2 of 5`).

## Story changes
- Added Slice 012 to `qa/stories.md`: S-012.1–.3, .5–.11, .13, .14 as in the spec, plus S-012.15 (the regression row).
- S-012.6 adds "stays refused when its date comes near" and "a manual upload keeps its own preview window". S-012.9 adds "a malformed call is no contact". S-012.10 adds "a punch made during the poll". S-012.14 adds "a bad agent id or plain http stops the run".
- S-01.6 (manual upload, unmatched ID): its punches are now held, not skipped.
- Three owner checks added: the boot run on the office PC, the first real clock set, and the first real punch after deploy.

## Done-when checklist
- [x] Every P1 case has a test that failed first, then passed.
  - Server: `red-server-tests-before-code.txt` (`Tests: 55, Assertions: 56, Errors: 6, Failures: 49.`, before any code). The review fix: `red-review-fixes.txt`.
  - Agent: the same checks against a do-nothing stub agent fail (`red-agent-tests-stub-agent.txt`). See Rulings on the order.
- [x] Feature tests cover the four endpoints.
- [x] The agent is tested against a fake ZKTeco TcpListener with packet checksums and CMD_AUTH, on the poll, post, outbox, backoff and clock-sync paths, with the real app served locally (`php -S`) and a fake 500 server.
- [x] The full suite adds no new failures against ad139cc.
  - Baseline: `Tests: 354, Assertions: 3189, Failures: 1.`
  - After: `Tests: 412, Assertions: 3965, Failures: 1.`
  - The one failure is the same in both (`ApprovalsPageTest::test_s_10_1_nav_count_and_list`): it asserts "Asked … Oct dd, 2026" and fails on today's date at ad139cc too (`baseline-ad139cc.txt`, `full-suite-after.txt`).
- [x] The manual upload tests stay green: `tests/Feature/Import` passes unchanged.
- [x] `git grep -n -E "Cmd 1[45] |CLEAR" -- tools/biometrics-agent` prints nothing (`no-clear-grep.txt`).
- [x] Deploy notes, install guide and exact commands (below).

## How to run
```
git checkout feat/012-biometrics-feed
composer install
php vendor/bin/phpunit tests/Feature/Biometrics --testdox
php vendor/bin/phpunit
powershell -NoProfile -ExecutionPolicy Bypass -File tools\biometrics-agent\tests\run-agent-tests.ps1
```
- The agent checks need `php` on PATH and the free ports 14370, 18123 and 18124. They take about 4 minutes, because they space the runs to stay under the general API limit of 60 calls a minute.
- They use a throw-away SQLite file in %TEMP% and a generated key. They read no `.env`. They do run `php artisan cache:clear` on this checkout.

## Amendments
- **012-1 (2026-11-10)**: decision 1's "if that is later" now reads "whichever reaches further back". The agent posts from the EARLIER of now − 3 days and the last acknowledged punch − 1 day, in batches of at most 1,000, with `-Since` kept as a manual override. Agent check Q5 (a 5-day outage posts all 5 days) failed first (`stored 2 of 5`) and passes now. See the Window ruling.

## Rulings
Every decision the spec left open:
- **Window (decision 1, as amended by 012-1)** — Start = the EARLIER of now − 3 days and last acknowledged punch − 1 day. In steady state the last 3 days are posted (about 600 lines, in batches of at most 1,000), and the server answers them as duplicates. `last_punch_at` counts held punches too.
  - Why: amendment 012-1 (the owner's office) reads "if that is later" as "whichever reaches further back". The window exists so no punch is lost after an outage, and the server de-duplicates re-sent punches.
  - A PC outage longer than 3 days is now caught up by the next poll by itself (agent check Q5: a 5-day outage posts all 5 days). `-Since <date>` stays as a manual override, for example after a lost `state.json`.
  - The first version read the decision literally (the later of the two, capped at now − 1 day). That reading is replaced, and so is its cap, which an earlier start makes unnecessary.
  - Cost: on the first poll after install, the window reaches back to a day before the last punch Likha HR holds, so everything the device holds since then is posted once and de-duplicated.
- **Records with a refused date are always posted** — A record before 2020 or more than a day ahead is posted outside the window, every minute.
  - Why: decision 5 wants them counted, and the server counts each one once.
  - Cost if wrong: a few extra lines a minute.
- **Decision 5 applies to agent and held lines, not to manual upload files** — Once refused, a punch stays refused.
  - Why: the done-when says "the manual upload tests stay green", and `test_far_future_timestamp_does_not_fail_the_run` stores a year-9999 punch. The upload preview already limits dates to a window HR picks.
  - Cost if wrong: one condition in `parseAttlogDat`.
- **Junk IDs** — "Not 1–9 digits" is checked only for IDs that match no employee. They count as invalid lines, are not held, and are not counted as unmatched.
  - Why: a matched employee's punches keep the old behaviour exactly.
  - Cost if wrong: none today, since the device IDs are numeric.
- **An ID that has an employee but is missing from a file's own user list** is skipped as before, not held. This path is dead: uploads carry no user.dat.
- **A run row for every post, with "quiet" runs hidden** — A post that stored, held, newly refused and computed nothing, with no error, is marked `quiet`. Its file is deleted, the Import page does not list it, and it is removed after 24 h. A locked-date skip alone counts as quiet.
  - Why: decision 3 and S-03 ("the second run says 1 duplicate") want a run per post, but 1,440 visible runs a day would bury the page.
  - Cost if wrong: one query condition.
- **`uploaded_by` is nullable** (a migration with plain SQL per driver, since there is no doctrine/dbal). No system user was made.
  - Why: a fake user can sign in, and deleting it would cascade-delete every agent punch.
  - Agent runs are shown to everyone who may open the Import page.
- **When held punches are applied** — On the `Employee` saved event (created, or zkteco_id changed), and in a sweep after every agent post and manual import. The run is attributed to the person who added the employee, with source "held". A failure leaves the rows held.
- **The lock** — `Cache::lock('attendance-ingest')` with a 900 s lease.
  - An agent post waits 20 s, then gets `503 Retry-After: 30`.
  - The upload job waits 300 s, then marks its run failed: "Another import kept running; upload the file again."
  - Production needs one cache store shared by web and the queue worker (file on one host is fine).
- **Response codes** — Over 1,000 lines: 422 `too_many_lines`. A bad body: 422 `invalid_request`, with field names only. Busy: 503. A failed import: 500 `import_failed`.
- **Contact** — Any well-formed authenticated POST is a contact. A malformed call, a 401 or reading the status is not (from the review).
- **Counts** — `refused` in the response counts the lines this post refused; the page counts distinct refused punches. `held` counts newly held punches.
- **Device users** — The list is replaced on every post, only the users not in HR are stored, and it is filtered again at render. Names are at most 64 characters and the list at most 5,000. The agent posts it hourly, or when it changes.
- **Silence warning** — Shown when 08:00 ≤ Manila time < 20:00 and the silence is more than 10 minutes. It names the date when the silence started on another day, and reads "No contact from the office PC yet" before the first contact. When the device is unreachable, the last known record count stays.
- **Agent form** — One poll per scheduled run every minute, not a long-running loop, with a `Global\` mutex.
  - Backoff: 1, 2, 4 … 30 minutes, and Retry-After is honoured.
  - A 413 or `too_many_lines` batch goes to `rejected-YYYYMMDD.txt`. Any other refusal stays in the outbox (from the review).
- **Reply checksums** — The agent accepts a reply whose checksum is right under either the standard or the reference-reader convention. `check_reply_checksum: false` turns the check off as a last resort.
  - Why: the reference reader never checked replies, so the device's own convention is unproven.
- **Record size** — Taken from the count the device reports. When a punch lands during the poll, it falls back to the device's 40/72 bytes (from the review).
- **Clock** — Manila is fixed at UTC+8.
  - Drift = the PC's midpoint of a `GET /status` round trip − `server_time`.
  - The device is set to the PC's Manila time, and the device's own drift is reported.
  - Retries come every 15 minutes. `clock_check_at` is configurable (tests use 00:00).
- **Agent setup** — `base_url` must be https (`allow_http` exists for tests only), and `agent_id` must match the server's pattern; otherwise the run stops before sending (from the review). The data folder is locked to SYSTEM and Administrators, and the scripts are in Program Files.
- **Agent test order** — The agent was written before its test script, not after. Red was shown afterwards by running the same 50 checks against a do-nothing stub (`red-agent-tests-stub-agent.txt`). The server side was strictly test-first.
- **Header whitespace** — A trailing space in the key is refused in the tests. A real web server strips trailing whitespace from header values before PHP sees it, so on production "key " equals "key". This is HTTP behaviour, not the check.

## Skeptic review
A separate reviewer read `ad139cc..HEAD` against the five areas the spec names.
- **Key check:** sound.
- **Lock:** sound when the cache store is shared.
- **Held punches:** no double application.
- **Never clears:** proven. `Send-ZkPacket` is the only write to the socket and refuses everything outside the read and clock list.
- **Date validation:** not sound. The blocker was a punch refused as over a day ahead: it was re-sent every minute and would be stored once its date came within a day.
  - Fixed: a refused punch stays refused (`test_s_012_6_refused_future_punch_stays_refused`, red then green).
- **Also fixed:**
  - Contact is recorded only after validation.
  - A config `agent_id` with a space no longer sends every batch to `rejected-*.txt`: it is refused at start, and only `too_many_lines` and 413 divert a batch.
  - Held rows are applied only on an exact zkteco_id match, so a MySQL collation can no longer match "54 ".
  - A punch made during the poll no longer fails the read.
  - Plain http is refused.
- **Not fixed:** see the deferred minors and open questions.

## Deferred minors
- A held-punch application that keeps failing (status failed) is retried after every agent post, so it makes a failed run a minute until fixed. Rare: it needs a parse failure on held lines.
- The lock lease is 900 s. If PHP dies inside an ingest (a fatal error, or the job killed at 600 s), posts get 503 for up to 15 minutes. The agent backs off and loses nothing.
- The exact-match filter on held rows is proven only on SQLite. The MySQL collation case comes from the review, not from a run on MySQL.
- The job's 600 s timeout now also covers up to 300 s of waiting for the lock.

## Merge danger
- **Two-way door for code, one-way for one column.** `uploaded_by` becomes nullable; going back needs the agent's runs removed or given an uploader first (the migration's `down` will fail while NULL rows exist).
- **Manual upload changes:**
  - Unmatched IDs are held, not skipped, and no longer block the preview.
  - Junk unmatched IDs count as invalid.
  - The job takes the ingest lock.
  - A held sweep runs after each import.
- **Blast radius if wrong:** attendance punches and computed days (payroll input), the Import page, and the upload preview.
- **Revert:** `git revert` of the four commits after 43f433a, then `php artisan migrate:rollback --step=2`, but only before any agent run exists.

## Deploy notes
1. Deploy the branch, then run `php artisan migrate --force`. It adds 2 migrations:
   - `create_biometric_feed_table`;
   - `make_import_run_uploader_nullable` (MySQL: `ALTER TABLE attendance_import_runs MODIFY uploaded_by BIGINT UNSIGNED NULL`).
2. Generate a key on the server and put it in `.env` as `BIOMETRICS_FEED_KEY=<key>`. Do not paste it into chat or tickets.
   - Generate it with `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`.
   - Without the key the endpoints answer 401 to everything.
3. Clear and rebuild the caches: `php artisan config:cache && php artisan route:cache && php artisan view:clear`.
4. Restart the queue worker, because the import job class changed: `php artisan queue:restart`.
5. Check that web and the queue worker use the same `CACHE_DRIVER` (file on one host is fine). Do not run `cache:clear` while an import runs, because it drops the lock.
6. Smoke test from the server: `curl -s -H "X-BIOMETRICS-FEED-KEY: $KEY" https://<host>/api/biometrics/status`. It should return JSON with `server_time`, and the Import page should show "Office PC: not set up yet".
7. The Oct 26 – Nov 10 catch-up is a manual upload of the converted file. Its unmatched IDs are now held, not refused, and appear on the Import page per ID.

## Install guide (office PC 192.168.1.214)
1. Sign in to the office PC with an administrator account.
2. Copy the folder `tools\biometrics-agent` from this repository to the PC, for example to `C:\Temp\biometrics-agent`.
3. Click Start, type `PowerShell`, right-click "Windows PowerShell" and choose "Run as administrator".
4. Run these two lines, with the real Likha HR address:
   ```
   cd C:\Temp\biometrics-agent
   powershell -ExecutionPolicy Bypass -File .\install.ps1 -BaseUrl https://<likha-hr-host>/api/biometrics
   ```
   The defaults are device 192.168.1.201, port 4370, comm key 0 and agent id office-pc. Add `-DeviceIp …` only if the device moves.
5. When it asks for the key, paste the `BIOMETRICS_FEED_KEY` value from the server and press Enter. Nothing shows while you type or paste.
6. Read what it prints under "Reading the device once":
   - "Device read: OK" with about 53 users and the record count means it works.
   - If it says FAILED (bad_reply), open `C:\ProgramData\LikhaBiometrics\config.json`, add `"check_reply_checksum": false,`, and run step 4 again (press Enter at the key prompt to keep the key).
7. It ends with "Scheduled task … registered and started". Within 2 minutes, the Import page in Likha HR shows "Office PC: in contact" and "Device: reachable".
8. Restart the PC once and do not sign in. After 2 minutes the Import page should still show contact (owner check S-012.14).
9. Where things are:
   - Log: `C:\ProgramData\LikhaBiometrics\logs\agent.log`
   - Monthly archive: `…\archive\attlog-YYYY-MM.csv`
   - Waiting posts: `…\outbox.txt`
   - Config: `…\config.json`
   - Key: `…\feed.key` (SYSTEM and Administrators only)
10. A PC outage of any length is caught up by the next poll by itself. Only if `state.json` was lost (so the agent no longer knows the last acknowledged punch), post from a date by hand: `powershell -ExecutionPolicy Bypass -File "C:\Program Files\LikhaBiometrics\agent.ps1" -Since 2026-11-01` (as administrator).
11. To remove it: `powershell -ExecutionPolicy Bypass -File .\uninstall.ps1`. Keep the data folder until `outbox.txt` is gone.
12. Clearing the device stays manual. Do it after the Import page shows the device near 40,000 records, and only when `outbox.txt` does not exist.

## Tests
- `tests/Feature/Biometrics/` has 8 files and 58 tests: endpoints, key, duplicates and lock, held punches, refused dates, same-as-manual, heartbeat and status, device users. Latest: `OK (58 tests, 776 assertions)`.
- `tools/biometrics-agent/tests/run-agent-tests.ps1` has 52 checks. Latest: `Agent tests: 52 passed, 0 failed`.
- `012-evidence/concurrent-posts-file-lock.txt`: 4 processes, file cache, 1 row.
- Full suite: `Tests: 412, Assertions: 3965, Failures: 1`, with the same date-bound failure as at ad139cc (354 tests).

## Open questions
- Should held punches older than some limit (for example 30 days, or before the employee's start date) wait for HR's confirmation instead of being applied automatically? Device IDs can be reused after a device user is deleted, so months-old punches of someone never added would land on the next person given that ID. Decision 4 says "applied when HR creates that employee" and has no limit, so nothing was changed.
- Resolved by amendment 012-1: decision 1 now posts from the earlier of the two starts, so an outage longer than 3 days needs no `-Since`.

## Process suggestions
- When a spec says "the manual upload tests stay green" and also adds a rule to the shared import path, say which wins. Here decision 5 and `test_far_future_timestamp_does_not_fail_the_run` disagree.

## Proposed tasks
- An owner decision on the age limit for held punches (open question 1) — why: reused device IDs — normal.
- A watchdog on the server: alert the owner's office when `GET /api/biometrics/status` has had a `no_contact` warning for more than 30 minutes in the day — why: the page warns only when someone opens it — normal.

012: done — punches from the office ZKTeco reach Likha HR within a minute through the import path; unmatched IDs are held and applied when HR adds them; the agent never clears the device
