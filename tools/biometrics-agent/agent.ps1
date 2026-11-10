<#
  Likha HR biometrics agent (spec 012). PowerShell 5.1, no modules.

  One run = one poll. The scheduled task starts it every minute (install.ps1):
    1. read the office ZKTeco device: connect, auth, sizes, users, attendance log, disconnect;
    2. keep every record in a monthly CSV archive;
    3. post the records of the window (decision 1) to Likha HR in batches of at most 1,000
       lines, through a local outbox that survives an outage or a reboot;
    4. send a heartbeat, the user list (hourly, or when it changed) and, once a day from
       06:00 Manila, check the PC clock against Likha HR and set the device clock when they
       agree within 60 seconds (decision 9).
  It never sends a command that deletes or wipes anything on the device: ZkProtocol.ps1
  allows only the read and clock commands.

  The key is read from the key file (install.ps1 writes it, readable by SYSTEM and
  Administrators only). It is never logged, written to the CSV or passed on a command line.

  Usage:
    agent.ps1                       one poll with C:\ProgramData\LikhaBiometrics\config.json
    agent.ps1 -Test                 read the device and print what would be posted; post nothing
    agent.ps1 -Since 2026-10-26     also post every record since that date (a one-off catch-up)
    -ConfigPath <file>              another config file
    -NowUtc <ISO time>              tests only: the time this PC's clock shows
    -LoadOnly                       tests only: define the functions, do nothing
#>
param(
    [string]$ConfigPath = 'C:\ProgramData\LikhaBiometrics\config.json',
    [switch]$Test,
    [string]$Since,
    [string]$NowUtc,
    [switch]$LoadOnly
)

$ErrorActionPreference = 'Stop'
$AgentVersion = '1.0.0'
. (Join-Path $PSScriptRoot 'ZkProtocol.ps1')

# ---------------------------------------------------------------- clock and config

$script:realStart = [DateTime]::UtcNow
$script:fakeBase = $null
if ($NowUtc) {
    $script:fakeBase = [DateTime]::Parse($NowUtc, [Globalization.CultureInfo]::InvariantCulture,
        [Globalization.DateTimeStyles]::AdjustToUniversal -bor [Globalization.DateTimeStyles]::AssumeUniversal)
}

# What this PC's clock says, in UTC.
function Get-PcUtc {
    if ($null -ne $script:fakeBase) { return $script:fakeBase + ([DateTime]::UtcNow - $script:realStart) }
    return [DateTime]::UtcNow
}

# Manila has no daylight saving: UTC+8 always, whatever time zone this PC is set to.
function Get-PcManila { return (Get-PcUtc).AddHours(8) }

function Read-Config([string]$path) {
    if (-not (Test-Path -LiteralPath $path)) { throw "Config file not found: $path" }
    $raw = [IO.File]::ReadAllText($path, [Text.Encoding]::UTF8) | ConvertFrom-Json
    $c = @{
        base_url             = ''
        agent_id             = 'office-pc'
        device_ip            = '192.168.1.201'
        device_port          = 4370
        comm_key             = 0
        data_dir             = 'C:\ProgramData\LikhaBiometrics'
        key_file             = ''
        device_timeout_ms    = 20000
        http_timeout_ms      = 30000
        check_reply_checksum = $true
        clock_sync           = $true
        clock_check_at       = '06:00'
        max_clock_drift_s    = 60
        window_days          = 3
        batch_size           = 1000
        users_every_minutes  = 60
        log_max_bytes        = 1048576
        log_keep             = 5
    }
    foreach ($p in $raw.PSObject.Properties) { $c[$p.Name] = $p.Value }
    if (-not $c.base_url) { throw 'Config: base_url is missing' }
    $c.base_url = ([string]$c.base_url).TrimEnd('/')
    # The key travels in a header: plain http would show it to the network.
    if ($c.base_url -notmatch '^https://' -and -not $c.allow_http) { throw 'Config: base_url must start with https://' }
    if ([string]$c.agent_id -notmatch '^[A-Za-z0-9._-]{1,64}$') { throw 'Config: agent_id may hold only letters, digits, dot, dash and underscore' }
    if (-not $c.key_file) { $c.key_file = Join-Path $c.data_dir 'feed.key' }
    if ([int]$c.batch_size -gt 1000 -or [int]$c.batch_size -lt 1) { $c.batch_size = 1000 }
    return $c
}

# ---------------------------------------------------------------- log, state, files

function Write-Log([string]$message) {
    $line = '{0} {1}' -f (Get-PcManila).ToString('yyyy-MM-dd HH:mm:ss'), $message
    try { [IO.File]::AppendAllText($script:logFile, $line + "`r`n", [Text.Encoding]::UTF8) } catch { }
    if ($Test) { Write-Host $line }
}

