<#
  Stops and removes the scheduled task of the Likha HR biometrics agent. Run as administrator.
  The data folder (log, monthly CSV archive, outbox, key) is kept; delete
  C:\ProgramData\LikhaBiometrics by hand only after any outbox.txt in it is empty or gone.
#>
param([string]$TaskName = 'Likha HR Biometrics Agent')

$ErrorActionPreference = 'Stop'
if (Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue) {
    Stop-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false
    Write-Host "Removed the scheduled task '$TaskName'."
} else {
    Write-Host "No scheduled task '$TaskName'."
}
