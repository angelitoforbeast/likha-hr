# Helpers of install.ps1, kept apart so the agent tests can check them without installing.

$SidSystem = 'S-1-5-18'
$SidAdministrators = 'S-1-5-32-544'

# Only SYSTEM and Administrators may open the path; nothing is inherited from the folder above.
# For a folder the rules are inherited by what is inside it.
function Protect-Path([string]$path) {
    $isDir = (Get-Item -LiteralPath $path) -is [IO.DirectoryInfo]
    $acl = if ($isDir) { New-Object Security.AccessControl.DirectorySecurity } else { New-Object Security.AccessControl.FileSecurity }
    $acl.SetAccessRuleProtection($true, $false)
    $inherit = if ($isDir) { [Security.AccessControl.InheritanceFlags]'ContainerInherit, ObjectInherit' } else { [Security.AccessControl.InheritanceFlags]::None }
    foreach ($sid in @($SidSystem, $SidAdministrators)) {
        $rule = New-Object Security.AccessControl.FileSystemAccessRule(
            (New-Object Security.Principal.SecurityIdentifier($sid)),
            [Security.AccessControl.FileSystemRights]::FullControl,
            $inherit,
            [Security.AccessControl.PropagationFlags]::None,
            [Security.AccessControl.AccessControlType]::Allow)
        $acl.AddAccessRule($rule)
    }
    Set-Acl -LiteralPath $path -AclObject $acl
}

# The key file: created empty, locked to SYSTEM and Administrators, and only then filled, so the
# key never sits in a file others can read. The plain text lives only in this function.
function Write-KeyFile([string]$path, [Security.SecureString]$secure) {
    if (Test-Path -LiteralPath $path) { Remove-Item -LiteralPath $path -Force }
    [IO.File]::WriteAllText($path, '')
    Protect-Path $path
    $ptr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
    try {
        $plain = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr)
        [IO.File]::WriteAllText($path, $plain.Trim(), (New-Object Text.UTF8Encoding($false)))
    } finally {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr)
        $plain = $null
    }
}

# The scheduled task: runs as SYSTEM (nobody needs to be signed in), at startup and from now on,
# every minute, one run at a time, restarted when a run fails. The key is not on its command line.
function New-AgentTask([string]$agentPath, [string]$configPath) {
    $ps = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'
    $action = New-ScheduledTaskAction -Execute $ps `
        -Argument ('-NoProfile -NonInteractive -ExecutionPolicy Bypass -WindowStyle Hidden -File "{0}" -ConfigPath "{1}"' -f $agentPath, $configPath)
    $every = New-ScheduledTaskTrigger -Once -At ((Get-Date).AddMinutes(1)) -RepetitionInterval (New-TimeSpan -Minutes 1)
    $boot = New-ScheduledTaskTrigger -AtStartup
    $boot.Repetition = $every.Repetition
    $principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
    $settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1) `
        -ExecutionTimeLimit (New-TimeSpan -Minutes 10) -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
    return New-ScheduledTask -Action $action -Trigger @($boot, $every) -Principal $principal -Settings $settings `
        -Description 'Likha HR biometrics agent: posts the office device punches to Likha HR every minute (spec 012).'
}
