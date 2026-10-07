<#
.SYNOPSIS
Registers the Windows scheduled task that refreshes the public Developer Docs corpus.

.DESCRIPTION
GitHub Actions cannot run for this repository's account, so the daily corpus refresh
that .github/workflows/update-docs-ai-search.yml used to perform runs on this machine.
The task runs scripts/update-docs-ai-search-scheduled.js daily as the current user while
that user is logged on, with its console window hidden, and starts a missed run as soon as
the machine is available. Task Scheduler's Last Run Result is the wrapper's exit code
(0 ok, 1 updater failure, 2 missing credentials). Credentials come from
~/.config/flavor-agent/docs-ai-search.env.
See docs/reference/developer-docs-public-corpus-runbook.md.

.EXAMPLE
pwsh -NoProfile -File scripts/register-docs-ai-search-task.ps1

.EXAMPLE
pwsh -NoProfile -File scripts/register-docs-ai-search-task.ps1 -Unregister
#>
[CmdletBinding()]
param(
	# Local time of the daily run.
	[string] $At = '05:17',
	# node.exe to run. Defaults to the user-level, then machine-wide, Node.js install.
	[string] $NodePath,
	[string] $TaskName = 'Flavor Agent docs corpus update',
	[switch] $Unregister
)

$ErrorActionPreference = 'Stop'

if ($Unregister) {
	Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false
	Write-Output "Removed scheduled task '$TaskName'."
	return
}

$repoRoot = Split-Path -Parent $PSScriptRoot
$wrapper = Join-Path $PSScriptRoot 'update-docs-ai-search-scheduled.js'

if (-not $NodePath) {
	# Prefer a standalone Node.js install: copies bundled inside other tools
	# (for example WordPress Studio's) can move or change version on update.
	$NodePath = @(
		(Join-Path $env:LOCALAPPDATA 'nodejs\node.exe'),
		(Join-Path $env:ProgramFiles 'nodejs\node.exe')
	) | Where-Object { Test-Path $_ } | Select-Object -First 1
	if (-not $NodePath) {
		$NodePath = (Get-Command node).Source
	}
}

# Hidden Windows PowerShell (its window flashes briefly) passes node's exit code
# back to Task Scheduler; conhost --headless hides the window but drops the code.
# A windowless S4U task would need an elevated registration.
$command = "& '{0}' '{1}'; exit `$LASTEXITCODE" -f $NodePath.Replace("'", "''"), $wrapper.Replace("'", "''")
$action = New-ScheduledTaskAction `
	-Execute (Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe') `
	-Argument ('-NoProfile -NonInteractive -WindowStyle Hidden -Command "{0}"' -f $command) `
	-WorkingDirectory $repoRoot
$trigger = New-ScheduledTaskTrigger -Daily -At $At
$settings = New-ScheduledTaskSettingsSet `
	-StartWhenAvailable `
	-RunOnlyIfNetworkAvailable `
	-AllowStartIfOnBatteries `
	-DontStopIfGoingOnBatteries `
	-ExecutionTimeLimit (New-TimeSpan -Hours 2) `
	-MultipleInstances IgnoreNew
$principal = New-ScheduledTaskPrincipal `
	-UserId ([Security.Principal.WindowsIdentity]::GetCurrent().Name) `
	-LogonType Interactive `
	-RunLevel Limited

Register-ScheduledTask `
	-TaskName $TaskName `
	-Description 'Refreshes the Flavor Agent public Developer Docs corpus. See docs/reference/developer-docs-public-corpus-runbook.md.' `
	-Action $action `
	-Trigger $trigger `
	-Settings $settings `
	-Principal $principal `
	-Force | Out-Null

$info = Get-ScheduledTaskInfo -TaskName $TaskName
Write-Output "Registered '$TaskName': daily at $At using $NodePath. Next run: $($info.NextRunTime)."
