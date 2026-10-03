[CmdletBinding()]
param(
    [Parameter(Mandatory)][ValidateSet('Preflight','Smoke','Dedup','ResumeDedup','Evaluate','ResumeEvaluate')][string]$Action,
    [ValidateSet('yeswiki','cacti')][string]$Target = 'yeswiki',
    [string]$FrozenRoot,
    [string]$RecoveryRoot,
    [string]$Image = 'lailaps-pentest-agent:semantic-p0-20260929-v2',
    [ValidateSet('auto','InferenceNet')][string]$SmokeDeduperProvider = 'InferenceNet'
)
$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '../..')).Path
if (-not $FrozenRoot) { $FrozenRoot = Join-Path $repo 'storage/app/reader-evaluation/p0-recovery-frozen-20260929-v2' }
if (-not $RecoveryRoot) { $RecoveryRoot = Join-Path $repo 'storage/app/reader-evaluation/p0-recovery-20260929-v2' }
$frozen = (Resolve-Path (Join-Path $FrozenRoot $Target)).Path
$source = 'C:/Users/Alessandro/Documents/Codex/2026-09-28/ok-vorrei-fare-la-seguente-cosa/outputs/reader-round/sources/' + $Target
$destination = Join-Path $RecoveryRoot ('recovery/' + $Target)
if ($Action -eq 'Preflight') { $destination = Join-Path $RecoveryRoot ('preflight/' + $Target) }
if ($Action -eq 'Smoke') {
    if ($Target -ne 'yeswiki') { throw 'The bounded serving smoke uses the YesWiki Git source fixture.' }
    $destination = Join-Path $RecoveryRoot 'smoke'
    if (Test-Path (Join-Path $destination 'run')) { throw 'Smoke already exists. Inspect its result; do not reset its envelope.' }
}
if ($Action -in @('Dedup','ResumeDedup')) {
    $preflight = Get-Content (Join-Path $RecoveryRoot "preflight/$Target/phase-status.json") -Raw | ConvertFrom-Json
    if (-not $preflight.complete) { throw "Offline preflight suspended ($($preflight.stop_reason)); no paid replay." }
    $smoke = Get-Content (Join-Path $RecoveryRoot 'smoke/phase-status.json') -Raw | ConvertFrom-Json
    if (-not $smoke.complete) { throw 'Paid smoke did not satisfy the technical gate; no full replay.' }
}
if ($Action -in @('Evaluate','ResumeEvaluate')) {
    $phase = Get-Content (Join-Path $destination 'phase-status.json') -Raw | ConvertFrom-Json
    $dedupComplete = $phase.complete -and $phase.phase -eq 'dedup'
    $dedupComplete = $dedupComplete -or $phase.dedup_complete
    if (-not $dedupComplete) { throw "Dedup incomplete ($($phase.stop_reason)); evaluator stays pending." }
}
New-Item -ItemType Directory -Force -Path $destination | Out-Null
$destination = (Resolve-Path $destination).Path
$mounts = @('--mount', "type=bind,source=$frozen,target=/frozen,readonly",
            '--mount', "type=bind,source=$source,target=/workspace,readonly",
            '--mount', "type=bind,source=$destination,target=/artifacts")
$dockerArguments = @('run','--rm')
if ($Action -eq 'Preflight') { $dockerArguments += @('--network','none') }
else { $dockerArguments += @('--env-file', (Join-Path $repo 'agent/pentest-agent/.env')) }
$dockerArguments += $mounts
if ($Action -eq 'Smoke') {
    $smokeConfig = Get-Content (Join-Path $frozen 'roles.json') -Raw | ConvertFrom-Json
    $smokeConfig.deduper.provider = $SmokeDeduperProvider
    $smokeConfig.deduper.total_points = 50000
    $smokeConfig.evaluator.total_points = 50000
    $smokeConfig | Add-Member -NotePropertyName budget_purpose -NotePropertyValue 'Explicit new smoke envelope: 50k EP per role; no discovery' -Force
    $smokeConfig | ConvertTo-Json -Depth 12 | Set-Content (Join-Path $destination 'smoke-roles.json') -Encoding utf8
    $dockerArguments += @('--entrypoint','/app/.venv/bin/python',$Image,'/app/scripts/semantic_smoke.py',
        '--config','/artifacts/smoke-roles.json','--source-root','/workspace','--output','/artifacts/run')
} else {
    $mode = if ($Action -eq 'Preflight') { 'full' } elseif ($Action -in @('Evaluate','ResumeEvaluate')) { 'evaluate' } else { 'dedup' }
    $dockerArguments += @($Image,'reader-evaluate','--collection','/frozen/collection.json','--target',$Target,
        '--output','/artifacts','--mode',$mode,'--roles-config','/frozen/roles.json',
        '--manifests','/frozen/manifests.json','--source-root','/workspace')
    if ($Action -eq 'Preflight') { $dockerArguments += '--preflight-only' }
    if ($Action -in @('ResumeDedup','ResumeEvaluate')) { $dockerArguments += '--resume-failed' }
    if ($mode -eq 'evaluate') { $dockerArguments += @('--dedup-artifact','/artifacts/dedup.json') }
}
& docker @dockerArguments
$resultCode = $LASTEXITCODE
if ($Action -eq 'Smoke' -and (Test-Path (Join-Path $destination 'run/phase-status.json'))) {
    Copy-Item -LiteralPath (Join-Path $destination 'run/phase-status.json') -Destination (Join-Path $destination 'phase-status.json')
}
$phasePath = Join-Path $destination 'phase-status.json'
if (-not (Test-Path $phasePath)) { throw 'Process did not publish phase status; do not start another stage.' }
$phase = Get-Content $phasePath -Raw | ConvertFrom-Json
Write-Host "Phase=$($phase.phase) status=$($phase.status) reason=$($phase.stop_reason) artifacts=$destination"
if ($resultCode -ne $phase.exit_code) { throw 'Process exit code and phase status disagree.' }
exit $resultCode
