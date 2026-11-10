<#
  A fake Likha HR for the agent tests: answers every HTTP request with one status code (500 by
  default) and counts the requests in -CountFile, one line per request ("POST /api/biometrics/punches").
#>
param(
    [int]$Port = 18124,
    [int]$Status = 500,
    [Parameter(Mandatory = $true)][string]$CountFile
)

$ErrorActionPreference = 'Stop'
$listener = New-Object Net.Sockets.TcpListener([Net.IPAddress]::Loopback, $Port)
$listener.Start()
while ($true) {
    $client = $listener.AcceptTcpClient()
    try {
        $client.ReceiveTimeout = 10000
        $stream = $client.GetStream()
        $reader = New-Object IO.StreamReader($stream, [Text.Encoding]::ASCII)
        $requestLine = $reader.ReadLine()
        $length = 0
        while ($true) {
            $line = $reader.ReadLine()
            if ($null -eq $line -or $line -eq '') { break }
            if ($line -match '^Content-Length:\s*(\d+)') { $length = [int]$Matches[1] }
        }
        if ($length -gt 0) {
            $buf = New-Object char[] $length
            $got = 0
            while ($got -lt $length) { $n = $reader.Read($buf, $got, $length - $got); if ($n -le 0) { break }; $got += $n }
        }
        $parts = ([string]$requestLine).Split(' ')
        [IO.File]::AppendAllText($CountFile, ($parts[0] + ' ' + $parts[1]) + "`r`n")
        $body = '{"error":"fake"}'
        $head = "HTTP/1.1 $Status Fake`r`nContent-Type: application/json`r`nContent-Length: $($body.Length)`r`nConnection: close`r`n`r`n"
        $bytes = [Text.Encoding]::ASCII.GetBytes($head + $body)
        $stream.Write($bytes, 0, $bytes.Length)
        $stream.Flush()
    } catch {
    } finally {
        $client.Close()
    }
}