# agent.log -> agent.1.log -> ... -> agent.<keep>.log, when it passes the size limit.
function Invoke-LogRotation {
    if (-not (Test-Path -LiteralPath $script:logFile)) { return }
    if ((Get-Item -LiteralPath $script:logFile).Length -lt [int64]$cfg.log_max_bytes) { return }
    $dir = Split-Path $script:logFile
    $keep = [int]$cfg.log_keep
    $oldest = Join-Path $dir ("agent.$keep.log")
    if (Test-Path -LiteralPath $oldest) { Remove-Item -LiteralPath $oldest -Force }
    for ($i = $keep - 1; $i -ge 1; $i--) {
        $from = Join-Path $dir ("agent.$i.log")
        if (Test-Path -LiteralPath $from) { Move-Item -LiteralPath $from -Destination (Join-Path $dir ("agent." + ($i + 1) + ".log")) -Force }
    }
    Move-Item -LiteralPath $script:logFile -Destination (Join-Path $dir 'agent.1.log') -Force
}

function Read-State {
    $s = @{}
    if (Test-Path -LiteralPath $script:stateFile) {
        try {
            $raw = [IO.File]::ReadAllText($script:stateFile, [Text.Encoding]::UTF8) | ConvertFrom-Json
            foreach ($p in $raw.PSObject.Properties) { $s[$p.Name] = $p.Value }
        } catch { Write-Log 'state.json could not be read; starting with an empty state' }
    }
    foreach ($k in @('last_ack', 'next_try_utc', 'users_hash', 'users_posted_utc', 'clock_done_date', 'clock_next_try_utc')) {
        if (-not $s.ContainsKey($k)) { $s[$k] = $null }
    }
    if (-not $s.ContainsKey('fail_count')) { $s['fail_count'] = 0 }
    return $s
}

# Written to a temporary file first, so a reboot in the middle never leaves half a file.
function Write-FileAtomic([string]$path, [string]$text) {
    $tmp = $path + '.tmp'
    [IO.File]::WriteAllText($tmp, $text, (New-Object Text.UTF8Encoding($false)))
    Move-Item -LiteralPath $tmp -Destination $path -Force
}

function Save-State($state) { Write-FileAtomic $script:stateFile ($state | ConvertTo-Json -Compress) }

function Read-Outbox {
    $set = New-Object 'System.Collections.Generic.SortedSet[string]' ([StringComparer]::Ordinal)
    if (Test-Path -LiteralPath $script:outboxFile) {
        foreach ($l in [IO.File]::ReadAllLines($script:outboxFile, [Text.Encoding]::UTF8)) {
            if ($l.Trim() -ne '') { [void]$set.Add($l.Trim()) }
        }
    }
    return , $set
}

function Save-Outbox($set) {
    if ($set.Count -eq 0) {
        if (Test-Path -LiteralPath $script:outboxFile) { Remove-Item -LiteralPath $script:outboxFile -Force }
        return
    }
    Write-FileAtomic $script:outboxFile (([string[]]@($set)) -join "`r`n")
}

# ---------------------------------------------------------------- device

function Read-ZkBytes($s, [int]$n) {
    $buf = New-Object byte[] $n
    $got = 0
    while ($got -lt $n) {
        try { $r = $s.Stream.Read($buf, $got, $n - $got) }
        catch { throw 'zk:timeout' }
        if ($r -le 0) { throw 'zk:closed' }
        $got += $r
    }
    return , $buf
}

# One reply: the 8-byte wrapper, then the payload. Anything malformed is dropped as a bad reply.
function Read-ZkReply($s) {
    $h = Read-ZkBytes $s 8
    if ([BitConverter]::ToUInt16($h, 0) -ne 0x5050 -or [BitConverter]::ToUInt16($h, 2) -ne 0x7D82) { throw 'zk:bad_reply' }
    $len = [BitConverter]::ToUInt32($h, 4)
    if ($len -lt 8 -or $len -gt 16777216) { throw 'zk:bad_reply' }
    $p = Read-ZkBytes $s ([int]$len)
    if ($cfg.check_reply_checksum -and -not (Test-ZkChecksum $p)) { throw 'zk:bad_reply' }
    $s.Reply = [BitConverter]::ToUInt16($p, 6)
    return , $p
}

# The only way a command reaches the device. Refuses every command outside the allow list.
function Send-ZkPacket($s, [int]$cmd, [byte[]]$data) {
    if ($ZkAllowedCommands -notcontains $cmd) { throw "Refused to send device command ${cmd}: not a read or clock command." }
    $old = $s.Reply
    $new = $old + 1
    if ($new -ge 65535) { $new -= 65535 }
    $s.Reply = $new
    $frame = New-ZkFrame (New-ZkPayload $cmd $s.Session $new $data $old)
    $s.Stream.Write($frame, 0, $frame.Length)
}

