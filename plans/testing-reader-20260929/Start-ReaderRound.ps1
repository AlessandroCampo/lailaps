[CmdletBinding()]
param(
    [Parameter(Mandatory)][ValidateSet('yeswiki', 'cacti')][string]$Target,
    [switch]$PreflightOnly
)
$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '../..')).Path
$sources = 'C:/Users/Alessandro/Documents/Codex/2026-09-28/ok-vorrei-fare-la-seguente-cosa/outputs/reader-round/sources'
$snapshot = @{ yeswiki = '7325759547611def210a731b103878bd696c419c'; cacti = '6482af547c204199e829b7a0df0b7a13db3e0a58' }[$Target]
$source = Join-Path $sources $Target
$glm = 'z-ai/glm-5.3-flash'
$provider = 'InferenceNet'
$baseline = 'yeswiki-reader-global-20260927-225341'
function Invoke-Artisan([string[]]$Arguments) {
    Write-Host ('php artisan ' + ($Arguments -join ' ')) -ForegroundColor Cyan
    & php artisan @Arguments
    if ($LASTEXITCODE -ne 0) { throw "Command failed ($LASTEXITCODE). Artifacts are preserved; do not relaunch discovery to retry evaluation." }
}
function Run-DirectoryNames([string]$Path) {
    if (Test-Path -LiteralPath $Path) { Get-ChildItem -LiteralPath $Path -Directory | Select-Object -ExpandProperty Name }
}
function New-RunDirectory([string]$Path, [string[]]$Before) {
    $found = @(Run-DirectoryNames $Path | Where-Object { $_ -notin $Before })
    if ($found.Count -ne 1) { throw 'Expected exactly one new run. Avoid concurrent runs of this target; no latest-run fallback is used.' }
    return Join-Path $Path $found[0]
}
function Write-ReviewQueue([string]$Evaluation) {
    $state = Get-Content (Join-Path $Evaluation 'dedup.json') -Raw | ConvertFrom-Json
    $decisions = @($state.decisions.PSObject.Properties | Sort-Object Name)
    $sample = @($decisions | Where-Object { $_.Value.decision.decision -eq 'pass' -and $_.Value.dedup_status -eq 'adjudicated' } | Select-Object -First 10 -ExpandProperty Name)
    $rows = @($decisions | ForEach-Object {
        $d = $_.Value
        [ordered]@{ decision_key = $_.Name; proposal_id = $d.proposal_id; proposed = $d.decision;
            priority_review = ($d.decision.decision -ne 'pass' -or $d.dedup_status -ne 'adjudicated' -or $_.Name -in $sample);
            review_status = 'unreviewed'; expected_decision = $null; expected_related_ids = @(); rationale = $null }
    })
    [ordered]@{ status = 'provisional_not_ground_truth'; dedup_sha256 = (Get-FileHash (Join-Path $Evaluation 'dedup.json')).Hash;
        instructions = 'Review all block/partial/inconclusive and sampled pass against originals and registry; leave ambiguity unresolved. Also inspect residual duplicates among passed leads.';
        decisions = $rows } | ConvertTo-Json -Depth 15 | Set-Content (Join-Path $Evaluation 'review-queue.json') -Encoding utf8
}
function Evaluate-Parent([string]$Parent, [string]$Destination, [string]$ExistingDedup, [string]$Fixtures, [int]$Points) {
    $arguments = @('benchmark:reader-evaluate', $Target, "--parent=$Parent", "--output=$Destination",
        "--source=$source", "--source-snapshot=$snapshot", "--evaluator-model=$glm", "--evaluator-provider=$provider", "--evaluator-points=$Points")
    if ($Fixtures) { $arguments += "--fixtures=$Fixtures" }
    if ($ExistingDedup) { $arguments += @('--mode=evaluate', "--dedup-artifact=$ExistingDedup") }
    else { $arguments += @('--mode=full', "--deduper-model=$glm", "--deduper-provider=$provider", "--deduper-points=$Points") }
    Invoke-Artisan $arguments
    $phase = Get-Content (Join-Path $Destination 'phase-status.json') -Raw | ConvertFrom-Json
    if (-not $phase.complete -or $phase.exit_code -ne 0) {
        throw "Phase incomplete ($($phase.stop_reason)). No evaluator continuation or historical replay; artifacts preserved."
    }
    Write-ReviewQueue $Destination
}
Push-Location $repo
try {
    $head = & git -C $source rev-parse HEAD
    if ($LASTEXITCODE -ne 0 -or $head.Trim() -ne $snapshot) { throw 'Prepared source snapshot mismatch.' }
    $dirty = & git -C $source status --porcelain --untracked-files=all --ignored
    if ($LASTEXITCODE -ne 0 -or $dirty) { throw 'Prepared source is not clean.' }
    & docker info --format '{{.ServerVersion}}'
    if ($LASTEXITCODE -ne 0) { throw 'Docker engine unavailable.' }
    & docker run --rm --network none lailaps-pentest-agent:dev reader-normalize --help | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'Runtime image is missing the normalizer.' }
    if (-not (Test-Path 'agent/pentest-agent/.env')) { throw 'Agent environment file missing.' }
    if ($Target -eq 'yeswiki' -and -not (Test-Path "storage/framework/lailaps-reader-global/$baseline/fixtures")) { throw 'Historical fixture mapping missing.' }
    if ($PreflightOnly) { Write-Host "Offline preflight OK: $Target. No provider request made."; return }

    $round = Join-Path $repo ('storage/app/reader-evaluation/p0-' + $Target + '-' + (Get-Date -Format 'yyyyMMdd-HHmmss-fff'))
    New-Item -ItemType Directory -Path $round | Out-Null
    $reconId = '01M309MYBKSARVPA2HWVTYT450'
    if ($Target -eq 'cacti') {
        $reconRoot = Join-Path $repo 'storage/app/runs/cacti/recon-global'
        $beforeRecon = @(Run-DirectoryNames $reconRoot)
        Invoke-Artisan @('benchmark:recon', 'cacti', "--path=$source", '--global', '--assignments', '--repetitions=1', "--recon-model=$glm", '--timeout=1200')
        $reconDir = New-RunDirectory $reconRoot $beforeRecon
        $reconId = & php (Join-Path $PSScriptRoot 'resolve-recon.php') (Split-Path $reconDir -Leaf)
        if ($LASTEXITCODE -ne 0) { throw 'Fresh Recon was not accepted.' }
        $reconId = $reconId.Trim()
        Set-Content (Join-Path $round 'recon-run.txt') $reconDir
    }
    $readerRoot = Join-Path $repo "storage/app/runs/$Target/reader-global"
    $beforeReader = @(Run-DirectoryNames $readerRoot)
    $arguments = @('benchmark:reader-global', $Target, "--path=$source", "--recon-artifact=$reconId", '--concurrency=3',
        '--assignment-points=500000', '--reader-model=xiaomi/mimo-v2.6-pro', '--reader-provider=Xiaomi',
        '--reader-checkpoint-strategy=reader_checkpoint', '--operational-context-window=1048576',
        '--max-prompt-input-tokens=900000', '--timeout=7200', '--follow-slot=1')
    if ($Target -eq 'yeswiki') { $arguments += @('--area=area-assignment-4', '--area=area-assignment-5', '--area=area-assignment-6', '--defer-enrichments') }
    else { $arguments += @("--deduper-model=$glm", "--deduper-provider=$provider", '--deduper-points=1000000') }
    $arguments | ConvertTo-Json | Set-Content (Join-Path $round 'reader-command.json') -Encoding utf8
    Invoke-Artisan $arguments
    $readerDir = New-RunDirectory $readerRoot $beforeReader
    $parent = Join-Path $readerDir ((Split-Path $readerDir -Leaf) + '-outcome.json')
    Set-Content (Join-Path $round 'reader-parent.txt') $parent
    if ($Target -eq 'cacti') {
        Evaluate-Parent $parent (Join-Path $round 'current') (Join-Path $readerDir 'dedup.json') '' 1000000
    } else {
        Evaluate-Parent $parent (Join-Path $round 'current') '' '' 250000
        $oldParent = Join-Path $readerRoot "$baseline/$baseline-outcome.json"
        $oldFixtures = Join-Path $repo "storage/framework/lailaps-reader-global/$baseline/fixtures"
        Evaluate-Parent $oldParent (Join-Path $round 'historical') '' $oldFixtures 250000
    }
    Write-Host "Round artifacts and provisional review queues: $round" -ForegroundColor Green
} finally { Pop-Location }
