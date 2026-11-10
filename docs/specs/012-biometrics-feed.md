# Spec 012: punches from the office biometric reach Likha HR by themselves

## Why
Today an admin downloads attlog.dat and user.dat from the ZKTeco device and uploads them on /import, then computes. The last import was run 34 on 2026-10-26, so 16 days are missing.

The office device has no cloud push (no ADMS). It is a ZKTeco "ZK3969":
- firmware Ver 6.60 Sep 27 2019
- 192.168.1.201, TCP 4370, comm key 0. It needs CMD_AUTH 1102 even with key 0.
- 53 users. It holds up to 50,000 records. It was just cleared by hand.

An always-on office Windows PC (192.168.1.214, same LAN) can read it. tools/biometrics-agent/mock/reference-reader.ps1 is a no-install PowerShell 5.1 reader that worked against the real device today. It does connect, auth, sizes (CMD 50), the user list (1503/1504 buffered read, fct 5), and the attendance log (CMD 13). The real records are 40 bytes and the real user records are 72 bytes. Reuse it.

## Decisions (do not reopen)
| # | Decision | Why |
|---|---|---|
| 1 | The PC agent reads the device every 60 seconds: connect, read, disconnect. It posts every record of the last 3 days, or since the server's last acknowledged punch minus 1 day if that is later, in batches of at most 1,000 lines. Live events (CMD_REG_EVENT) are optional, P2, and only if proven against a mock. Polling is the baseline. | The owner wants each time-in in Likha HR within about a minute; stateless polling is the most robust. |
| 2 | The new endpoints use their own key header, X-BIOMETRICS-FEED-KEY, from config (env BIOMETRICS_FEED_KEY). No key configured means the endpoints are off. Same empty-401 behaviour as ScheduleFeedKey. They have their own rate limiter of about 300 per hour per key. | The precedent is routes/api.php staff-schedule; its 60 per hour is too low. |
| 3 | Received punches go through the EXISTING import path: an attendance_import_runs row (status done or failed, stats_json with source "agent" and the agent id), the attlog line shape "zkteco_id<TAB>YYYY-MM-DD HH:MM:SS", ZktecoParserService de-duplication (3-minute window, unchanged), then computeEmployeeDays for the touched days. Date locks, overrides, approvals and the overnight rule behave exactly as with a manual upload. Ingests are serialized with a lock, so two concurrent posts cannot store the same punch twice. | One import behaviour, no second rule. |
| 4 | Punches for a zkteco_id with no employee are HELD, not dropped. They are applied through the same path when HR creates that employee. This applies to the agent AND to manual upload. Update the manual-upload story that today says unmatched IDs are skipped. Refuse a junk zkteco_id: not 1–9 digits. | Owner, "Oo, lahat": a new hire's first days must not be lost. |
| 5 | A punch dated more than 1 day in the future or before 2020-01-01 is refused: never stored, never computed. It is counted in the response and on the Import page. | Owner: a device that reset its clock must not create fake days. |
| 6 | The device user list is posted by the agent and only SHOWN on the Import page as "on the device, not in Likha HR yet". It never creates or edits employees. HR stays the source of names and pay. | Owner. |
| 7 | Storage is lean. Make one new row-based table, e.g. biometric_feed (kind, key, value, timestamps, unique on kind+key+value). It holds the agent state (last contact, device reachable, device record count, last punch, clock drift, agent version), the held punches (kind held, key zkteco_id, value datetime), the device users not in HR, and the refused-date counts. Add no other table unless a test proves this cannot work; if you add one, say why in the result. | The owner's rule: no new table unless needed; row-based precedent. |
| 8 | Endpoints: POST /api/biometrics/punches, POST /api/biometrics/heartbeat, POST /api/biometrics/users, GET /api/biometrics/status. Every response returns server_time (UTC ISO) and the last stored punch time. The status endpoint is read-only: last contact, device reachable, device record count, held count, refused count, drift, warnings. It never returns names or punch lists. | The agent needs server time for the clock check; the owner's assistant reads the status. |
| 9 | Clock sync: once a day at 06:00 Manila the agent compares the PC clock with server_time. Within 60 seconds inclusive, it sets the device clock (CMD_SET_TIME 202, ZK time encoding as in the reference reader's ZTime, inverted). Otherwise it does NOT set the clock and reports the drift. The drift is logged daily. | Owner: "sync dapat daily", with a guard against a wrong PC clock. |
| 10 | The agent NEVER sends any clear or delete command: CMD_CLEAR_ATTLOG 15, CMD_CLEAR_DATA 14, user delete, or any other. When the device holds 40,000 records or more, the heartbeat says so and the Import page warns. | Owner: clearing the device stays manual. |
| 11 | The Import page shows: last contact (Manila time), device reachable or not, device record count against 50,000, held punches per ID, refused-date count, the latest clock drift, and the device users not in HR. It warns "no contact since HH:MM" after 10 minutes of silence between 08:00 and 20:00 Manila. It uses the same permission as the page. | HR sees the feed health. |
| 12 | Agent: tools/biometrics-agent/agent.ps1, PowerShell 5.1, no modules, TLS 1.2. It is installed by tools/biometrics-agent/install.ps1 as a scheduled task under SYSTEM: at startup, repeating, restart on failure, one instance at a time. The key is read from a file only SYSTEM and Administrators can read; install.ps1 asks for it and never echoes it. The key never appears in logs, the CSV or the command line. The agent keeps a local monthly CSV archive and a rotating log under C:\ProgramData\LikhaBiometrics. It keeps a local outbox for posts that failed and retries with backoff. | Runs with nobody signed in; nothing is lost on a reboot or outage. |

## Stories (P1 unless marked)
- S-01 Punch arrives. Post "1<TAB>2026-11-02 08:00:12" with the right key: one attendance_logs row for zk 1, a run row with source agent, the day computed. A 4-punch day in one post gives 4 inserted, 1 day computed. Bad lines (no tab, bad date, month 13) are counted invalid, never echoed back, and never stored. Windows line endings and extra columns parse. The result equals a manual upload of the same file. Times are Asia/Manila with no shift; a midnight edge stays on its own date. Over 1,000 lines is refused with 413 or 422, never a silent partial.
- S-02 Key. No key, a wrong key, a key with different case or a trailing space, or the key in the query or body: empty 401 and nothing written. No key configured: 401. Over the limit: 429. GET on a POST route: 405, never 500.
- S-03 Duplicates. The same punch posted twice is stored once (the second run says 1 duplicate). The 60-second re-read posting 200 known records stores nothing new. Two concurrent posts of the same new punch give exactly one row. A retry after a timeout gives no double row.
- S-05 Outage. The PC was off for 3 days: the next run posts the backlog once, and the touched days are computed. With no internet, posts wait in the outbox and survive a PC reboot. If Likha HR returns 5xx, the agent backs off without flooding.
- S-06 Clock. PC within 60 s of the server: the device clock is set and the drift is logged. PC 2 minutes off: the device clock is untouched and a warning is shown. Server unreachable at 06:00: retry later that day. A punch from a device whose clock reset (year 2000, or tomorrow+2): refused and counted (decision 5).
- S-07 New hire. Punches for zk 54 with no employee are held and shown. Creating employee 54 applies them and computes the days. Creating a different ID applies nothing. Held punches respect date locks. Held punches are de-duplicated. A manual upload with an unmatched ID also holds.
- S-08 Same as manual. A locked D1: punches saved, not computed (locked skipped 1). A manual override on time out stays. Approved fields stay. A pending request stays pending. An overnight time out at 02:10 joins the previous day (specs 009 and 011). A compute error keeps the punch saved and the run done, with errors 1.
- S-09 Heartbeat. Last contact is stored in the database and shown in Manila time. A wrong key does not update it. A warning appears after 10 minutes of silence between 08:00 and 20:00 and clears itself when contact returns. No warning at night. The agent is alive but the device is unreachable: both facts are shown.
- S-10 Recovery. A dropped connection or a device reboot: the next poll reconnects and re-auths. Garbled or half replies are dropped, never posted. If another program holds the device session, the agent fails cleanly and retries.
- S-11 Never clears. A code search proves the agent has no clear or delete command. At 40,000 or more records there is a warning; at 50,000 a louder one. After a manual clear the agent posts only what is there, with no loss.
- S-13 (P2) User list. New device users are listed, known employees are never changed, and a deleted device user does not touch the employee. UTF-8 names (Peña, Ñ) survive.
- S-14 (P2) Install. The task runs at boot with nobody signed in. The monthly CSV rolls over. The log rotates. The key file ACL is SYSTEM and Administrators only.

## Threat model and risk
Untrusted: anything on the network can call /api/biometrics/*. Only the key separates it from writing attendance, which drives pay. Trusted: HR users behind the existing login, and the office PC holding the key file.
Risk tier: HIGH, because writes reach payroll. Run a skeptic review of the key check, the lock, held-punch application, date validation, and "agent never clears". Name what is untrusted in the result.

## Done when
- Every P1 case has a test that failed first and then passed. Feature tests cover the endpoints.
- The agent is tested against a fake ZKTeco server on localhost: a PowerShell TcpListener that speaks the protocol. Follow the pattern of the reference reader, and include packet checksums and CMD_AUTH. Cover the poll, post, outbox and clock-sync paths, with a fake Likha HR, or the real app served locally with php artisan serve.
- The full suite adds no new failures against ad139cc.
- The manual upload tests stay green.
- `git grep -n -E "Cmd 1[45] |CLEAR" -- tools/biometrics-agent` shows no clear command is ever sent.
- The result has: deploy notes (migration, the env key name, route/config cache), an install guide for the office PC in plain steps, and the exact commands.

## Out of scope
- Deploying, and setting any real key.
- Clearing the device.
- The duplicate-row backlog in attendance_logs (separate job).
- Payroll changes.
- The catch-up of Oct 26 – Nov 10: it will be done by a manual upload of a converted file. Make sure that upload holds unmatched IDs per decision 4.

## Budget
Large job. At most 2 attempts at the same failing step. Then stop and write into the result what you tried and what you need.