function Invoke-Zk($s, [int]$cmd, [byte[]]$data) {
    Send-ZkPacket $s $cmd $data
    return , (Read-ZkReply $s)
}

function Get-ZkCode([byte[]]$r) { return [int][BitConverter]::ToUInt16($r, 0) }

function Get-ZkBody([byte[]]$r) {
    if ($r.Length -le 8) { return , (New-Object byte[] 0) }
    $b = New-Object byte[] ($r.Length - 8)
    [Array]::Copy($r, 8, $b, 0, $b.Length)
    return , $b
}

function Open-Zk {
    $c = New-Object Net.Sockets.TcpClient
    $timeout = [int]$cfg.device_timeout_ms
    $ar = $c.BeginConnect([string]$cfg.device_ip, [int]$cfg.device_port, $null, $null)
    if (-not $ar.AsyncWaitHandle.WaitOne([Math]::Min($timeout, 10000))) { $c.Close(); throw 'zk:connect_failed' }
    try { $c.EndConnect($ar) } catch { $c.Close(); throw 'zk:connect_failed' }
    $c.ReceiveTimeout = $timeout
    $c.SendTimeout = $timeout
    $s = @{ Client = $c; Stream = $c.GetStream(); Session = 0; Reply = 65534 }
    try {
        $r = Invoke-Zk $s $ZkCmd.Connect $null
        $code = Get-ZkCode $r
        $s.Session = [BitConverter]::ToUInt16($r, 4)
        if ($code -eq $ZkReply.Unauth) {
            $r = Invoke-Zk $s $ZkCmd.Auth (Get-ZkCommKey ([int64]$cfg.comm_key) $s.Session)
            $code = Get-ZkCode $r
        }
        if ($code -eq $ZkReply.Error) { throw 'zk:busy' }
        if ($code -ne $ZkReply.Ok) { throw 'zk:auth_failed' }
    } catch {
        $c.Close()
        throw
    }
    return $s
}

function Close-Zk($s) {
    if ($null -eq $s) { return }
    try { [void](Invoke-Zk $s $ZkCmd.Exit $null) } catch { }
    try { $s.Client.Close() } catch { }
}

# The data of a 1504 read: either one 1501 reply, or 1500 with the size, 1501 chunks and an ack.
function Read-ZkChunk($s, [byte[]]$r) {
    $c = Get-ZkCode $r
    if ($c -eq $ZkReply.Data) { return , (Get-ZkBody $r) }
    if ($c -ne $ZkReply.PrepareData -or $r.Length -lt 12) { throw 'zk:bad_reply' }
    $size = [BitConverter]::ToUInt32($r, 8)
    $ms = New-Object IO.MemoryStream
    while ($ms.Length -lt $size) {
        $f = Read-ZkReply $s
        if ((Get-ZkCode $f) -ne $ZkReply.Data) { throw 'zk:bad_reply' }
        $b = Get-ZkBody $f
        $ms.Write($b, 0, $b.Length)
    }
    if ($ms.Length -ne $size) { throw 'zk:bad_reply' }
    [void](Read-ZkReply $s)
    return , $ms.ToArray()
}

function Read-ZkBuffer($s, [int]$what, [int]$fct) {
    $req = New-Object byte[] 11
    $req[0] = 1
    [BitConverter]::GetBytes([int16]$what).CopyTo($req, 1)
    [BitConverter]::GetBytes([int]$fct).CopyTo($req, 3)
    $r = Invoke-Zk $s $ZkCmd.PrepareBuf $req
    if ((Get-ZkCode $r) -eq $ZkReply.Data) { return , (Get-ZkBody $r) }
    if ((Get-ZkCode $r) -ne $ZkReply.Ok -or $r.Length -lt 13) { throw 'zk:bad_reply' }
    $size = [BitConverter]::ToUInt32($r, 9)
    $ms = New-Object IO.MemoryStream
    $start = 0
    $max = 0xFFC0
    while ($start -lt $size) {
        $n = [Math]::Min($max, $size - $start)
        $q = New-Object byte[] 8
        [BitConverter]::GetBytes([int]$start).CopyTo($q, 0)
        [BitConverter]::GetBytes([int]$n).CopyTo($q, 4)
        $d = Read-ZkChunk $s (Invoke-Zk $s $ZkCmd.ReadBuf $q)
        if ($d.Length -ne $n) { throw 'zk:bad_reply' }
        $ms.Write($d, 0, $d.Length)
        $start += $n
    }
    [void](Invoke-Zk $s $ZkCmd.FreeData $null)
    $all = $ms.ToArray()
    # The buffer starts with the byte size of what follows; a mismatch is a half or garbled read.
    if ($all.Length -lt 4) { if ($size -eq 0) { return , (New-Object byte[] 4) } else { throw 'zk:bad_reply' } }
    if ([BitConverter]::ToUInt32($all, 0) -ne ($all.Length - 4)) { throw 'zk:bad_reply' }
    return , $all
}

