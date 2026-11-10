<#
  A fake ZKTeco device for the agent tests: a TcpListener on 127.0.0.1 that speaks the device's
  TCP protocol the way mock/reference-reader.ps1 found the office ZK3969 does: connect (with
  CMD_AUTH and the comm key), sizes, the buffered user and attendance reads (40-byte records,
  72-byte users), get/set time and exit. It checks the checksum of every packet it receives.

  It reads the scenario JSON again at every new connection, so a test can change what the
  device holds between two agent runs:
    { "comm_key": 0, "users": [ { "uid": 1, "user_id": "1", "name": "..." } ],
      "records": [ { "user_id": "1", "time": "2026-11-10 08:00:00", "status": 0, "punch": 1 } ],
      "device_offset_s": 0,
      "fault": "none" | "busy" | "garbled" | "half" | "drop" | "grow" }
  Every command received is logged as "cmd=<code>" to -Log; a command it does not know is
  logged as "unknown cmd=<code>" and refused. CMD_SET_TIME writes "set_to=<time>" to
  <log>.clock and later reads of the clock follow it.
#>
param(
    [int]$Port = 14370,
    [Parameter(Mandatory = $true)][string]$Scenario,
    [Parameter(Mandatory = $true)][string]$Log
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot '..\ZkProtocol.ps1')

$clockFile = $Log + '.clock'

function Write-ZkLog([string]$text) {
    [IO.File]::AppendAllText($Log, $text + "`r`n", [Text.Encoding]::UTF8)
}

function Read-Scenario {
    return ([IO.File]::ReadAllText($Scenario, [Text.Encoding]::UTF8) | ConvertFrom-Json)
}

function Get-RealManila { return [DateTime]::UtcNow.AddHours(8) }

function Get-DeviceNow($sc) {
    if (Test-Path -LiteralPath $clockFile) {
        $offset = [double]([IO.File]::ReadAllText($clockFile).Split("`n")[1])
        return (Get-RealManila).AddSeconds($offset)
    }
    $o = 0
    if ($null -ne $sc.device_offset_s) { $o = [double]$sc.device_offset_s }
    return (Get-RealManila).AddSeconds($o)
}

function Set-Text([byte[]]$buf, [int]$offset, [int]$length, [string]$text) {
    $b = [Text.Encoding]::UTF8.GetBytes($text)
    [Array]::Copy($b, 0, $buf, $offset, [Math]::Min($b.Length, $length))
}

function Get-UserBuffer($sc) {
    $list = @($sc.users)
    $data = New-Object byte[] (4 + 72 * $list.Count)
    [BitConverter]::GetBytes([uint32](72 * $list.Count)).CopyTo($data, 0)
    for ($i = 0; $i -lt $list.Count; $i++) {
        $o = 4 + 72 * $i
        [BitConverter]::GetBytes([uint16]$list[$i].uid).CopyTo($data, $o)
        Set-Text $data ($o + 11) 24 ([string]$list[$i].name)
        Set-Text $data ($o + 48) 24 ([string]$list[$i].user_id)
    }
    return , $data
}

function Get-AttLogBuffer($sc) {
    $list = @($sc.records)
    $data = New-Object byte[] (4 + 40 * $list.Count)
    [BitConverter]::GetBytes([uint32](40 * $list.Count)).CopyTo($data, 0)
    for ($i = 0; $i -lt $list.Count; $i++) {
        $o = 4 + 40 * $i
        $r = $list[$i]
        [BitConverter]::GetBytes([uint16]($i + 1)).CopyTo($data, $o)
        Set-Text $data ($o + 2) 24 ([string]$r.user_id)
        $data[$o + 26] = [byte]$r.status
        $t = [DateTime]::ParseExact([string]$r.time, 'yyyy-MM-dd HH:mm:ss', $null)
        [BitConverter]::GetBytes((ConvertTo-ZkTime $t)).CopyTo($data, $o + 27)
        $data[$o + 31] = [byte]$r.punch
    }
    return , $data
}

function Read-Exact($stream, [int]$n) {
    $buf = New-Object byte[] $n
    $got = 0
    while ($got -lt $n) {
        $r = $stream.Read($buf, $got, $n - $got)
        if ($r -le 0) { return $null }
        $got += $r
    }
    return , $buf
}

function Send-Reply($stream, [int]$code, [int]$session, [int]$replyId, [byte[]]$data, [switch]$BadChecksum) {
    $payload = New-ZkPayload $code $session $replyId $data
    if ($BadChecksum) { $payload[2] = $payload[2] -bxor 0xFF }
    $frame = New-ZkFrame $payload
    $stream.Write($frame, 0, $frame.Length)
}

