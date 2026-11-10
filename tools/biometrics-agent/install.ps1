<#
  Installs the Likha HR biometrics agent on the office PC (spec 012). Run it once, in
  PowerShell opened with "Run as administrator":

    powershell -ExecutionPolicy Bypass -File .\install.ps1 -BaseUrl https://<likha-hr-host>/api/biometrics

  It asks for the biometrics feed key (typed, not shown, never written anywhere but the key
  file), then:
    - copies agent.ps1 and ZkProtocol.ps1 to C:\Program Files\LikhaBiometrics (only
      administrators can change them, since SYSTEM runs them);
    - writes C:\ProgramData\LikhaBiometrics\config.json and the key file feed.key, which only
      SYSTEM and Administrators can read;
    - reads the device once to show it works (agent.ps1 -Test, nothing is posted);
    - registers the scheduled task "Likha HR Biometrics Agent" and starts it.
  Running it again updates the scripts and the config; leave the key empty to keep the old one.
#>
param(
    [Parameter(Mandatory = $true)][string]$BaseUrl,
    [string]$DeviceIp = '192.168.1.201',
    [int]$DevicePort = 4370,
    [int]$CommKey = 0,
    [string]$AgentId = 'office-pc',
    [string]$InstallDir = (Join-Path $env:ProgramFiles 'LikhaBiometrics'),
    [string]$DataDir = (Join-Path $env:ProgramData 'LikhaBiometrics'),
    [string]$TaskName = 'Likha HR Biometrics Agent',
    # Only for a test server on the office network; the key then travels unencrypted.
    [switch]$AllowHttp
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'InstallHelpers.ps1')

$admin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $admin) { throw 'Open PowerShell with "Run as administrator" and run install.ps1 again.' }
if ($BaseUrl -notmatch '^https://' -and -not $AllowHttp) { throw 'Use the https address of Likha HR: over http the key would travel unencrypted.' }
if ($AgentId -notmatch '^[A-Za-z0-9._-]{1,64}$') { throw 'AgentId may hold only letters, digits, dot, dash and underscore (no spaces).' }

foreach ($d in @($InstallDir, $DataDir)) {
    if (-not (Test-Path -LiteralPath $d)) { New-Item -ItemType Directory -Path $d -Force | Out-Null }
}
Protect-Path $DataDir

Copy-Item -LiteralPath (Join-Path $PSScriptRoot 'agent.ps1') -Destination $InstallDir -Force
Copy-Item -LiteralPath (Join-Path $PSScriptRoot 'ZkProtocol.ps1') -Destination $InstallDir -Force

$configPath = Join-Path $DataDir 'config.json'
$keyPath = Join-Path $DataDir 'feed.key'
$config = [ordered]@{
    base_url    = $BaseUrl.TrimEnd('/')
    agent_id    = $AgentId
    device_ip   = $DeviceIp
    device_port = $DevicePort
    comm_key    = $CommKey
    data_dir    = $DataDir
    key_file    = $keyPath
}
if ($AllowHttp) { $config.allow_http = $true }
[IO.File]::WriteAllText($configPath, ($config | ConvertTo-Json), (New-Object Text.UTF8Encoding($false)))
Write-Host "Config written: $configPath"

$secure = Read-Host -AsSecureString 'Biometrics feed key (BIOMETRICS_FEED_KEY of Likha HR; not shown; Enter alone keeps the current key)'
if ($secure.Length -gt 0) {
    Write-KeyFile $keyPath $secure
    Write-Host "Key saved in $keyPath (SYSTEM and Administrators only)."
} elseif (-not (Test-Path -LiteralPath $keyPath)) {
    throw 'No key given and none saved before. Run install.ps1 again and type the key.'
} else {
    Write-Host 'Key unchanged.'
}

Write-Host ''
Write-Host 'Reading the device once (nothing is posted):'
& (Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe') -NoProfile -ExecutionPolicy Bypass `
    -File (Join-Path $InstallDir 'agent.ps1') -ConfigPath $configPath -Test

$task = New-AgentTask (Join-Path $InstallDir 'agent.ps1') $configPath
Register-ScheduledTask -TaskName $TaskName -InputObject $task -Force | Out-Null
Start-ScheduledTask -TaskName $TaskName
Write-Host ''
Write-Host "Scheduled task '$TaskName' registered and started: every minute, as SYSTEM, also after a restart."
Write-Host "Log: $DataDir\logs\agent.log    Archive: $DataDir\archive"