function Skip-Four([byte[]]$b) {
    $x = New-Object byte[] ([Math]::Max(0, $b.Length - 4))
    [Array]::Copy($b, 4, $x, 0, $x.Length)
    return , $x
}

# The size of one record. From the count the device reported when it divides the data into a
# known size; otherwise the office device's own size (40 or 72 bytes), since a punch made while
# the poll runs adds a record after the count was read. Anything else is a garbled reply.
function Get-RecordSize([int]$length, [int]$count, [int[]]$known, [int]$usual) {
    if ($length -eq 0) { return $usual }
    if ($count -gt 0 -and $length % $count -eq 0 -and $known -contains [int]($length / $count)) { return [int]($length / $count) }
    if ($length % $usual -eq 0) { return $usual }
    throw 'zk:bad_reply'
}

# A plausible device user ID: printable ASCII, at most 24 characters.
function Test-UserIdShape([string]$id) { return ($id -match '^[\x21-\x7E]{1,24}$') }

# Read users and records. Returns @{ ok; error; users; records; user_count; record_count; garbled }.
function Read-Device {
    $result = @{ ok = $false; error = $null; users = @(); records = @(); user_count = $null; record_count = $null; garbled = 0 }
    $s = $null
    try {
        $s = Open-Zk
        $r = Invoke-Zk $s $ZkCmd.GetSizes $null
        if ((Get-ZkCode $r) -ne $ZkReply.Ok -or $r.Length -lt (8 + 36)) { throw 'zk:bad_reply' }
        $users = [BitConverter]::ToInt32($r, 8 + 16)
        $recs = [BitConverter]::ToInt32($r, 8 + 32)
        if ($users -lt 0 -or $recs -lt 0 -or $recs -gt 10000000) { throw 'zk:bad_reply' }

        $u = Read-ZkBuffer $s $ZkBuffer.Users 5
        $ud = Skip-Four $u
        $usz = Get-RecordSize $ud.Length $users @(28, 72) 72
        $map = @{}
        $userRows = New-Object System.Collections.ArrayList
        for ($o = 0; $o + $usz -le $ud.Length; $o += $usz) {
            $uid = [BitConverter]::ToUInt16($ud, $o)
            if ($usz -eq 28) { $name = ConvertFrom-ZkString $ud ($o + 8) 8; $userId = [string][BitConverter]::ToUInt32($ud, $o + 24) }
            else { $name = ConvertFrom-ZkString $ud ($o + 11) 24; $userId = ConvertFrom-ZkString $ud ($o + 48) 24 }
            if (-not (Test-UserIdShape $userId)) { $result.garbled++; continue }
            $map[[int]$uid] = $userId
            [void]$userRows.Add(@{ user_id = $userId; name = $name })
        }

        $a = Read-ZkBuffer $s $ZkBuffer.AttLog 0
        $ad = Skip-Four $a
        $rsz = Get-RecordSize $ad.Length $recs @(8, 16, 40) 40
        $records = New-Object System.Collections.ArrayList
        for ($o = 0; $o + $rsz -le $ad.Length; $o += $rsz) {
            if ($rsz -eq 8) { $id = $map[[int][BitConverter]::ToUInt16($ad, $o)]; $status = $ad[$o + 2]; $tm = ConvertFrom-ZkTime ([BitConverter]::ToUInt32($ad, $o + 3)); $punch = $ad[$o + 7] }
            elseif ($rsz -eq 16) { $id = [string][BitConverter]::ToUInt32($ad, $o); $tm = ConvertFrom-ZkTime ([BitConverter]::ToUInt32($ad, $o + 4)); $status = $ad[$o + 8]; $punch = $ad[$o + 9] }
            else { $id = ConvertFrom-ZkString $ad ($o + 2) 24; $status = $ad[$o + 26]; $tm = ConvertFrom-ZkTime ([BitConverter]::ToUInt32($ad, $o + 27)); $punch = $ad[$o + 31] }
            if (-not $id -or -not (Test-UserIdShape ([string]$id))) { $result.garbled++; continue }
            [void]$records.Add(@{ id = [string]$id; time = $tm; status = [int]$status; punch = [int]$punch })
        }
        $result.users = @($userRows)
        $result.records = @($records)
        $result.user_count = [int]($ud.Length / $usz)
        $result.record_count = [int]($ad.Length / $rsz)
        $result.ok = $true
    } catch {
        $msg = [string]$_.Exception.Message
        $result.error = if ($msg -like 'zk:*') { $msg.Substring(3) } else { 'device_error' }
        if ($msg -notlike 'zk:*') { Write-Log ('device read failed: ' + $msg) }
        $result.users = @(); $result.records = @()
    } finally {
        Close-Zk $s
    }
    return $result
}

