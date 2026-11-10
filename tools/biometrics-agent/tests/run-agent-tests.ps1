<#
  Agent tests (spec 012). Runs the real agent.ps1 against:
    - mock/fake-zk.ps1, a fake ZKTeco device on 127.0.0.1:14370 that speaks the protocol
      (packet checksums, CMD_AUTH with the comm key, buffered reads, get/set time);
    - the real Likha HR app of this worktree, served by "php -S" on 127.0.0.1:18123 with a
      throw-away SQLite database and a test-only key (nothing of any .env is read or used);
    - mock/fake-http.ps1 on 127.0.0.1:18124 for a Likha HR that answers 500.
  Usage, from the repository root:
    powershell -NoProfile -ExecutionPolicy Bypass -File tools\biometrics-agent\tests\run-agent-tests.ps1
  Exit code 0 when every check passed.
#>
param([switch]$KeepTemp, [string]$AgentPath = '')

$ErrorActionPreference = 'Stop'
$agentDir = Split-Path $PSScriptRoot
$repo = (Resolve-Path (Join-Path $agentDir '..\..')).Path
$agent = if ($AgentPath) { $AgentPath } else { Join-Path $agentDir 'agent.ps1' }
$psExe = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'
$php = (& php -r 'echo PHP_BINARY;')
$root = Join-Path $env:TEMP ('likha-agent-tests-' + [Guid]::NewGuid().ToString('N').Substring(0, 8))
New-Item -ItemType Directory -Path $root | Out-Null

$HR = 'http://127.0.0.1:18123/api/biometrics'
$ZK_PORT = 14370
$testKey = 'agent-test-only-key-' + [Guid]::NewGuid().ToString('N')
$results = New-Object System.Collections.ArrayList
$procs = New-Object System.Collections.ArrayList

function Check([string]$case, [bool]$ok, [string]$detail = '') {
    [void]$results.Add([pscustomobject]@{ case = $case; ok = $ok })
    $mark = if ($ok) { 'PASS' } else { 'FAIL' }
    Write-Host ("{0}  {1}{2}" -f $mark, $case, $(if ($detail) { "  [$detail]" } else { '' }))
}

function Wait-Port([int]$port, [int]$seconds) {
    $until = (Get-Date).AddSeconds($seconds)
    while ((Get-Date) -lt $until) {
        $c = New-Object Net.Sockets.TcpClient
        try { $c.Connect('127.0.0.1', $port); $c.Close(); return $true } catch { Start-Sleep -Milliseconds 300 } finally { $c.Close() }
    }
    return $false
}

$manilaNow = [DateTime]::UtcNow.AddHours(8)
function MDate([int]$days) { return $manilaNow.AddDays($days).ToString('yyyy-MM-dd') }
$smallEnye = [string][char]0x00F1  # PowerShell names ignore case: two names, not $enye and $ENYE
$capitalEnye = [string][char]0x00D1
$juan = 'Juan Pe' + $smallEnye + 'a'
$nando = $capitalEnye + 'ANDO'

# ------------------------------------------------------------------ the app and the fakes

$db = Join-Path $root 'app.sqlite'
[IO.File]::WriteAllText($db, '')
$env:APP_ENV = 'local'; $env:APP_DEBUG = 'false'
$env:APP_KEY = 'base64:' + [Convert]::ToBase64String((1..32 | ForEach-Object { [byte](Get-Random -Maximum 256) }))
$env:DB_CONNECTION = 'sqlite'; $env:DB_DATABASE = $db
$env:BIOMETRICS_FEED_KEY = $testKey
$env:CACHE_DRIVER = 'file'; $env:SESSION_DRIVER = 'array'; $env:QUEUE_CONNECTION = 'sync'; $env:LOG_CHANNEL = 'null'