function Invoke-Session($client) {
    $client.ReceiveTimeout = 30000
    $stream = $client.GetStream()
    $sc = Read-Scenario
    $fault = if ($sc.fault) { [string]$sc.fault } else { 'none' }
    $commKey = if ($null -ne $sc.comm_key) { [int64]$sc.comm_key } else { 0 }
    $session = Get-Random -Minimum 1 -Maximum 60000
    $authed = $false
    $current = New-Object byte[] 0

    while ($true) {
        $h = Read-Exact $stream 8
        if ($null -eq $h) { return }
        if ([BitConverter]::ToUInt16($h, 0) -ne 0x5050 -or [BitConverter]::ToUInt16($h, 2) -ne 0x7D82) {
            Write-ZkLog 'bad frame header'
            return
        }
        $p = Read-Exact $stream ([int][BitConverter]::ToUInt32($h, 4))
        if ($null -eq $p) { return }
        $cmd = [int][BitConverter]::ToUInt16($p, 0)
        $rid = [int][BitConverter]::ToUInt16($p, 6)
        $data = New-Object byte[] ($p.Length - 8)
        [Array]::Copy($p, 8, $data, 0, $data.Length)

        # The agent sums with the reply id before its increment, as the reference reader does.
        $copy = [byte[]]$p.Clone()
        $copy[2] = 0; $copy[3] = 0
        $prev = if ($rid -eq 0) { 65534 } else { $rid - 1 }
        [BitConverter]::GetBytes([uint16]$prev).CopyTo($copy, 6)
        if ((Get-ZkChecksum $copy) -ne [BitConverter]::ToUInt16($p, 2)) {
            Write-ZkLog "bad checksum cmd=$cmd"
            Send-Reply $stream $ZkReply.Error $session $rid $null
            continue
        }
        if (@(1000, 1001, 1102, 50, 201, 202, 1503, 1504, 1502) -contains $cmd) { Write-ZkLog "cmd=$cmd" }
        else { Write-ZkLog "unknown cmd=$cmd" }

        if ($cmd -eq $ZkCmd.Connect) {
            if ($fault -eq 'busy') { Send-Reply $stream $ZkReply.Error 0 $rid $null; continue }
            Send-Reply $stream $ZkReply.Unauth $session $rid $null
            continue
        }
        if ($cmd -eq $ZkCmd.Auth) {
            $expected = Get-ZkCommKey $commKey $session
            if ($data.Length -eq 4 -and [BitConverter]::ToString($data) -eq [BitConverter]::ToString($expected)) {
                $authed = $true
                Write-ZkLog 'auth ok'
                Send-Reply $stream $ZkReply.Ok $session $rid $null
            } else {
                Write-ZkLog 'auth refused'
                Send-Reply $stream $ZkReply.Unauth $session $rid $null
            }
            continue
        }
        if ($cmd -eq $ZkCmd.Exit) { Send-Reply $stream $ZkReply.Ok $session $rid $null; return }
        if (-not $authed) { Send-Reply $stream $ZkReply.Unauth $session $rid $null; continue }

        switch ($cmd) {
            50 {
                if ($fault -eq 'drop') { Write-ZkLog 'dropping the connection'; return }
                $body = New-Object byte[] 92
                [BitConverter]::GetBytes([int]@($sc.users).Count).CopyTo($body, 16)
                # "grow": a punch lands while the poll runs, after the count was read.
                $recCount = @($sc.records).Count
                if ($fault -eq 'grow' -and $recCount -gt 0) { $recCount-- }
                [BitConverter]::GetBytes([int]$recCount).CopyTo($body, 32)
                [BitConverter]::GetBytes([int]50000).CopyTo($body, 76)
                Send-Reply $stream $ZkReply.Ok $session $rid $body
            }
            1503 {
                $what = [BitConverter]::ToInt16($data, 1)
                $current = if ($what -eq $ZkBuffer.Users) { Get-UserBuffer $sc } else { Get-AttLogBuffer $sc }
                $body = New-Object byte[] 9
                [BitConverter]::GetBytes([uint32]$current.Length).CopyTo($body, 1)
                Send-Reply $stream $ZkReply.Ok $session $rid $body
            }
            1504 {
                $start = [BitConverter]::ToInt32($data, 0)
                $size = [BitConverter]::ToInt32($data, 4)
                $chunk = New-Object byte[] $size
                [Array]::Copy($current, $start, $chunk, 0, $size)
                $sizeBody = [BitConverter]::GetBytes([uint32]$size)
                if ($fault -eq 'half') {
                    # Announce the data, send half of it, and hang up.
                    Send-Reply $stream $ZkReply.PrepareData $session $rid $sizeBody
                    $half = New-ZkFrame (New-ZkPayload $ZkReply.Data $session $rid $chunk)
                    $stream.Write($half, 0, [int]($half.Length / 2))
                    Write-ZkLog 'sent half a reply and hung up'
                    return
                }
                Send-Reply $stream $ZkReply.PrepareData $session $rid $sizeBody -BadChecksum:($fault -eq 'garbled')
                for ($o = 0; $o -lt $size; $o += 1024) {
                    $n = [Math]::Min(1024, $size - $o)
                    $part = New-Object byte[] $n
                    [Array]::Copy($chunk, $o, $part, 0, $n)
                    Send-Reply $stream $ZkReply.Data $session $rid $part
                }
                Send-Reply $stream $ZkReply.Ok $session $rid $null
            }
            1502 { Send-Reply $stream $ZkReply.Ok $session $rid $null }
            201 { Send-Reply $stream $ZkReply.Ok $session $rid ([BitConverter]::GetBytes((ConvertTo-ZkTime (Get-DeviceNow $sc)))) }
            202 {
                $setTo = ConvertFrom-ZkTime ([BitConverter]::ToUInt32($data, 0))
                $offset = ([DateTime]::ParseExact($setTo, 'yyyy-MM-dd HH:mm:ss', $null) - (Get-RealManila)).TotalSeconds
                [IO.File]::WriteAllText($clockFile, "set_to=$setTo`n$offset")
                Write-ZkLog "clock set_to=$setTo"
                Send-Reply $stream $ZkReply.Ok $session $rid $null
            }
            default { Send-Reply $stream $ZkReply.Error $session $rid $null }
        }
    }
}

$listener = New-Object Net.Sockets.TcpListener([Net.IPAddress]::Loopback, $Port)
$listener.Start()
Write-ZkLog "listening on $Port"
while ($true) {
    $client = $listener.AcceptTcpClient()
    try { Invoke-Session $client }
    catch { Write-ZkLog ('session error: ' + $_.Exception.Message) }
    finally { $client.Close() }
}