# Read the device clock, set it to this PC's Manila time, and read it back.
function Set-DeviceClock {
    $s = $null
    try {
        $s = Open-Zk
        $r = Invoke-Zk $s $ZkCmd.GetTime $null
        $before = if ((Get-ZkCode $r) -eq $ZkReply.Ok -and $r.Length -ge 12) { ConvertFrom-ZkTime ([BitConverter]::ToUInt32($r, 8)) } else { $null }
        $pc = Get-PcManila
        $r = Invoke-Zk $s $ZkCmd.SetTime ([BitConverter]::GetBytes((ConvertTo-ZkTime $pc)))
        if ((Get-ZkCode $r) -ne $ZkReply.Ok) { throw 'zk:set_time_refused' }
        $deviceDrift = $null
        if ($before) {
            $deviceDrift = [int][Math]::Round(([DateTime]::ParseExact($before, 'yyyy-MM-dd HH:mm:ss', $null) - $pc).TotalSeconds)
        }
        return @{ ok = $true; device_drift = $deviceDrift; set_to = $pc.ToString('yyyy-MM-dd HH:mm:ss') }
    } catch {
        $msg = [string]$_.Exception.Message
        return @{ ok = $false; error = $(if ($msg -like 'zk:*') { $msg.Substring(3) } else { 'device_error' }) }
    } finally {
        Close-Zk $s
    }
}

# ---------------------------------------------------------------- archive and window