$query = Join-Path $root 'query.php'
[IO.File]::WriteAllText($query, @'
<?php
$p = new PDO('sqlite:' . $argv[1]);
$p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
echo json_encode($p->query($argv[2])->fetchAll(PDO::FETCH_ASSOC));
'@)
function Q([string]$sql) { return @((& $php $query $db $sql) | ConvertFrom-Json) }
function N([string]$sql) { return [int](Q $sql)[0].n }

Push-Location $repo
try {
    & $php artisan migrate --force --no-interaction | Out-Null
    & $php artisan cache:clear | Out-Null
} finally { Pop-Location }
& $php $query $db "INSERT INTO shifts (name, start_time, end_time, lunch_start, lunch_end, required_work_minutes, created_at, updated_at) VALUES ('Day', '08:00:00', '17:00:00', '12:00:00', '13:00:00', 480, '2026-11-01 00:00:00', '2026-11-01 00:00:00')" | Out-Null
foreach ($e in @(@('1', 'One Tester'), @('2', 'Two Tester'), @('3', 'Three Tester'))) {
    & $php $query $db ("INSERT INTO employees (zkteco_id, full_name, status, default_shift_id, created_at, updated_at) VALUES ('{0}', '{1}', 'active', 1, '2026-11-01 00:00:00', '2026-11-01 00:00:00')" -f $e[0], $e[1]) | Out-Null
}

$serverOut = Join-Path $root 'php-server.log'
$router = Join-Path $repo 'vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php'
[void]$procs.Add((Start-Process -FilePath $php -ArgumentList @('-S', '127.0.0.1:18123', "`"$router`"") -WorkingDirectory (Join-Path $repo 'public') `
    -PassThru -WindowStyle Hidden -RedirectStandardError $serverOut -RedirectStandardOutput (Join-Path $root 'php-server.out')))

$scenario = Join-Path $root 'scenario.json'
$zkLog = Join-Path $root 'zk.log'
function Set-Scenario($records, $users, [string]$fault = 'none', [int]$offset = 0) {
    $o = [ordered]@{ comm_key = 0; fault = $fault; device_offset_s = $offset; users = @($users); records = @($records) }
    [IO.File]::WriteAllText($scenario, ($o | ConvertTo-Json -Depth 5), (New-Object Text.UTF8Encoding($false)))
}
function Rec([string]$id, [string]$time) { return [ordered]@{ user_id = $id; time = $time; status = 0; punch = 1 } }
$users = @(
    [ordered]@{ uid = 1; user_id = '1'; name = 'One Tester' },
    [ordered]@{ uid = 2; user_id = '2'; name = 'Two Tester' },
    [ordered]@{ uid = 3; user_id = '3'; name = 'Three Tester' },
    [ordered]@{ uid = 4; user_id = '54'; name = $juan },
    [ordered]@{ uid = 5; user_id = '55'; name = $nando }
)
Set-Scenario @() $users
[void]$procs.Add((Start-Process -FilePath $psExe -ArgumentList @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', "`"$(Join-Path $agentDir 'mock\fake-zk.ps1')`"",
    '-Port', $ZK_PORT, '-Scenario', "`"$scenario`"", '-Log', "`"$zkLog`"") -PassThru -WindowStyle Hidden))

$httpCount = Join-Path $root 'fake-http.count'
[void]$procs.Add((Start-Process -FilePath $psExe -ArgumentList @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', "`"$(Join-Path $agentDir 'mock\fake-http.ps1')`"",
    '-Port', 18124, '-Status', 500, '-CountFile', "`"$httpCount`"") -PassThru -WindowStyle Hidden))

function Get-Status {
    return Invoke-RestMethod -Uri "$HR/status" -Headers @{ 'X-BIOMETRICS-FEED-KEY' = $testKey } -TimeoutSec 20
}

function New-AgentConfig([string]$name, [hashtable]$over = @{}) {
    $dir = Join-Path $root $name
    New-Item -ItemType Directory -Path $dir -Force | Out-Null
    $c = [ordered]@{ base_url = $HR; agent_id = 'office-pc'; device_ip = '127.0.0.1'; device_port = $ZK_PORT; comm_key = 0;
                     data_dir = $dir; key_file = (Join-Path $dir 'feed.key'); clock_sync = $false; device_timeout_ms = 5000; http_timeout_ms = 20000;
                     allow_http = $true }
    foreach ($k in $over.Keys) { $c[$k] = $over[$k] }
    [IO.File]::WriteAllText((Join-Path $dir 'feed.key'), $testKey)
    $path = Join-Path $dir 'config.json'
    [IO.File]::WriteAllText($path, ($c | ConvertTo-Json), (New-Object Text.UTF8Encoding($false)))
    return $path
}

# Likha HR allows 60 API calls a minute per address (the general api limit). A real agent makes
# 2 or 3 a minute; these tests run it back to back, so they space the runs to stay under it.
$script:lastRun = [DateTime]::MinValue
function Run-Agent([string]$config, [string]$nowUtc = '') {
    $wait = 6 - ((Get-Date) - $script:lastRun).TotalSeconds
    if ($wait -gt 0) { Start-Sleep -Milliseconds ([int]($wait * 1000)) }
    $script:lastRun = Get-Date
    $a = @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $agent, '-ConfigPath', $config)
    if ($nowUtc) { $a += @('-NowUtc', $nowUtc) }
    & $psExe @a | Out-Null
    return $LASTEXITCODE
}

function Read-AgentState([string]$config) {
    $dir = Split-Path $config
    return ([IO.File]::ReadAllText((Join-Path $dir 'state.json')) | ConvertFrom-Json)
}
function ZkLog { if (Test-Path $zkLog) { return @(Get-Content $zkLog) } else { return @() } }
function Count-Logs([string]$zk) { return N ("SELECT count(*) AS n FROM attendance_logs l JOIN employees e ON e.id = l.employee_id WHERE e.zkteco_id = '$zk'") }

try {
    if (-not (Wait-Port 18123 30)) { throw 'The app did not start on 18123' }
    if (-not (Wait-Port $ZK_PORT 30)) { throw 'The fake device did not start' }
    if (-not (Wait-Port 18124 30)) { throw 'The fake 500 server did not start' }
    $null = Get-Status

    # ---------------------------------------------------------- A. poll and post
    $D1 = MDate -1
    $recsA = @(
        (Rec '1' "$D1 08:00:00"), (Rec '1' "$D1 12:00:00"), (Rec '1' "$D1 13:00:00"), (Rec '1' "$D1 17:00:00"),
        (Rec '54' "$D1 08:05:00"), (Rec '54' "$D1 17:01:00")
    )
    Set-Scenario $recsA $users
    $cfgA = New-AgentConfig 'a'
    $code = Run-Agent $cfgA
    Check 'A poll: the run ends cleanly' ($code -eq 0) "exit $code"
    Check 'A post: the 4 punches of zk 1 are stored' ((Count-Logs '1') -eq 4)
    Check 'A post: the day of zk 1 is computed' ((N "SELECT count(*) AS n FROM attendance_days d JOIN employees e ON e.id = d.employee_id WHERE e.zkteco_id = '1' AND substr(d.work_date, 1, 10) = '$D1'") -eq 1)
    Check 'A post: the 2 punches of zk 54 (no employee) are held' ((N "SELECT count(*) AS n FROM biometric_feed WHERE kind = 'held' AND key = '54'") -eq 2)
    $du = Q "SELECT key, value FROM biometric_feed WHERE kind = 'device_user' ORDER BY key"
    Check 'A users: device users not in HR are listed with UTF-8 names (S-13)' ($du.Count -eq 2 -and $du[0].value -ceq $juan -and $du[1].value -ceq $nando) (($du | ForEach-Object { $_.key + '=' + $_.value }) -join '; ')
    Check 'A users: no employee was created or renamed' ((N "SELECT count(*) AS n FROM employees") -eq 3 -and (N "SELECT count(*) AS n FROM employees WHERE full_name = 'One Tester'") -eq 1)
    $st = Get-Status
    Check 'A heartbeat: last contact, device reachable, record count' ($st.last_contact_at -and $st.device_reachable -eq $true -and $st.device_record_count -eq 6) ("records " + $st.device_record_count)
    $stateA = Read-AgentState $cfgA
    Check 'A state: the last acknowledged punch is kept' ($stateA.last_ack -eq "$D1 17:01:00") ([string]$stateA.last_ack)
    Check 'A outbox: empty after a good post' (-not (Test-Path (Join-Path (Split-Path $cfgA) 'outbox.txt')))
    $csv = Join-Path (Split-Path $cfgA) ('archive\attlog-' + $D1.Substring(0, 7) + '.csv')
    Check 'A archive: the monthly CSV holds the 6 records once' ((Test-Path $csv) -and (@(Get-Content $csv).Count -eq 7))
    $z = ZkLog
    Check 'A protocol: connect, CMD_AUTH, sizes, buffered reads and exit were used' (
        ($z -contains 'cmd=1000') -and ($z -contains 'cmd=1102') -and ($z -contains 'auth ok') -and ($z -contains 'cmd=50') -and
        ($z -contains 'cmd=1503') -and ($z -contains 'cmd=1504') -and ($z -contains 'cmd=1502') -and ($z -contains 'cmd=1001'))
    Check 'A protocol: every packet the agent sent had a right checksum' (-not ($z | Where-Object { $_ -like 'bad checksum*' }))

    # ---------------------------------------------------------- B. the next minute's re-read
    $runsBefore = N "SELECT count(*) AS n FROM attendance_import_runs WHERE stats_json NOT LIKE '%quiet%'"
    $code = Run-Agent $cfgA
    Check 'B re-read: stores nothing new (S-03)' ($code -eq 0 -and (Count-Logs '1') -eq 4 -and (N "SELECT count(*) AS n FROM biometric_feed WHERE kind = 'held'") -eq 2)
    Check 'B re-read: no new visible run on the Import page' ((N "SELECT count(*) AS n FROM attendance_import_runs WHERE stats_json NOT LIKE '%quiet%'") -eq $runsBefore)
    Check 'B archive: still 6 records, no duplicates' (@(Get-Content $csv).Count -eq 7)

    # ---------------------------------------------------------- I. a garbled record is dropped
    $D0 = MDate 0
    Set-Scenario (@($recsA) + @((Rec ([string][char]1 + 'x') "$D0 07:00:00"))) $users
    $code = Run-Agent $cfgA
    $logA = Get-Content (Join-Path (Split-Path $cfgA) 'logs\agent.log') -Raw
    Check 'I garbled record: dropped and logged, never posted (S-10)' ($code -eq 0 -and $logA -match '1 garbled dropped' -and (N "SELECT count(*) AS n FROM attendance_logs WHERE punched_at = '$D0 07:00:00'") -eq 0)

    # ---------------------------------------------------------- F/G/H. garbled reply, half reply, busy, dropped connection
    $faultTimes = @{ garbled = '06:40:00'; half = '06:44:00'; busy = '06:48:00'; drop = '06:52:00' }
    foreach ($fault in @('garbled', 'half', 'busy', 'drop')) {
        $ft = "$D0 " + $faultTimes[$fault]
        $recsF = @($recsA) + @((Rec '1' $ft))
        Set-Scenario $recsF $users $fault
        $authBefore = @(ZkLog | Where-Object { $_ -eq 'auth ok' }).Count
        $code = Run-Agent $cfgA
        $stF = Get-Status
        Check "S-10 $fault reply: the run fails cleanly, nothing is posted, the device shows unreachable" (
            $code -eq 0 -and (N "SELECT count(*) AS n FROM attendance_logs WHERE punched_at = '$ft'") -eq 0 -and $stF.device_reachable -eq $false -and
            (@($stF.warnings | Where-Object { $_.code -eq 'device_unreachable' }).Count -eq 1) -and (@($stF.warnings | Where-Object { $_.code -eq 'no_contact' }).Count -eq 0)) "exit $code"
        Set-Scenario $recsF $users 'none'
        $code = Run-Agent $cfgA
        $authAfter = @(ZkLog | Where-Object { $_ -eq 'auth ok' }).Count
        Check "S-10 after $fault`: the next poll reconnects, re-auths and posts" ($code -eq 0 -and $authAfter -gt $authBefore -and
            (N "SELECT count(*) AS n FROM attendance_logs WHERE punched_at = '$ft'") -eq 1 -and (Get-Status).device_reachable -eq $true)
    }

    # ---------------------------------------------------------- G2. a punch lands while the poll runs
    $ftGrow = "$D0 06:56:00"
    Set-Scenario (@($recsA) + @((Rec '1' $ftGrow))) $users 'grow'
    $code = Run-Agent $cfgA
    Check 'S-10 a punch made during the poll: the read still succeeds and posts it' ($code -eq 0 -and
        (N "SELECT count(*) AS n FROM attendance_logs WHERE punched_at = '$ftGrow'") -eq 1 -and (Get-Status).device_reachable -eq $true)

    # ---------------------------------------------------------- S. setup errors stop the run, nothing is sent
    $cfgS = New-AgentConfig 's' @{ agent_id = 'Office PC' }
    $code = Run-Agent $cfgS
    $logS = Get-Content (Join-Path (Split-Path $cfgS) 'logs\agent.log') -Raw
    Check 'S a bad agent id in the config: the run stops with an error and posts nothing' ($code -eq 1 -and $logS -match 'agent_id' -and
        -not (Test-Path (Join-Path (Split-Path $cfgS) 'state.json')))
    $cfgH = New-AgentConfig 'h' @{ allow_http = $false }
    $code = Run-Agent $cfgH
    $logH = Get-Content (Join-Path (Split-Path $cfgH) 'logs\agent.log') -Raw
    Check 'S plain http without allow_http: refused, the key is not sent' ($code -eq 1 -and $logH -match 'https')

    # ---------------------------------------------------------- C. after a manual clear of the device
    $recsC = @((Rec '2' "$D0 06:10:00"), (Rec '2' "$D0 06:30:00"))
    $total = N "SELECT count(*) AS n FROM attendance_logs"
    Set-Scenario $recsC $users
    $code = Run-Agent $cfgA
    Check 'C after a manual clear: only what is there is posted, nothing is lost (S-11)' ($code -eq 0 -and (N "SELECT count(*) AS n FROM attendance_logs") -eq ($total + 2) -and (Get-Status).device_record_count -eq 2)

    # ---------------------------------------------------------- D. no internet: outbox, reboot, send
    $cfgD = New-AgentConfig 'd' @{ base_url = 'http://127.0.0.1:18199/api/biometrics' }
    $recsD = @((Rec '3' "$D0 06:15:00"), (Rec '3' "$D0 06:45:00"))
    Set-Scenario $recsD $users
    $code = Run-Agent $cfgD
    $outbox = Join-Path (Split-Path $cfgD) 'outbox.txt'
    Check 'D no internet: the posts wait in the outbox file (S-05)' ($code -eq 0 -and (Test-Path $outbox) -and (@(Get-Content $outbox).Count -eq 2) -and (Count-Logs '3') -eq 0)
    $sD = Read-AgentState $cfgD
    Check 'D no internet: one failure counted, a next try set' ([int]$sD.fail_count -eq 1 -and $sD.next_try_utc)
    # A reboot is a new process; the device was cleared meanwhile, so only the outbox has the punches.
    Set-Scenario @() $users
    $c = Get-Content $cfgD -Raw | ConvertFrom-Json
    $c.base_url = $HR
    [IO.File]::WriteAllText($cfgD, ($c | ConvertTo-Json), (New-Object Text.UTF8Encoding($false)))
    $code = Run-Agent $cfgD ([DateTime]::UtcNow.AddMinutes(3).ToString('o'))
    Check 'D after the reboot: the outbox is sent once and emptied (S-05)' ($code -eq 0 -and (Count-Logs '3') -eq 2 -and -not (Test-Path $outbox))

    # ---------------------------------------------------------- E. Likha HR answers 500: back off
    $cfgE = New-AgentConfig 'e' @{ base_url = 'http://127.0.0.1:18124/api/biometrics' }
    Set-Scenario $recsC $users
    if (Test-Path $httpCount) { Remove-Item $httpCount }
    $t0 = [DateTime]::UtcNow
    foreach ($s in @(0, 60, 120, 180, 240, 300)) { [void](Run-Agent $cfgE ($t0.AddSeconds($s).ToString('o'))) }
    $hits = if (Test-Path $httpCount) { @(Get-Content $httpCount).Count } else { 0 }
    Check 'E 5xx: six runs in six minutes call Likha HR only 3 times (backoff 1, 2, 4 min) (S-05)' ($hits -eq 3) "calls $hits"
    Check 'E 5xx: the lines stay in the outbox' ((Test-Path (Join-Path (Split-Path $cfgE) 'outbox.txt')))

    # ---------------------------------------------------------- Q. the PC was off for 3 days
    $cfgQ = New-AgentConfig 'q'
    $now = [DateTime]::UtcNow.AddHours(8)
    $times = @($now.AddHours(-71), $now.AddHours(-50), $now.AddHours(-27), $now.AddHours(-26)) | ForEach-Object { $_.ToString('yyyy-MM-dd HH:mm:') + '17' }
    [IO.File]::WriteAllText((Join-Path (Split-Path $cfgQ) 'state.json'), (@{ last_ack = $now.AddDays(-3).AddHours(-1).ToString('yyyy-MM-dd HH:mm:ss'); fail_count = 0 } | ConvertTo-Json))
    Set-Scenario @($times | ForEach-Object { Rec '2' $_ }) $users
    $before2 = Count-Logs '2'
    $code = Run-Agent $cfgQ
    $dates = @($times | ForEach-Object { $_.Substring(0, 10) } | Sort-Object -Unique)
    $daysOk = $true
    foreach ($d in $dates) { if ((N "SELECT count(*) AS n FROM attendance_days x JOIN employees e ON e.id = x.employee_id WHERE e.zkteco_id = '2' AND substr(x.work_date, 1, 10) = '$d'") -ne 1) { $daysOk = $false } }
    Check 'Q 3 days off: the backlog is posted once and the touched days computed (S-05)' ($code -eq 0 -and (Count-Logs '2') -eq ($before2 + 4) -and $daysOk) ("dates " + ($dates -join ','))
    [void](Run-Agent $cfgQ)
    Check 'Q 3 days off: the next poll stores nothing twice' ((Count-Logs '2') -eq ($before2 + 4))

    # ---------------------------------------------------------- Q5. the PC was off for 5 days (amendment 012-1)
    # The window reaches back to the last acknowledged punch minus a day, so no -Since is needed.
    $cfgQ5 = New-AgentConfig 'q5'
    $now = [DateTime]::UtcNow.AddHours(8)
    $times5 = @($now.AddHours(-119), $now.AddHours(-100), $now.AddHours(-80), $now.AddHours(-55), $now.AddHours(-31)) |
        ForEach-Object { $_.ToString('yyyy-MM-dd HH:mm:') + '41' }
    [IO.File]::WriteAllText((Join-Path (Split-Path $cfgQ5) 'state.json'), (@{ last_ack = $now.AddDays(-5).AddHours(-1).ToString('yyyy-MM-dd HH:mm:ss'); fail_count = 0 } | ConvertTo-Json))
    Set-Scenario @($times5 | ForEach-Object { Rec '3' $_ }) $users
    $before3 = Count-Logs '3'
    $code = Run-Agent $cfgQ5
    $dates5 = @($times5 | ForEach-Object { $_.Substring(0, 10) } | Sort-Object -Unique)
    $days5 = $true
    foreach ($d in $dates5) { if ((N "SELECT count(*) AS n FROM attendance_days x JOIN employees e ON e.id = x.employee_id WHERE e.zkteco_id = '3' AND substr(x.work_date, 1, 10) = '$d'") -ne 1) { $days5 = $false } }
    Check 'Q5 5 days off: all 5 days are posted by themselves, no -Since, and computed (amendment 012-1)' ($code -eq 0 -and (Count-Logs '3') -eq ($before3 + 5) -and $days5) ("stored " + ((Count-Logs '3') - $before3) + " of 5; dates " + ($dates5 -join ','))
    [void](Run-Agent $cfgQ5)
    Check 'Q5 5 days off: the next poll stores nothing twice' ((Count-Logs '3') -eq ($before3 + 5))

    # ---------------------------------------------------------- J. clock: PC within 60 s, device clock set
    $clockFile = $zkLog + '.clock'
    if (Test-Path $clockFile) { Remove-Item $clockFile }
    Set-Scenario $recsC $users 'none' -300
    $cfgJ = New-AgentConfig 'j' @{ clock_sync = $true; clock_check_at = '00:00' }
    $code = Run-Agent $cfgJ
    $stJ = Get-Status
    $setOk = $false
    if (Test-Path $clockFile) {
        $setTo = [DateTime]::ParseExact(((Get-Content $clockFile)[0] -replace '^set_to=', ''), 'yyyy-MM-dd HH:mm:ss', $null)
        $setOk = [Math]::Abs(($setTo - [DateTime]::UtcNow.AddHours(8)).TotalSeconds) -le 15
    }
    Check 'J clock within 60 s: the device clock is set to Manila time (S-06)' ($code -eq 0 -and $setOk)
    Check 'J clock within 60 s: the drift is reported and logged, no warning' ($null -ne $stJ.clock_drift_seconds -and [Math]::Abs([int]$stJ.clock_drift_seconds) -le 5 -and $stJ.clock_set -eq $true -and
        (@($stJ.warnings | Where-Object { $_.code -eq 'clock_not_set' }).Count -eq 0) -and (N "SELECT count(*) AS n FROM biometric_feed WHERE kind = 'drift'") -eq 1) ("drift " + $stJ.clock_drift_seconds)
    $sJ = Read-AgentState $cfgJ
    Check 'J clock: done once for today' ($sJ.clock_done_date -eq $manilaNow.ToString('yyyy-MM-dd') -or $sJ.clock_done_date -eq [DateTime]::UtcNow.AddHours(8).ToString('yyyy-MM-dd'))
    $setsBefore = @(ZkLog | Where-Object { $_ -like 'clock set_to=*' }).Count
    [void](Run-Agent $cfgJ)
    Check 'J clock: a second run the same day does not set it again' (@(ZkLog | Where-Object { $_ -like 'clock set_to=*' }).Count -eq $setsBefore)

    # ---------------------------------------------------------- K. clock: PC 2 minutes off
    Remove-Item $clockFile
    $cfgK = New-AgentConfig 'k' @{ clock_sync = $true; clock_check_at = '00:00' }
    $code = Run-Agent $cfgK ([DateTime]::UtcNow.AddSeconds(120).ToString('o'))
    $stK = Get-Status
    Check 'K PC 2 minutes off: the device clock is untouched (S-06)' ($code -eq 0 -and -not (Test-Path $clockFile))
    Check 'K PC 2 minutes off: the drift and a warning are shown (S-06)' ([int]$stK.clock_drift_seconds -ge 115 -and [int]$stK.clock_drift_seconds -le 125 -and $stK.clock_set -eq $false -and
        @($stK.warnings | Where-Object { $_.code -eq 'clock_not_set' }).Count -eq 1) ("drift " + $stK.clock_drift_seconds)

    # ---------------------------------------------------------- L. clock: Likha HR unreachable at 06:00, retried later
    $cfgL = New-AgentConfig 'l' @{ clock_sync = $true; clock_check_at = '00:00'; base_url = 'http://127.0.0.1:18199/api/biometrics' }
    $code = Run-Agent $cfgL
    $sL = Read-AgentState $cfgL
    Check 'L server unreachable at clock time: not done, not set (S-06)' ($code -eq 0 -and -not $sL.clock_done_date -and -not (Test-Path $clockFile))
    # Later that day: the backoff and the 15-minute clock retry have passed, Likha HR is back.
    $c = Get-Content $cfgL -Raw | ConvertFrom-Json
    $c.base_url = $HR
    [IO.File]::WriteAllText($cfgL, ($c | ConvertTo-Json), (New-Object Text.UTF8Encoding($false)))
    $sL.next_try_utc = $null; $sL.clock_next_try_utc = $null; $sL.fail_count = 0
    [IO.File]::WriteAllText((Join-Path (Split-Path $cfgL) 'state.json'), ($sL | ConvertTo-Json))
    $code = Run-Agent $cfgL
    $sL = Read-AgentState $cfgL
    Check 'L later that day: the clock check is retried and the device clock set (S-06)' ($code -eq 0 -and $sL.clock_done_date -and (Test-Path $clockFile))

    # ---------------------------------------------------------- O, P. log rotation, monthly CSV
    $cfgO = New-AgentConfig 'o'
    $logDir = Join-Path (Split-Path $cfgO) 'logs'
    New-Item -ItemType Directory -Path $logDir -Force | Out-Null
    [IO.File]::WriteAllText((Join-Path $logDir 'agent.log'), ('x' * 1100000))
    $lastMonth = $manilaNow.AddMonths(-1).ToString('yyyy-MM') + '-15 09:00:00'
    Set-Scenario (@($recsC) + @((Rec '2' $lastMonth))) $users
    $code = Run-Agent $cfgO
    Check 'O the log rotates past 1 MB (S-14)' ($code -eq 0 -and (Test-Path (Join-Path $logDir 'agent.1.log')) -and (Get-Item (Join-Path $logDir 'agent.log')).Length -lt 20000)
    $arch = @(Get-ChildItem (Join-Path (Split-Path $cfgO) 'archive') -Filter 'attlog-*.csv' | ForEach-Object { $_.Name } | Sort-Object)
    Check 'P the CSV archive rolls over by month (S-14)' ($arch.Count -eq 2 -and $arch[0] -eq ('attlog-' + $lastMonth.Substring(0, 7) + '.csv')) ($arch -join ', ')

    # ---------------------------------------------------------- M. never a delete or wipe command
    $z = ZkLog
    $codes = @($z | Where-Object { $_ -match '^(unknown )?cmd=(\d+)$' } | ForEach-Object { [int]($_ -replace '^(unknown )?cmd=', '') } | Sort-Object -Unique)
    $allowed = @(1000, 1001, 1102, 50, 201, 202, 1503, 1504, 1502)
    Check 'M the device only ever received read and clock commands (S-11)' ((@($codes | Where-Object { $allowed -notcontains $_ }).Count -eq 0) -and -not ($z | Where-Object { $_ -like 'unknown*' })) ("commands " + ($codes -join ','))
    # The agent's own send function, loaded without running a poll: the wipe and delete codes
    # (14, 15, 18) and anything else outside the read and clock list never get a byte out.
    . $agent -LoadOnly
    $refusedAll = $true
    foreach ($bad in @(14, 15, 18, 1013, 13)) {
        $refused = $false
        try { Send-ZkPacket @{ Reply = 1; Session = 1; Stream = $null } $bad $null }
        catch { $refused = $_.Exception.Message -like "Refused to send device command $bad*" }
        if (-not $refused) { $refusedAll = $false }
    }
    Check 'M the send function refuses every command outside the allow list (S-11)' $refusedAll

    # ---------------------------------------------------------- N. the key never leaves the key file
    $leak = @(Get-ChildItem $root -Recurse -File | Where-Object { $_.Name -ne 'feed.key' -and $_.Name -notlike '*.sqlite*' } |
        Select-String -SimpleMatch $testKey -List | ForEach-Object { $_.Path })
    Check 'N the key appears in no log, CSV, state, outbox or server log (S-14)' ($leak.Count -eq 0) ($leak -join ', ')

    # ---------------------------------------------------------- R. install helpers
    . (Join-Path $agentDir 'InstallHelpers.ps1')
    $kf = Join-Path $root 'acl-test.key'
    [IO.File]::WriteAllText($kf, '')
    Protect-Path $kf
    $acl = Get-Acl -LiteralPath $kf
    $rules = @($acl.Access | ForEach-Object { $_.IdentityReference.Translate([Security.Principal.SecurityIdentifier]).Value + ':' + $_.FileSystemRights + ':' + $_.AccessControlType } | Sort-Object)
    Check 'R the key file ACL is SYSTEM and Administrators only, not inherited (S-14)' ($acl.AreAccessRulesProtected -and $rules.Count -eq 2 -and
        $rules[0] -eq 'S-1-5-18:FullControl:Allow' -and $rules[1] -eq 'S-1-5-32-544:FullControl:Allow') ($rules -join ' ')
    & icacls $kf /reset /Q | Out-Null
    Remove-Item -LiteralPath $kf -Force
    $task = New-AgentTask 'C:\Program Files\LikhaBiometrics\agent.ps1' 'C:\ProgramData\LikhaBiometrics\config.json'
    $boot = @($task.Triggers | Where-Object { $_.CimClass.CimClassName -eq 'MSFT_TaskBootTrigger' })
    Check 'R the task runs as SYSTEM at boot, every minute, one at a time, restarts on failure (S-14)' (
        $task.Principal.UserId -eq 'SYSTEM' -and [string]$task.Principal.LogonType -eq 'ServiceAccount' -and [string]$task.Principal.RunLevel -eq 'Highest' -and
        $boot.Count -eq 1 -and $boot[0].Repetition.Interval -eq 'PT1M' -and [string]$task.Settings.MultipleInstances -eq 'IgnoreNew' -and
        [int]$task.Settings.RestartCount -eq 3 -and $task.Actions[0].Arguments -notmatch 'key')
} catch {
    Check 'the run of the checks went on to the end' $false ('stopped: ' + $_.Exception.Message)
} finally {
    foreach ($p in $procs) { try { & taskkill /PID $p.Id /T /F 2>&1 | Out-Null } catch { } }
    $failed = @($results | Where-Object { -not $_.ok }).Count
    Write-Host ''
    Write-Host ("Agent tests: {0} passed, {1} failed" -f (@($results | Where-Object { $_.ok }).Count), $failed)
    if (-not $KeepTemp) { Start-Sleep -Milliseconds 500; Remove-Item -LiteralPath $root -Recurse -Force -ErrorAction SilentlyContinue }
    else { Write-Host "Kept: $root" }
}
if ($failed -gt 0 -or $results.Count -eq 0) { exit 1 }
exit 0
