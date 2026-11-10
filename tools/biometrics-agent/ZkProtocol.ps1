# ZKTeco TCP protocol (port 4370) for the Likha HR biometrics agent and its fake device.
# PowerShell 5.1, no modules. Built from mock/reference-reader.ps1, which worked against the
# office device (ZK3969, firmware Ver 6.60 Sep 27 2019).
#
# The agent may send ONLY the read and clock commands in $ZkAllowedCommands. Anything else is
# refused by Send-ZkPacket before a byte leaves the PC: the device is never cleared or changed
# by the agent, apart from its clock (spec 012, decisions 9 and 10).

$ZkCmd = @{
    Connect      = 1000
    Exit         = 1001
    Auth         = 1102
    GetSizes     = 50
    GetTime      = 201
    SetTime      = 202
    PrepareBuf   = 1503
    ReadBuf      = 1504
    FreeData     = 1502
}
$ZkReply = @{
    Ok          = 2000
    Error       = 2001
    Unauth      = 2005
    PrepareData = 1500
    Data        = 1501
}
# Buffer contents asked through PrepareBuf (these are arguments, not commands).
$ZkBuffer = @{ Users = 9; AttLog = 13 }

$ZkAllowedCommands = @(1000, 1001, 1102, 50, 201, 202, 1503, 1504, 1502)

function Get-ZkChecksum([byte[]]$p) {
    $k = 0
    for ($i = 0; $i + 1 -lt $p.Length; $i += 2) {
        $k += [BitConverter]::ToUInt16($p, $i)
        if ($k -gt 65535) { $k -= 65535 }
    }
    if ($p.Length % 2) { $k += $p[$p.Length - 1] }
    while ($k -gt 65535) { $k -= 65535 }
    $k = -bnot $k
    while ($k -lt 0) { $k += 65535 }
    return [int]$k
}

# The 8-byte TCP wrapper: 0x5050, 0x7D82, payload length.
function New-ZkFrame([byte[]]$payload) {
    $t = New-Object byte[] 8
    [BitConverter]::GetBytes([uint16]0x5050).CopyTo($t, 0)
    [BitConverter]::GetBytes([uint16]0x7D82).CopyTo($t, 2)
    [BitConverter]::GetBytes([uint32]$payload.Length).CopyTo($t, 4)
    return , ([byte[]]($t + $payload))
}

# A payload: command, checksum, session, reply id, data. The checksum covers the payload with
# checksum 0; $checksumReplyId lets the agent reproduce the reference reader exactly (it sums
# with the reply id before the increment, as pyzk does).
function New-ZkPayload([int]$cmd, [int]$session, [int]$replyId, [byte[]]$data, [int]$checksumReplyId = -1) {
    if ($null -eq $data) { $data = New-Object byte[] 0 }
    if ($checksumReplyId -lt 0) { $checksumReplyId = $replyId }
    $b = New-Object byte[] (8 + $data.Length)
    [BitConverter]::GetBytes([uint16]$cmd).CopyTo($b, 0)
    [BitConverter]::GetBytes([uint16]$session).CopyTo($b, 4)
    [BitConverter]::GetBytes([uint16]$checksumReplyId).CopyTo($b, 6)
    $data.CopyTo($b, 8)
    $ck = Get-ZkChecksum $b
    [BitConverter]::GetBytes([uint16]$ck).CopyTo($b, 2)
    [BitConverter]::GetBytes([uint16]$replyId).CopyTo($b, 6)
    return , $b
}

# Whether a payload's checksum is right, under either convention a device may use.
function Test-ZkChecksum([byte[]]$p) {
    if ($p.Length -lt 8) { return $false }
    $given = [BitConverter]::ToUInt16($p, 2)
    $copy = [byte[]]$p.Clone()
    $copy[2] = 0; $copy[3] = 0
    if ((Get-ZkChecksum $copy) -eq $given) { return $true }
    $rid = [BitConverter]::ToUInt16($p, 6)
    $prev = if ($rid -eq 0) { 65534 } else { $rid - 1 }
    [BitConverter]::GetBytes([uint16]$prev).CopyTo($copy, 6)
    return ((Get-ZkChecksum $copy) -eq $given)
}

function Get-ZkCommKey([int64]$key, [int64]$sid) {
    $k = [int64]0
    for ($i = 0; $i -lt 32; $i++) {
        if ($key -band ([int64]1 -shl $i)) { $k = ($k -shl 1) -bor 1 } else { $k = $k -shl 1 }
    }
    $k += $sid
    $b = [BitConverter]::GetBytes([uint32]($k -band 0xFFFFFFFF))
    $x = [byte[]]@(($b[0] -bxor 0x5A), ($b[1] -bxor 0x4B), ($b[2] -bxor 0x53), ($b[3] -bxor 0x4F))
    $t = 50
    return , ([byte[]]@(($x[2] -bxor $t), ($x[3] -bxor $t), $t, ($x[1] -bxor $t)))
}

# Device time: seconds counted in 31-day months of 12-month years from 2000 (as ZTime of the
# reference reader).
function ConvertFrom-ZkTime([uint32]$value) {
    $t = [int64]$value
    $sec = $t % 60; $t = [Math]::Floor($t / 60)
    $min = $t % 60; $t = [Math]::Floor($t / 60)
    $hr = $t % 24; $t = [Math]::Floor($t / 24)
    $day = $t % 31 + 1; $t = [Math]::Floor($t / 31)
    $mon = $t % 12 + 1; $t = [Math]::Floor($t / 12)
    return ('{0:0000}-{1:00}-{2:00} {3:00}:{4:00}:{5:00}' -f ($t + 2000), $mon, $day, $hr, $min, $sec)
}

# The inverse, for CMD_SET_TIME (spec 012, decision 9).
function ConvertTo-ZkTime([DateTime]$t) {
    $v = (((((([int64]$t.Year % 100) * 12 + ($t.Month - 1)) * 31 + ($t.Day - 1)) * 24 + $t.Hour) * 60 + $t.Minute) * 60 + $t.Second)
    return [uint32]$v
}

# A device string field: NUL-terminated bytes, UTF-8 when valid, else Windows-1252.
function ConvertFrom-ZkString([byte[]]$b, [int]$offset, [int]$length) {
    $end = $offset
    while ($end -lt ($offset + $length) -and $end -lt $b.Length -and $b[$end] -ne 0) { $end++ }
    if ($end -eq $offset) { return '' }
    $utf8 = New-Object Text.UTF8Encoding($false, $true)
    try { $s = $utf8.GetString($b, $offset, $end - $offset) }
    catch { $s = [Text.Encoding]::GetEncoding(1252).GetString($b, $offset, $end - $offset) }
    return $s.Trim()
}