# Keep every record in attlog-YYYY-MM.csv (by the record's month), once.
function Add-ToArchive($records) {
    $dir = Join-Path $cfg.data_dir 'archive'
    if (-not (Test-Path -LiteralPath $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    $byMonth = @{}
    foreach ($r in $records) {
        $m = $r.time.Substring(0, 7)
        if (-not $byMonth.ContainsKey($m)) { $byMonth[$m] = New-Object System.Collections.ArrayList }
        [void]$byMonth[$m].Add($r)
    }
    $added = 0
    foreach ($m in $byMonth.Keys) {
        $file = Join-Path $dir ("attlog-$m.csv")
        $seen = New-Object 'System.Collections.Generic.HashSet[string]'
        if (Test-Path -LiteralPath $file) {
            foreach ($l in [IO.File]::ReadAllLines($file, [Text.Encoding]::UTF8)) {
                $p = $l.Split(',')
                if ($p.Length -ge 2) { [void]$seen.Add($p[0] + ',' + $p[1]) }
            }
        } else {
            [IO.File]::WriteAllText($file, "user_id,time,status,punch`r`n", (New-Object Text.UTF8Encoding($false)))
        }
        $sb = New-Object Text.StringBuilder
        foreach ($r in $byMonth[$m]) {
            if ($seen.Add($r.id + ',' + $r.time)) {
                [void]$sb.Append(('{0},{1},{2},{3}' -f $r.id, $r.time, $r.status, $r.punch) + "`r`n")
                $added++
            }
        }
        if ($sb.Length -gt 0) { [IO.File]::AppendAllText($file, $sb.ToString(), (New-Object Text.UTF8Encoding($false))) }
    }
    return $added
}

# The window of decision 1 as amended (012-1): from the EARLIER of the last 3 days and the
# server's last acknowledged punch minus 1 day, so a PC outage of any length is caught up by
# the next poll; Likha HR de-duplicates what it already has. -Since overrides it by hand.
# Records whose date Likha HR will refuse (before 2020, or over a day ahead) are always sent,
# so they are counted there.
function Select-PostLines($records, $state) {
    $pc = Get-PcManila
    $start = $pc.AddDays(-[int]$cfg.window_days)
    if ($state.last_ack) {
        $ack = [DateTime]::ParseExact([string]$state.last_ack, 'yyyy-MM-dd HH:mm:ss', $null).AddDays(-1)
        if ($ack -lt $start) { $start = $ack }
    }
    if ($Since) { $start = [DateTime]::ParseExact($Since, 'yyyy-MM-dd', $null) }
    $from = $start.ToString('yyyy-MM-dd HH:mm:ss')
    $ahead = $pc.AddDays(1).ToString('yyyy-MM-dd HH:mm:ss')

    $lines = New-Object 'System.Collections.Generic.SortedSet[string]' ([StringComparer]::Ordinal)
    foreach ($r in $records) {
        if ($r.time -ge $from -or $r.time -lt '2020-01-01 00:00:00' -or $r.time -gt $ahead) {
            [void]$lines.Add($r.id + "`t" + $r.time)
        }
    }
    return , $lines
}

# ---------------------------------------------------------------- Likha HR

function Invoke-Feed([string]$method, [string]$path, $body) {
    $url = $cfg.base_url + $path
    $req = [Net.HttpWebRequest]::Create($url)
    $req.Method = $method
    $req.Accept = 'application/json'
    $req.Timeout = [int]$cfg.http_timeout_ms
    $req.ReadWriteTimeout = [int]$cfg.http_timeout_ms
    $req.Headers.Add('X-BIOMETRICS-FEED-KEY', $script:key)
    $t0 = Get-PcUtc
    $resp = $null
    try {
        if ($null -ne $body) {
            $bytes = [Text.Encoding]::UTF8.GetBytes((ConvertTo-Json -InputObject $body -Depth 6 -Compress))
            $req.ContentType = 'application/json; charset=utf-8'
            $req.ContentLength = $bytes.Length
            $st = $req.GetRequestStream()
            $st.Write($bytes, 0, $bytes.Length)
            $st.Close()
        }
        $resp = $req.GetResponse()
    } catch [Net.WebException] {
        $resp = $_.Exception.Response
        if ($null -eq $resp) {
            return @{ status = 0; json = $null; error = [string]$_.Exception.Status; t0 = $t0; t1 = (Get-PcUtc); retry_after = $null }
        }
    } catch {
        return @{ status = 0; json = $null; error = 'request_failed'; t0 = $t0; t1 = (Get-PcUtc); retry_after = $null }
    }
    $t1 = Get-PcUtc
    $status = [int]$resp.StatusCode
    $retryAfter = $resp.Headers['Retry-After']
    $text = ''
    try {
        $reader = New-Object IO.StreamReader($resp.GetResponseStream(), [Text.Encoding]::UTF8)
        $text = $reader.ReadToEnd()
        $reader.Close()
    } catch { }
    $resp.Close()
    $json = $null
    if ($text) { try { $json = $text | ConvertFrom-Json } catch { } }
    return @{ status = $status; json = $json; error = $null; t0 = $t0; t1 = $t1; retry_after = $retryAfter }
}

function Test-Ok($r) { return ($r.status -ge 200 -and $r.status -lt 300) }

# After a failed call: wait 1, 2, 4 ... up to 30 minutes (or what Retry-After asks) before the
# next call, so an outage of Likha HR is not flooded with a call a minute.
function Register-Failure($state, $r) {
    $state.fail_count = [int]$state.fail_count + 1
    $wait = [Math]::Min(60 * [Math]::Pow(2, [int]$state.fail_count - 1), 1800)
    if ($r.retry_after -and ([string]$r.retry_after -match '^\d+$')) { $wait = [Math]::Max($wait, [int]$r.retry_after) }
    $state.next_try_utc = (Get-PcUtc).AddSeconds($wait).ToString('o')
    Write-Log ('Likha HR call failed (status ' + $r.status + $(if ($r.error) { ', ' + $r.error } else { '' }) + '); next try in ' + [int]$wait + ' s')
}

function Register-Success($state, $r) {
    $state.fail_count = 0
    $state.next_try_utc = $null
    if ($r.json -and $r.json.last_punch_at) { $state.last_ack = [string]$r.json.last_punch_at }
}

# ---------------------------------------------------------------- one run

# Tests only: dot-sourced with -LoadOnly, the script defines its functions and stops here.
if ($LoadOnly) { return }

try {
    $cfg = Read-Config $ConfigPath
} catch {
    # A setup error: logged next to the config file (the data folder), and nothing is sent.
    $script:logFile = Join-Path (Split-Path $ConfigPath) 'logs\agent.log'
    $logDir = Split-Path $script:logFile
    if (-not (Test-Path -LiteralPath $logDir)) { New-Item -ItemType Directory -Path $logDir -Force | Out-Null }
    Write-Log ('setup error: ' + $_.Exception.Message)
    exit 1
}
foreach ($d in @($cfg.data_dir, (Join-Path $cfg.data_dir 'logs'))) {
    if (-not (Test-Path -LiteralPath $d)) { New-Item -ItemType Directory -Path $d -Force | Out-Null }
}
$script:logFile = Join-Path $cfg.data_dir 'logs\agent.log'
$script:stateFile = Join-Path $cfg.data_dir 'state.json'
$script:outboxFile = Join-Path $cfg.data_dir 'outbox.txt'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

$mutex = New-Object Threading.Mutex($false, 'Global\LikhaBiometricsAgent')
$haveMutex = $false
try { $haveMutex = $mutex.WaitOne(0) } catch [Threading.AbandonedMutexException] { $haveMutex = $true }
if (-not $haveMutex) { Write-Log 'another run is still active; this one stops'; exit 0 }

$exitCode = 0
try {
    Invoke-LogRotation
    $script:key = ''
    if (Test-Path -LiteralPath $cfg.key_file) { $script:key = ([IO.File]::ReadAllText($cfg.key_file)).Trim() }
    if (-not $script:key -and -not $Test) { throw 'The key file is missing or empty. Run install.ps1 again.' }

    $state = Read-State
    $device = Read-Device
    if ($device.ok) {
        $archived = Add-ToArchive $device.records
        Write-Log ('device: ' + $device.record_count + ' records, ' + $device.user_count + ' users; ' + $archived + ' new in the archive' + $(if ($device.garbled) { '; ' + $device.garbled + ' garbled dropped' } else { '' }))
    } else {
        Write-Log ('device not reachable: ' + $device.error)
    }

    $outbox = Read-Outbox
    $fresh = Select-PostLines $device.records $state
    foreach ($l in $fresh) { [void]$outbox.Add($l) }

    if ($Test) {
        Write-Host ('Device read: ' + $(if ($device.ok) { 'OK' } else { 'FAILED (' + $device.error + ')' }))
        Write-Host ('Users on the device: ' + $device.user_count + '; records: ' + $device.record_count)
        Write-Host ('Lines that would be posted: ' + $outbox.Count)
        foreach ($l in @($outbox | Select-Object -First 10)) { Write-Host ('  ' + ($l -replace "`t", '  ')) }
        exit 0
    }

    $now = Get-PcUtc
    if ($state.next_try_utc -and $now -lt [DateTime]::Parse([string]$state.next_try_utc).ToUniversalTime()) {
        Save-Outbox $outbox
        Write-Log ('backing off: ' + $outbox.Count + ' lines wait in the outbox until ' + $state.next_try_utc)
        Save-State $state
        exit 0
    }

    # Post in batches of at most 1,000 lines, oldest first; a sent batch leaves the outbox.
    $sent = 0
    $failed = $false
    $all = [string[]]@($outbox | Sort-Object { $_.Split("`t")[1] }, { $_ })
    for ($i = 0; $i -lt $all.Length; $i += [int]$cfg.batch_size) {
        $batch = [string[]]@($all[$i..([Math]::Min($i + [int]$cfg.batch_size, $all.Length) - 1)])
        $r = Invoke-Feed 'POST' '/punches' @{ agent_id = $cfg.agent_id; attlog = ($batch -join "`n") }
        if (Test-Ok $r) {
            foreach ($l in $batch) { [void]$outbox.Remove($l) }
            $sent += $batch.Length
            Register-Success $state $r
            if ($r.json -and ([int]$r.json.inserted -gt 0 -or [int]$r.json.held -gt 0 -or [int]$r.json.refused -gt 0)) {
                Write-Log ('posted ' + $batch.Length + ' lines: ' + $r.json.inserted + ' new, ' + $r.json.duplicates + ' known, ' + $r.json.held + ' held, ' + $r.json.refused + ' refused, ' + $r.json.invalid + ' invalid')
            }
        } elseif ($r.status -eq 413 -or ($r.status -eq 422 -and $r.json -and [string]$r.json.error -eq 'too_many_lines')) {
            # Likha HR will never take this batch as it is: keep it aside, not in the way. Any
            # other refusal (a bad agent id, say) is a setup error: the lines stay in the outbox.
            $rej = Join-Path $cfg.data_dir ('rejected-' + (Get-PcManila).ToString('yyyyMMdd') + '.txt')
            [IO.File]::AppendAllText($rej, (($batch -join "`r`n") + "`r`n"), (New-Object Text.UTF8Encoding($false)))
            foreach ($l in $batch) { [void]$outbox.Remove($l) }
            Write-Log ('Likha HR refused a batch of ' + $batch.Length + ' lines (status ' + $r.status + '); kept in ' + (Split-Path $rej -Leaf))
        } else {
            Register-Failure $state $r
            $failed = $true
            break
        }
    }
    Save-Outbox $outbox

    if (-not $failed) {
        $hb = @{
            agent_id            = $cfg.agent_id
            agent_version       = $AgentVersion
            device_reachable    = [bool]$device.ok
            device_record_count = $device.record_count
            device_user_count   = $device.user_count
        }
        if (-not $device.ok) { $hb.device_error = [string]$device.error }
        $r = Invoke-Feed 'POST' '/heartbeat' $hb
        if (Test-Ok $r) { Register-Success $state $r } else { Register-Failure $state $r; $failed = $true }
    }

    # The user list: shown on the Import page only. Hourly, or when it changed.
    if (-not $failed -and $device.ok) {
        $userText = (@($device.users | ForEach-Object { $_.user_id + "`t" + $_.name }) -join "`n")
        $hash = [BitConverter]::ToString((New-Object Security.Cryptography.SHA256Managed).ComputeHash([Text.Encoding]::UTF8.GetBytes($userText)))
        $due = (-not $state.users_posted_utc) -or ((Get-PcUtc) -ge [DateTime]::Parse([string]$state.users_posted_utc).ToUniversalTime().AddMinutes([int]$cfg.users_every_minutes))
        if ($hash -ne $state.users_hash -or $due) {
            $list = @($device.users | ForEach-Object { @{ user_id = $_.user_id; name = $_.name } })
            $r = Invoke-Feed 'POST' '/users' @{ agent_id = $cfg.agent_id; users = $list }
            if (Test-Ok $r) {
                $state.users_hash = $hash
                $state.users_posted_utc = (Get-PcUtc).ToString('o')
            } else {
                Write-Log ('user list not taken (status ' + $r.status + ')')
            }
        }
    }

    # The daily clock check (decision 9): from 06:00 Manila, once a day.
    $manila = Get-PcManila
    $today = $manila.ToString('yyyy-MM-dd')
    $clockDue = $cfg.clock_sync -and ($manila.ToString('HH:mm') -ge [string]$cfg.clock_check_at) -and ($state.clock_done_date -ne $today) `
        -and (-not $state.clock_next_try_utc -or (Get-PcUtc) -ge [DateTime]::Parse([string]$state.clock_next_try_utc).ToUniversalTime())
    if ($clockDue) {
        if ($failed -or -not $r -or -not $r.json -or -not $r.json.server_time) {
            $state.clock_next_try_utc = (Get-PcUtc).AddMinutes(15).ToString('o')
            Write-Log 'clock check: Likha HR not reachable; trying again in 15 minutes'
        } else {
            # Measure against a fresh call, halfway through its round trip.
            $probe = Invoke-Feed 'GET' '/status' $null
            if (-not (Test-Ok $probe) -or -not $probe.json.server_time) {
                $state.clock_next_try_utc = (Get-PcUtc).AddMinutes(15).ToString('o')
                Write-Log 'clock check: no server time; trying again in 15 minutes'
            } else {
                $server = [DateTime]::Parse([string]$probe.json.server_time, [Globalization.CultureInfo]::InvariantCulture,
                    [Globalization.DateTimeStyles]::AdjustToUniversal -bor [Globalization.DateTimeStyles]::AssumeUniversal)
                $mid = $probe.t0.AddTicks([int64](($probe.t1 - $probe.t0).Ticks / 2))
                $drift = [int][Math]::Round(($mid - $server).TotalSeconds)
                $set = $false
                $hb2 = @{ agent_id = $cfg.agent_id; agent_version = $AgentVersion; device_reachable = [bool]$device.ok;
                          device_record_count = $device.record_count; device_user_count = $device.user_count; clock_drift_seconds = $drift }
                if ([Math]::Abs($drift) -le [int]$cfg.max_clock_drift_s) {
                    $res = Set-DeviceClock
                    if ($res.ok) {
                        $set = $true
                        if ($null -ne $res.device_drift) { $hb2.device_drift_seconds = $res.device_drift }
                        Write-Log ('clock check: PC is ' + $drift + ' s off Likha HR; device clock set to ' + $res.set_to + ' (it was ' + $res.device_drift + ' s off)')
                    } else {
                        Write-Log ('clock check: PC is ' + $drift + ' s off Likha HR; setting the device clock failed: ' + $res.error)
                    }
                } else {
                    Write-Log ('clock check: PC is ' + $drift + ' s off Likha HR, more than ' + $cfg.max_clock_drift_s + ' s; the device clock was NOT set')
                }
                $hb2.clock_set = $set
                $r2 = Invoke-Feed 'POST' '/heartbeat' $hb2
                if (Test-Ok $r2) {
                    $state.clock_done_date = $today
                    $state.clock_next_try_utc = $null
                } else {
                    $state.clock_next_try_utc = (Get-PcUtc).AddMinutes(15).ToString('o')
                }
            }
        }
    }

    Save-State $state
} catch {
    Write-Log ('run failed: ' + $_.Exception.Message)
    $exitCode = 1
} finally {
    if ($haveMutex) { $mutex.ReleaseMutex() }
    $mutex.Dispose()
}
exit $exitCode
