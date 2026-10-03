<?php

namespace App\Console\Commands;

use App\Models\BenchmarkStageArtifact;
use App\Services\Audit\RunStorage;
use App\Services\Pentest\BenchmarkAuditSource;
use App\Services\Pentest\BenchmarkCatalog;
use App\Services\Pentest\BenchmarkEvaluator;
use App\Services\Pentest\BenchmarkStageArtifactRegistry;
use App\Services\Pentest\BenchmarkStageProcessRunner;
use App\Services\Pentest\BenchmarkTechnicalFailure;
use App\Services\Sandbox\Audit\AuditProfile;
use App\Services\Sandbox\Audit\SandboxPreparationService;
use App\Services\Sandbox\DTO\SandboxSpecDTO;
use App\Services\Sandbox\SandboxService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

final class BenchmarkReaderGlobal extends Command
{
    private const MAX_AREAS = 48;
    private bool $dedupResumed = false;

    /** @var array<int, string> */
    private const SLOT_COLORS = [1 => 'cyan', 2 => 'green', 3 => 'yellow', 4 => 'magenta'];

    protected $signature = 'benchmark:reader-global
        {target-id : Target benchmark}
        {--path= : Sorgente; default targets/<target-id>}
        {--recon-artifact= : Golden Recon global se omesso}
        {--area=* : Area iniziale della Golden Recon; ripetibile}
        {--defer-enrichments : Acquisisce le proposte senza avviare nuove aree}
        {--deduper-model= : Abilita normalizzazione semantica nel coordinatore}
        {--deduper-provider= : Provider esplicito del deduper}
        {--deduper-points= : Budget EP totale indipendente del deduper}
        {--deduper-mode=deterministic : deterministic, llm oppure off}
        {--image= : Runtime image for this Reader run}
        {--result-json= : Write run ID and status to this JSON path}
        {--roles-config= : Full role configuration; incompatible with individual deduper overrides}
        {--resume-failed : Explicitly resume technical failures and pending normalization}
        {--concurrency=4 : Processi per tranche, da 1 a 4}
        {--assignment-points=500000 : Envelope Reader+Reviewer per area}
        {--follow-slot=1 : Transcript completo dello slot, 0 per nessuno}
        {--reader-model= : Modello Reader}
        {--reviewer-model= : Modello Reviewer}
        {--reader-provider= : Provider OpenRouter da fissare per il Reader}
        {--operational-context-window=1048576 : Cap fisico del contesto operativo}
        {--max-prompt-input-tokens=900000 : Guardrail dell input completo}
        {--reader-checkpoint-strategy=reviewer : reviewer oppure reader_checkpoint}
        {--reuse-sandbox= : Audit ID di una sandbox pronta}
        {--timeout=7200 : Timeout per assignment in secondi}
        {--tool-output : Mostra gli output tool nel transcript seguito}';

    protected $description = 'Esegue la Golden Recon globale con Reader indipendenti in tranche concorrenti';

    public function handle(
        BenchmarkCatalog $catalog,
        BenchmarkAuditSource $auditSource,
        BenchmarkEvaluator $evaluator,
        BenchmarkStageArtifactRegistry $registry,
        BenchmarkStageProcessRunner $runner,
        BenchmarkTechnicalFailure $technicalFailure,
        RunStorage $storage,
        SandboxService $sandboxes,
        SandboxPreparationService $preparation,
    ): int {
        $target = (string) $this->argument('target-id');
        $concurrency = (int) $this->option('concurrency');
        $followSlot = (int) $this->option('follow-slot');
        $points = (float) $this->option('assignment-points');
        $timeout = (int) $this->option('timeout');
        $operationalContextWindow = (int) $this->option('operational-context-window');
        $maxPromptInputTokens = (int) $this->option('max-prompt-input-tokens');
        if ($concurrency < 1 || $concurrency > 4) {
            throw new InvalidArgumentException('--concurrency deve essere compreso fra 1 e 4.');
        }
        if ($followSlot < 0 || $followSlot > $concurrency) {
            throw new InvalidArgumentException('--follow-slot deve essere 0 oppure uno slot attivo.');
        }
        if ($points <= 0 || $timeout <= 0 || $operationalContextWindow <= 0 || $maxPromptInputTokens <= 0) {
            throw new InvalidArgumentException('Assignment points, timeout e cap di contesto devono essere positivi.');
        }
        $readerCheckpointStrategy = (string) $this->option('reader-checkpoint-strategy');
        if (! in_array($readerCheckpointStrategy, ['reviewer', 'reader_checkpoint'], true)) {
            throw new InvalidArgumentException(
                'reader-checkpoint-strategy deve essere reviewer oppure reader_checkpoint.'
            );
        }

        $manifests = $catalog->forTarget($target);
        if ($manifests === []) {
            throw new InvalidArgumentException('Il target non contiene manifest benchmark.');
        }
        $snapshot = $this->sharedSnapshot($manifests, $target);
        $recon = $this->option('recon-artifact')
            ? BenchmarkStageArtifact::query()->findOrFail((string) $this->option('recon-artifact'))
            : $registry->goldenRecon($target, 'global', $snapshot);
        $this->validateRecon($recon, $target, $snapshot);
        $goldenAreas = array_values((array) data_get($recon->payload, 'areas', []));
        $selectedIds = array_values((array) $this->option('area'));
        if (count($selectedIds) !== count(array_unique($selectedIds))) {
            throw new InvalidArgumentException('--area contiene ID duplicati.');
        }
        $knownIds = array_column($goldenAreas, 'area_id');
        if (array_diff($selectedIds, $knownIds) !== []) {
            throw new InvalidArgumentException('--area contiene ID inesistenti nella Golden Recon.');
        }
        $selectedAreas = $selectedIds === [] ? $goldenAreas : array_values(array_filter(
            $goldenAreas, fn (array $area): bool => in_array($area['area_id'], $selectedIds, true),
        ));
        if ($selectedAreas === []) {
            throw new InvalidArgumentException('La selezione delle aree e vuota.');
        }
        $deferEnrichments = (bool) $this->option('defer-enrichments');
        $deduperMode = (string) $this->option('deduper-mode');
        if (! in_array($deduperMode, ['deterministic', 'llm', 'off'], true)) {
            throw new InvalidArgumentException('--deduper-mode must be deterministic, llm or off.');
        }
        $hasLlmOptions = (string) $this->option('deduper-model') !== '' || (string) $this->option('roles-config') !== '';
        if ($hasLlmOptions && $deduperMode === 'deterministic') {
            $deduperMode = 'llm'; // Legacy explicit options retain their meaning.
        }
        if ($hasLlmOptions && $deduperMode === 'off') {
            throw new InvalidArgumentException('LLM deduper options incompatible with --deduper-mode=off.');
        }
        $dedupEnabled = $deduperMode === 'llm';
        if ($dedupEnabled && ! $hasLlmOptions) {
            throw new InvalidArgumentException('LLM mode requires a deduper model or roles config.');
        }
        if ($this->option('roles-config')) {
            foreach (['model', 'provider', 'points'] as $field) {
                if ($this->option('deduper-'.$field) !== null) {
                    throw new InvalidArgumentException('--roles-config is incompatible with individual deduper overrides.');
                }
            }
        }
        if ($dedupEnabled && ! $this->option('roles-config') && ((string) $this->option('deduper-provider') === '' || (float) $this->option('deduper-points') <= 0)) {
            throw new InvalidArgumentException('Deduper requires explicit provider and positive total points.');
        }
        $runtimeFiles = [];
        foreach (File::allFiles(base_path('agent/pentest-agent/src/pentest_agent')) as $file) {
            if (in_array($file->getExtension(), ['py', 'json'], true)) {
                $runtimeFiles['agent/pentest-agent/src/pentest_agent/'.str_replace('\\', '/', $file->getRelativePathname())] = hash_file('sha256', $file->getPathname());
            }
        }
        foreach (['agent/pentest-agent/uv.lock', 'agent/pentest-agent/pyproject.toml', 'app/Console/Commands/BenchmarkReaderGlobal.php', 'app/Services/Pentest/BenchmarkStageProcessRunner.php'] as $path) {
            $runtimeFiles[$path] = hash_file('sha256', base_path($path));
        }
        ksort($runtimeFiles);
        $runtimeFingerprint = hash('sha256', json_encode($runtimeFiles, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $models = [
            'reader' => $this->option('reader-model'),
            'reviewer' => $this->option('reviewer-model'),
        ];

        $source = $this->absolutePath((string) ($this->option('path') ?: base_path('targets/'.$target)));
        if ($source === '' || ! is_dir($source)) {
            throw new InvalidArgumentException('Directory sorgente inesistente.');
        }
        $oldImage = config('pentest.agent.image');
        if ($this->option('image')) {
            config()->set('pentest.agent.image', (string) $this->option('image'));
        }
        $parentLocation = $storage->create($target, ['reader-global']);
        $parentRunId = $parentLocation['run_id'];
        $parentDirectory = $parentLocation['directory'];
        $workDirectory = storage_path("framework/lailaps-reader-global/{$parentRunId}");
        $started = microtime(true);
        $children = [];
        $mappedChildren = [];
        $aggregateLeads = [];
        $enrichmentProposals = [];
        $aggregateCases = [];
        $caseDiagnostics = [];
        $manifestResults = [];
        $totalPoints = 0.0;
        $technicalFailureSeen = false;
        $limitReached = false;
        $liveProcesses = [];

        try {
            $agentSource = $auditSource->materialize(
                $source, $workDirectory.'/source', $target, $catalog->descriptor($target),
            );
            File::ensureDirectoryExists($workDirectory.'/fixtures');
            $context = $workDirectory.'/benchmark-context.json';
            File::put($context, json_encode([
                'schema' => 'lailaps.benchmark', 'version' => 1,
                'target_id' => $target, 'source_snapshot' => $snapshot,
                'category' => ['taxonomy' => 'scope', 'id' => 'global', 'name' => 'Global Reader'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            $sandbox = null;
            if ($this->option('reuse-sandbox')) {
                $profile = AuditProfile::fromProject($agentSource);
                $sandbox = $sandboxes->reuse(
                    (string) $this->option('reuse-sandbox'),
                    new SandboxSpecDTO(
                        auditId: $parentRunId,
                        projectPath: $agentSource,
                        webService: $profile->service,
                        basePath: $profile->basePath,
                        healthPath: $profile->healthPath,
                        benchmarkTargetId: $target,
                        sourceSnapshot: $snapshot,
                    ),
                );
                $preparation->verifyReadiness($sandbox, $profile);
            }

            $queue = array_map(
                fn (array $area): array => ['area' => $area, 'origin' => 'golden'],
                $selectedAreas,
            );
            if ($dedupEnabled) {
                $items = array_map(fn (array $area): array => [
                    'id' => $parentRunId.':'.$area['area_id'], 'kind' => 'task',
                    'project' => $target, 'snapshot' => $snapshot, 'payload' => $area, 'status' => 'queued',
                ], $selectedAreas);
                $normalization = $this->normalizeProducts($items, $target, $snapshot, $agentSource, $parentDirectory, $parentRunId, $runner, $storage, false, true);
                $queue = $this->normalizationQueue($normalization);
                File::put($parentDirectory.'/recon-projection.json', json_encode([
                    'original_selected_ids' => array_column($selectedAreas, 'area_id'), 'normalization' => $normalization,
                    'global_complete' => count($selectedAreas) === count($goldenAreas) && $normalization['complete'],
                ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            }
            $knownEnrichments = [];
            $batchNumber = 0;
            $storage->writeOutcome($parentDirectory, $parentRunId, [
                'schema_version' => 1, 'publication_state' => 'partial',
                'termination_reason' => 'running',
                'golden_recon_artifact_id' => $recon->id,
                'source_snapshot' => $snapshot, 'category_recon' => $recon->payload,
                'reader_checkpoint_strategy' => $readerCheckpointStrategy,
                'deduper_mode' => $deduperMode,
                'runtime_sha256' => $runtimeFingerprint, 'models' => $models,
                'selected_initial_area_ids' => array_column($selectedAreas, 'area_id'),
                'defer_enrichments' => $deferEnrichments,
                'assignments' => [], 'suspected' => [],
                'enrichment_proposals' => [],
                'coverage' => ['complete' => false, 'area_limit' => self::MAX_AREAS],
            ], [
                'schema' => 'lailaps.reader-global', 'version' => 1,
                'target_id' => $target, 'source_snapshot' => $snapshot, 'status' => 'running',
                'reader_checkpoint_strategy' => $readerCheckpointStrategy,
            ]);
            $active = [];
            while ($queue !== [] || $active !== []) {
                while ($queue !== [] && count($active) < $concurrency) {
                    $batchNumber++;
                    $assignment = array_shift($queue);
                    $slot = 1;
                    while (isset($active[$slot])) {
                        $slot++;
                    }
                    $area = $assignment['area'];
                    $location = $storage->create($target, ['reader-area']);
                    $runId = $location['run_id'];
                    $directory = $location['directory'];
                    $fixture = $workDirectory.'/fixtures/'.$runId.'.json';
                    File::put($fixture, json_encode($this->projectedFixture(
                        $recon, $area, $target, $snapshot,
                    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                    $args = [
                        'reader', '--source-root', $runner->containerized() ? '/workspace' : $agentSource,
                        '--audit-id', $runId, '--category', 'global',
                        '--recon-fixture', $runner->containerized() ? '/recon-fixture.json' : $fixture,
                        '--benchmark-context', $runner->containerized() ? '/benchmark-context.json' : $context,
                        '--assignment-points', (string) $points,
                        '--workspace-host-root', $agentSource,
                        '--reader-checkpoint-strategy', $readerCheckpointStrategy,
                        '--deduper-mode', 'off',
                        '--operational-context-window', (string) $operationalContextWindow,
                        '--max-prompt-input-tokens', (string) $maxPromptInputTokens,
                    ];
                    if ($this->option('reader-provider')) {
                        array_push($args, '--reader-provider', (string) $this->option('reader-provider'));
                    }
                    if ($sandbox !== null) {
                        array_push(
                            $args, '--target-audit-id', $sandbox->auditId,
                            '--target-container-id', $sandbox->containerId,
                        );
                    }
                    foreach (['reader-model', 'reviewer-model'] as $option) {
                        if ($this->option($option)) {
                            array_push($args, '--'.$option, (string) $this->option($option));
                        }
                    }
                    if ($this->option('tool-output')) {
                        $args[] = '--tool-output';
                    }
                    $process = $runner->process(
                        $agentSource, $directory, $runId, $args,
                        [$fixture => '/recon-fixture.json', $context => '/benchmark-context.json'],
                        $storage, $timeout,
                    );
                    $areaId = (string) $area['area_id'];
                    $active[$slot] = [
                        'slot' => $slot, 'batch' => $batchNumber, 'area' => $area,
                        'origin' => $assignment['origin'], 'run_id' => $runId,
                        'directory' => $directory, 'fixture' => $fixture,
                        'process' => $process, 'started' => microtime(true),
                        'seen_outputs' => [], 'exception' => null,
                    ];
                    $mappedChildren[] = ['run_id' => $runId, 'area_id' => $areaId,
                        'outcome' => $storage->outcomePath($directory, $runId), 'status' => 'running'];
                    $parentCurrent = $storage->outcome($parentDirectory, $parentRunId);
                    $parentReport = (array) ($parentCurrent['report'] ?? []);
                    $parentReport['mapped_children'] = $mappedChildren;
                    $storage->writeOutcome($parentDirectory, $parentRunId, $parentReport, $parentCurrent['benchmark'] ?? null);
                    $this->line(sprintf('[batch %d slot %d] start %s', $batchNumber, $slot, $areaId));
                    $process->start(function (string $type, string $buffer) use (
                        $storage, $directory, $runId, $batchNumber, $slot, $areaId, $followSlot,
                    ): void {
                        $storage->appendLog($directory, $runId, $buffer);
                        if ($followSlot === $slot) {
                            $this->writeFollowed($batchNumber, $slot, $areaId, $buffer);
                        }
                    });
                    $liveProcesses[$runId] = $process;
                }

                $finished = [];
                foreach ($active as $slot => &$child) {
                    /** @var Process $process */
                    $process = $child['process'];
                    try {
                        $process->checkTimeout();
                        if (! $process->isRunning()) {
                            $finished[] = $slot;
                        }
                    } catch (ProcessTimedOutException $exception) {
                        $child['exception'] = $exception;
                        $process->stop(1);
                        $finished[] = $slot;
                    }
                    if (in_array($slot, $finished, true)) {
                        $child['finished_at'] = now()->toIso8601String();
                        $child['duration_seconds'] = round(microtime(true) - $child['started'], 3);
                    }
                    $this->monitorPartial($child, $storage, $followSlot === $slot);
                }
                unset($child);
                if ($finished === []) {
                    usleep(100_000);

                    continue;
                }

                $rows = [];
                foreach ($finished as $slot) {
                    $child =& $active[$slot];
                    /** @var Process $process */
                    $process = $child['process'];
                    try {
                        $exit = $child['exception'] === null ? (int) $process->getExitCode() : 124;
                    } catch (Throwable $exception) {
                        $exit = 1;
                        $child['exception'] = $exception;
                    }
                    rescue(fn () => $runner->cleanup($child['run_id']), report: false);
                    unset($liveProcesses[$child['run_id']]);
                    $outcome = $storage->outcome($child['directory'], $child['run_id']);
                    $report = is_array($outcome['report'] ?? null) ? $outcome['report'] : [];
                    $technical = $report === [] || $technicalFailure->failed($report, $exit);
                    $technicalFailureSeen = $technicalFailureSeen || $technical;
                    $complete = ! $technical && data_get($report, 'coverage.complete') === true;
                    $status = $technical ? 'technical_failure' : ($complete ? 'complete' : 'incomplete');
                    $economic = (float) data_get($report, 'telemetry.economic_points_used', 0);
                    $totalPoints += $economic;
                    $duration = (float) $child['duration_seconds'];
                    $outputs = array_values(array_filter(
                        (array) ($report['structured_outputs'] ?? []), 'is_array',
                    ));
                    $leads = array_values(array_filter($outputs, fn (array $row): bool => ($row['role'] ?? null) === 'reader'
                        && ($row['output_type'] ?? null) === 'ReaderLead'
                        && ($row['accepted'] ?? false) === true
                    ));
                    $enrichments = array_values(array_filter($outputs, fn (array $row): bool => ($row['role'] ?? null) === 'reader'
                        && ($row['output_type'] ?? null) === 'AreaEnrichmentLead'
                    ));
                    foreach ($leads as $leadIndex => $lead) {
                        $localId = (string) ($lead['lead_id'] ?? 'lead-'.($leadIndex + 1));
                        $qualified = (string) $child['area']['area_id'].':'.$localId;
                        $aggregateLeads[] = [
                            'lead_id' => $qualified,
                            'area_id' => $child['area']['area_id'],
                            'run_id' => $child['run_id'],
                            'output_id' => $lead['output_id'],
                            'payload' => (array) ($lead['payload'] ?? []),
                            'source_refs' => (array) ($lead['source_refs'] ?? []),
                        ];
                    }
                    foreach ($enrichments as $enrichment) {
                        $proposal = (array) ($enrichment['payload'] ?? []);
                        $normalized = $this->normalizeEnrichmentProposal($proposal);
                        $hash = hash('sha256', json_encode(
                            $normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
                        ));
                        $accepted = ($enrichment['accepted'] ?? false) === true;
                        $pendingReview = ($enrichment['review_status'] ?? null) === 'pending_review';
                        $record = [
                            'source_area_id' => $child['area']['area_id'],
                            'source_run_id' => $child['run_id'],
                            'proposal' => $proposal,
                            'source_refs' => (array) ($enrichment['source_refs'] ?? []),
                            'accepted' => $accepted,
                            'rejection_reason' => $enrichment['rejection_reason'] ?? null,
                            'payload_hash' => $hash,
                            'status' => $accepted ? 'pending' : ($pendingReview ? 'pending_review' : 'rejected'),
                        ];
                        if ($dedupEnabled && ($accepted || $pendingReview)) {
                            $qualifiedRefs = [];
                            foreach ((array) ($enrichment['source_refs'] ?? []) as $ref) {
                                $qualifiedRefs[$child['run_id'].':'.$ref['source_ref_id']] = $ref;
                            }
                            $qualifiedPayload = $proposal;
                            $qualifiedPayload['source_ref_ids'] = array_map(fn (string $id): string => $child['run_id'].':'.$id, (array) ($proposal['source_ref_ids'] ?? []));
                            $normalization = $this->normalizeProducts([[
                                'id' => $child['run_id'].':'.$enrichment['output_id'], 'kind' => 'enrichment',
                                'project' => $target, 'snapshot' => $snapshot, 'payload' => $qualifiedPayload,
                                'source_refs' => $qualifiedRefs, 'status' => 'queued',
                            ]], $target, $snapshot, $agentSource, $parentDirectory, $parentRunId, $runner, $storage, $deferEnrichments, true);
                            $decision = collect($normalization['results'])->firstWhere('proposal_id', $child['run_id'].':'.$enrichment['output_id']);
                            $record['deduplication'] = $decision;
                            if (($decision['status'] ?? null) !== 'completed' || $decision['dispatch_ids'] === [] || $deferEnrichments) {
                                $record['status'] = ($decision['status'] ?? null) !== 'completed' ? 'pending_dedup' : ($deferEnrichments ? 'deferred' : ($decision['pending_integration'] !== [] ? 'pending_integration' : 'dedup_blocked'));
                                $enrichmentProposals[] = $record;
                                continue;
                            }
                            $canonical = collect($normalization['canonical'])->firstWhere('id', $decision['canonical_id']);
                            $proposal = $canonical['payload'];
                            $accepted = true;
                        }
                        if (! $accepted) {
                            $enrichmentProposals[] = $record;
                            continue;
                        }
                        if (isset($knownEnrichments[$hash])) {
                            $record['status'] = 'duplicate_payload';
                            $enrichmentProposals[] = $record;
                            continue;
                        }
                        $knownEnrichments[$hash] = true;
                        if ($deferEnrichments) {
                            $record['status'] = 'deferred';
                            $enrichmentProposals[] = $record;
                            continue;
                        }
                        if (count($children) + count($active) + count($queue) >= self::MAX_AREAS) {
                            $limitReached = true;
                            $record['status'] = 'area_limit';
                            $enrichmentProposals[] = $record;
                            continue;
                        }
                        $record['status'] = 'queued';
                        $enrichmentProposals[] = $record;
                        $queue[] = ['area' => $this->enrichmentArea($proposal, $hash), 'origin' => 'enrichment'];
                    }

                    $diagnostics = [];
                    foreach ($manifests as $manifest) {
                        $result = $evaluator->evaluate(
                            $child['directory'], $manifest, $source,
                            ['run_state' => $technical ? 'failed' : 'completed'], $report,
                        );
                        $diagnostics[] = $result;
                        $manifestId = $manifest->id();
                        $manifestResults[$manifestId] ??= [
                            'benchmark_id' => $manifestId,
                            'category' => $manifest->data['category'],
                            'case_ids_reached' => [],
                            'assignments' => [],
                        ];
                        $manifestResults[$manifestId]['assignments'][] = [
                            'area_id' => $child['area']['area_id'],
                            'status' => $status,
                            'score' => data_get($result, 'score.normalized'),
                            'suspected_recall' => data_get($result, 'suspected.recall'),
                        ];
                        foreach ((array) ($result['cases'] ?? []) as $case) {
                            $caseId = (string) ($case['id'] ?? '');
                            $diagnosticKey = $manifestId.':'.$caseId;
                            $caseDiagnostics[$diagnosticKey] ??= [
                                'benchmark_id' => $manifestId,
                                'case_id' => $caseId,
                                'expected_vulnerable' => (bool) ($case['expected_vulnerable'] ?? false),
                                'matched_lead' => false,
                                'wrong_hypothesis_same_anchor' => false,
                                'anchor_read' => false,
                                'file_reached' => false,
                            ];
                            $caseDiagnostics[$diagnosticKey]['matched_lead'] =
                                $caseDiagnostics[$diagnosticKey]['matched_lead']
                                || (($case['match_mode'] ?? null) === 'anchor_semantic'
                                    && ($case['matched_findings'] ?? []) !== []);
                            $caseDiagnostics[$diagnosticKey]['anchor_read'] =
                                $caseDiagnostics[$diagnosticKey]['anchor_read']
                                || data_get($case, 'reach.anchor_reached') === true;
                            $caseDiagnostics[$diagnosticKey]['file_reached'] =
                                $caseDiagnostics[$diagnosticKey]['file_reached']
                                || data_get($case, 'reach.file_reached') === true;
                            $caseDiagnostics[$diagnosticKey]['wrong_hypothesis_same_anchor'] =
                                $caseDiagnostics[$diagnosticKey]['wrong_hypothesis_same_anchor']
                                || collect((array) ($result['ambiguous_findings'] ?? []))->contains(
                                    fn (array $finding): bool => ($finding['reason'] ?? null) === 'semantic_mismatch'
                                        && in_array($caseId, (array) ($finding['candidate_case_ids'] ?? []), true),
                                );
                            if (($case['matched_findings'] ?? []) !== []) {
                                $aggregateCases[$caseId] = true;
                                $manifestResults[$manifestId]['case_ids_reached'][$caseId] = true;
                            }
                        }
                    }
                    $scorecard = $this->assignmentScorecard($report, $leads, $enrichments, $status, $duration);
                    $report['benchmark_scorecard'] = $scorecard;
                    $runArtifact = $registry->record([
                        'project_key' => $target, 'category' => 'global',
                        'source_commit' => $snapshot, 'parent_artifact_id' => $recon->id,
                        'run_id' => $child['run_id'], 'role' => 'reader',
                        'output_type' => 'ReaderRun',
                        'status' => $technical ? 'technical_failure' : 'valid',
                        'accepted' => ! $technical,
                        'payload' => [
                            'assignment_area_id' => $child['area']['area_id'],
                            'origin' => $child['origin'], 'status' => $status,
                            'coverage' => $report['coverage'] ?? null,
                            'reader_checkpoint_strategy' => $readerCheckpointStrategy,
                        ],
                        'usage' => (array) data_get($report, 'telemetry.role_usage', []),
                        'metrics' => [
                            'economic_points' => $economic,
                            'finished_at' => $child['finished_at'],
                            'duration_seconds' => $duration,
                            'scorecard' => $scorecard,
                        ],
                        'technical_error' => $technicalFailure->summary($report, $exit),
                    ]);
                    foreach ($outputs as $output) {
                        if (($output['role'] ?? null) !== 'reader') {
                            continue;
                        }
                        $registry->record([
                            'project_key' => $target, 'category' => 'global',
                            'source_commit' => $snapshot, 'parent_artifact_id' => $runArtifact->id,
                            'run_id' => $child['run_id'], 'role' => 'reader',
                            'output_type' => (string) ($output['output_type'] ?? 'unknown'),
                            'status' => 'valid', 'accepted' => (bool) ($output['accepted'] ?? false),
                            'payload' => [
                                'assignment_area_id' => $child['area']['area_id'],
                                'output' => (array) ($output['payload'] ?? []),
                                'source_refs' => (array) ($output['source_refs'] ?? []),
                                'lead_id' => $output['lead_id'] ?? null,
                            ],
                            'usage' => (array) ($output['usage'] ?? []),
                        ]);
                    }
                    $storage->writeOutcome($child['directory'], $child['run_id'], $report, [
                        'schema' => 'lailaps.reader-global-assignment', 'version' => 1,
                        'parent_run_id' => $parentRunId, 'parent_recon_artifact_id' => $recon->id,
                        'assignment_area_id' => $child['area']['area_id'], 'status' => $status,
                        'exit_code' => $exit, 'economic_points' => $economic,
                        'reader_checkpoint_strategy' => $readerCheckpointStrategy,
                        'finished_at' => $child['finished_at'], 'duration_seconds' => $duration,
                        'diagnostics_by_manifest' => $diagnostics,
                        'scorecard' => $scorecard,
                    ]);
                    $children[] = [
                        'area_id' => $child['area']['area_id'], 'origin' => $child['origin'],
                        'run_id' => $child['run_id'], 'status' => $status,
                        'lead_count' => count($leads), 'enrichment_count' => count($enrichments),
                        'reader_tool_calls' => (array) data_get($report, 'telemetry.tool_calls_details.reader', []),
                        'role_usage' => (array) data_get($report, 'telemetry.role_usage', []),
                        'economic_points' => $economic, 'finished_at' => $child['finished_at'],
                        'duration_seconds' => $duration,
                        'started_offset_seconds' => round($child['started'] - $started, 3),
                        'scorecard' => $scorecard,
                        'reader_context' => data_get($report, 'telemetry.reader_context'),
                        'reader_configuration' => $report['reader_benchmark'] ?? null,
                        'pricing' => data_get($report, 'telemetry.pricing'),
                        'outcome' => $storage->outcomePath($child['directory'], $child['run_id']),
                    ];
                    $rows[] = [
                        $child['area']['area_id'], $status, count($leads), count($enrichments),
                        round($economic), $duration.'s',
                    ];
                    unset($active[$slot]);
                    unset($child);
                }
                $this->table(['Area', 'Stato', 'Lead', 'Enrichment', 'Punti', 'Durata'], $rows);
                $storage->writeOutcome($parentDirectory, $parentRunId, [
                    'schema_version' => 1, 'publication_state' => 'partial',
                    'termination_reason' => 'running',
                    'golden_recon_artifact_id' => $recon->id,
                    'source_snapshot' => $snapshot, 'category_recon' => $recon->payload,
                    'reader_checkpoint_strategy' => $readerCheckpointStrategy,
                    'runtime_sha256' => $runtimeFingerprint, 'models' => $models,
                    'selected_initial_area_ids' => array_column($selectedAreas, 'area_id'),
                    'defer_enrichments' => $deferEnrichments,
                    'mapped_children' => $mappedChildren, 'assignments' => $children, 'suspected' => $aggregateLeads,
                    'enrichment_proposals' => $enrichmentProposals,
                    'coverage' => [
                        'complete' => false, 'area_limit' => self::MAX_AREAS,
                        'area_limit_reached' => $limitReached,
                        'areas_finished' => count($children), 'areas_queued' => count($queue),
                    ],
                    'telemetry' => ['economic_points_used' => $totalPoints],
                ], [
                    'schema' => 'lailaps.reader-global', 'version' => 1,
                    'target_id' => $target, 'source_snapshot' => $snapshot, 'status' => 'running',
                    'reader_checkpoint_strategy' => $readerCheckpointStrategy,
                ]);
            }

            $allClosed = count($children) >= count($selectedAreas)
                && collect($children)->every(fn (array $row): bool => $row['status'] === 'complete');
            $globalScope = count($selectedAreas) === count($goldenAreas);
            $readerToolCalls = [];
            foreach ($children as $child) {
                foreach ($child['reader_tool_calls'] as $name => $tool) {
                    $readerToolCalls[$name]['calls'] = ($readerToolCalls[$name]['calls'] ?? 0)
                        + (int) ($tool['calls'] ?? 0);
                    $readerToolCalls[$name]['enabled_by_area'][$child['area_id']] = $tool['enabled'] ?? null;
                    $readerToolCalls[$name]['available_by_area'][$child['area_id']] = $tool['available'] ?? null;
                }
            }
            $enrichmentCounts = array_count_values(array_column($enrichmentProposals, 'status'));
            if ($dedupEnabled || $deduperMode === 'deterministic') {
                $items = array_map(function (array $lead) use ($target, $snapshot): array {
                    $payload = $lead['payload'];
                    $payload['source_ref_ids'] = array_map(fn (string $id): string => $lead['run_id'].':'.$id, (array) ($payload['source_ref_ids'] ?? []));
                    $refs = [];
                    foreach ($lead['source_refs'] as $ref) {
                        $refs[$lead['run_id'].':'.$ref['source_ref_id']] = [...$ref, 'origin_run_id' => $lead['run_id'], 'evidence_origin' => 'reader'];
                    }
                    return ['id' => $lead['run_id'].':'.$lead['output_id'], 'kind' => 'lead',
                        'project' => $target, 'snapshot' => $snapshot, 'payload' => $payload,
                        'source_refs' => $refs, 'status' => 'queued', 'origin_run_id' => $lead['run_id']];
                }, $aggregateLeads);
                if ($items !== []) {
                    $normalization = $this->normalizeProducts($items, $target, $snapshot, $agentSource, $parentDirectory, $parentRunId, $runner, $storage);
                    File::put($parentDirectory.'/confirmer-handoff.json', json_encode($normalization, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                }
            }
            $parentScorecard = $this->aggregateScorecards($children);
            $parentScorecard['time']['parent_wall_seconds'] = round(microtime(true) - $started, 3);
            $parentScorecard['production']['enrichment_dispatches'] = count(array_filter(
                $enrichmentProposals,
                fn (array $row): bool => ($row['status'] ?? null) === 'queued',
            ));
            $status = $technicalFailureSeen
                ? 'technical_failure'
                : (($allClosed && ! $limitReached) ? 'complete' : 'incomplete');
            $parentReport = [
                'schema_version' => 1, 'publication_state' => 'final',
                'termination_reason' => $status,
                'golden_recon_artifact_id' => $recon->id,
                'source_snapshot' => $snapshot,
                'reader_checkpoint_strategy' => $readerCheckpointStrategy,
                'runtime_sha256' => $runtimeFingerprint, 'models' => $models,
                'selected_initial_area_ids' => array_column($selectedAreas, 'area_id'),
                'defer_enrichments' => $deferEnrichments,
                'reader_provider' => $this->option('reader-provider') ?: null,
                'operational_context_window' => $operationalContextWindow,
                'max_prompt_input_tokens' => $maxPromptInputTokens,
                'pricing' => $children[0]['pricing'] ?? null,
                'scorecard' => $parentScorecard,
                'category_recon' => $recon->payload,
                'mapped_children' => $mappedChildren, 'assignments' => $children,
                'suspected' => $aggregateLeads,
                'enrichment_proposals' => $enrichmentProposals,
                'coverage' => [
                    'complete' => $globalScope && $status === 'complete'
                        && ! collect($enrichmentProposals)->contains(
                            fn (array $proposal): bool => in_array(
                                $proposal['status'], ['deferred', 'pending_review'], true,
                            ),
                        ),
                    'selected_areas_complete' => $status === 'complete',
                    'scope' => $globalScope ? 'global' : 'selected_areas',
                    'area_limit' => self::MAX_AREAS,
                    'area_limit_reached' => $limitReached, 'areas_finished' => count($children),
                ],
                'telemetry' => [
                    'economic_points_used' => $totalPoints,
                    'duration_seconds' => round(microtime(true) - $started, 3),
                ],
            ];
            $postRunDiagnostics = array_values(array_map(function (array $case): array {
                $case['classification'] = $case['matched_lead']
                    ? 'matched_lead'
                    : ($case['wrong_hypothesis_same_anchor']
                        ? 'wrong_hypothesis_same_anchor'
                        : ($case['anchor_read']
                            ? 'anchor_read_without_lead'
                            : ($case['file_reached'] ? 'file_only' : 'unreached')));
                unset(
                    $case['matched_lead'],
                    $case['wrong_hypothesis_same_anchor'],
                    $case['anchor_read'],
                    $case['file_reached'],
                );

                return $case;
            }, $caseDiagnostics));
            $parentBenchmark = [
                'schema' => 'lailaps.reader-global', 'version' => 1,
                'target_id' => $target, 'source_snapshot' => $snapshot,
                'status' => $status, 'concurrency' => $concurrency,
                'assignment_points' => $points,
                'reader_checkpoint_strategy' => $readerCheckpointStrategy,
                'deduper_mode' => $deduperMode,
                'reader_provider' => $this->option('reader-provider') ?: null,
                'operational_context_window' => $operationalContextWindow,
                'max_prompt_input_tokens' => $maxPromptInputTokens,
                'pricing' => $children[0]['pricing'] ?? null,
                'scorecard' => $parentScorecard,
                'runtime_sha256' => $runtimeFingerprint, 'models' => $models,
                'selected_initial_area_ids' => array_column($selectedAreas, 'area_id'),
                'defer_enrichments' => $deferEnrichments,
                'enrichment_proposals' => $enrichmentProposals,
                'reader_tool_calls' => $readerToolCalls,
                'enrichment_counts_by_status' => $enrichmentCounts,
                'post_run_case_diagnostics' => $postRunDiagnostics,
                'manifest_case_ids_reached' => array_values(array_keys($aggregateCases)),
                'results_by_manifest' => array_values(array_map(function (array $result): array {
                    $result['case_ids_reached'] = array_values(array_keys($result['case_ids_reached']));

                    return $result;
                }, $manifestResults)),
                'accepted_leads' => count($aggregateLeads),
                'accepted_leads_per_100k_points' => $totalPoints > 0
                    ? 100_000 * count($aggregateLeads) / $totalPoints : null,
            ];
            $storage->writeOutcome($parentDirectory, $parentRunId, $parentReport, $parentBenchmark);
            if ($this->option('result-json')) {
                File::put((string) $this->option('result-json'), json_encode([
                    'run_id' => $parentRunId, 'status' => $status,
                    'outcome' => $storage->outcomePath($parentDirectory, $parentRunId),
                ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            }
            $registry->record([
                'project_key' => $target, 'category' => 'global', 'source_commit' => $snapshot,
                'parent_artifact_id' => $recon->id, 'run_id' => $parentRunId,
                'role' => 'reader', 'output_type' => 'ReaderGlobalRun',
                'status' => $technicalFailureSeen ? 'technical_failure' : 'valid',
                'accepted' => ! $technicalFailureSeen,
                'payload' => [
                    'status' => $status, 'assignments' => $children,
                    'reader_checkpoint_strategy' => $readerCheckpointStrategy,
                    'deduper_mode' => $deduperMode,
                    'selected_initial_area_ids' => array_column($selectedAreas, 'area_id'),
                    'defer_enrichments' => $deferEnrichments,
                ],
                'usage' => ['economic_points' => $totalPoints], 'metrics' => $parentBenchmark,
            ]);
            $this->info("Outcome globale: {$this->relativePath($storage->outcomePath($parentDirectory, $parentRunId))}");

            return $technicalFailureSeen ? self::FAILURE : self::SUCCESS;
        } finally {
            config()->set('pentest.agent.image', $oldImage);
            foreach ($liveProcesses as $runId => $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
                rescue(fn () => $runner->cleanup((string) $runId), report: false);
            }
            if (is_dir($workDirectory)) {
                File::deleteDirectory($workDirectory);
            }
        }
    }

    private function normalizationQueue(array $normalization): array
    {
        return array_map(fn (array $product): array => ['area' => $product['payload'], 'origin' => 'golden'],
            array_values(array_filter($normalization['canonical'], fn (array $product): bool =>
                $product['kind'] === 'task' && ($product['adjudication_status'] ?? null) === 'completed')));
    }

    private function normalizeProducts(array $items, string $target, string $snapshot, string $source, string $directory, string $runId, BenchmarkStageProcessRunner $runner, RunStorage $storage, bool $defer = false, bool $markDispatched = false): array
    {
        $deterministic = (string) $this->option('deduper-mode') === 'deterministic'
            && ! $this->option('deduper-model') && ! $this->option('roles-config');
        $config = $this->option('roles-config') ? json_decode(File::get((string) $this->option('roles-config')), true, flags: JSON_THROW_ON_ERROR) : ['deduper' => ['model' => (string) $this->option('deduper-model'),
            'provider' => (string) $this->option('deduper-provider'), 'total_points' => (float) $this->option('deduper-points')]];
        File::put($directory.'/dedup-roles.json', json_encode($config, JSON_THROW_ON_ERROR));
        File::put($directory.'/dedup-input.json', json_encode(['project' => $target, 'snapshot' => $snapshot,
            'items' => $items, 'defer' => $defer, 'mark_dispatched' => $markDispatched], JSON_THROW_ON_ERROR));
        $container = $runner->containerized();
        $args = [
            'reader-normalize', '--input', $container ? '/artifacts/dedup-input.json' : $directory.'/dedup-input.json',
            '--mode', $deterministic ? 'deterministic' : 'llm',
            '--output', $container ? '/artifacts' : $directory,
        ];
        if (! $deterministic) {
            array_push($args, '--config', $container ? '/artifacts/dedup-roles.json' : $directory.'/dedup-roles.json');
        }
        if ($this->option('resume-failed') && ! $this->dedupResumed) {
            $args[] = '--resume-failed';
            $this->dedupResumed = true;
        }
        $code = $runner->run($source, $directory, $runId.'-dedup', $args, [], $storage,
            fn ($type, $buffer) => $this->output->write($buffer), offline: $deterministic);
        if (! in_array($code, [0, 2], true)) {
            throw new InvalidArgumentException('Deduper stage failed before dispatch; originals preserved.');
        }
        return json_decode(File::get($directory.'/normalization-result.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param array<int, array<string, mixed>> $leads @param array<int, array<string, mixed>> $enrichments */
    private function assignmentScorecard(array $report, array $leads, array $enrichments, string $status, float $duration): array
    {
        $telemetry = (array) ($report['telemetry'] ?? []);
        $reader = (array) data_get($telemetry, 'role_usage.reader', []);
        $reviewer = (array) data_get($telemetry, 'role_usage.reviewer', []);
        $checkpoints = (array) ($telemetry['reader_self_checkpoint_events'] ?? []);
        $checkpointUsage = [];
        $checkpointOutputs = [];
        $checkpointCostComplete = true;
        $reasons = [];
        foreach ($checkpoints as $event) {
            $event = (array) $event;
            foreach ((array) ($event['usage'] ?? []) as $name => $value) {
                $checkpointUsage[$name] = ($checkpointUsage[$name] ?? 0) + (float) $value;
            }
            if ((float) data_get($event, 'usage.requests', 0) > 0 && ($event['cost_complete'] ?? false) !== true) {
                $checkpointCostComplete = false;
            }
            if (! empty($event['output_id'])) {
                $checkpointOutputs[(string) $event['output_id']] = true;
            }
            $reason = (string) ($event['boundary_reason'] ?? 'unknown');
            $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
        }
        $checkpointLeads = count(array_filter($leads, fn (array $lead): bool => isset($checkpointOutputs[(string) ($lead['output_id'] ?? '')])));
        $tokens = [];
        foreach (['input_tokens', 'cached_tokens', 'uncached_tokens', 'output_tokens'] as $name) {
            $checkpoint = (int) round($checkpointUsage[$name] ?? 0);
            $tokens[$name] = [
                'operational' => max(0, (int) ($reader[$name] ?? 0) - $checkpoint),
                'checkpoint' => $checkpoint,
                'total' => (int) ($reader[$name] ?? 0),
            ];
        }
        $toolRows = [];
        $attempts = $known = $failures = 0;
        foreach ((array) ($telemetry['reader_tool_outcomes'] ?? []) as $name => $row) {
            $row = (array) $row;
            $a = (int) ($row['attempts'] ?? 0);
            $successes = (int) ($row['successes'] ?? 0);
            $validation = (int) ($row['validation_failures'] ?? 0);
            $execution = (int) ($row['execution_failures'] ?? 0);
            $k = $successes + $validation + $execution;
            $f = $validation + $execution;
            $toolRows[$name] = [
                'attempts' => $a, 'known_outcomes' => $k, 'successes' => $successes,
                'validation_failures' => $validation, 'execution_failures' => $execution,
                'pending' => max(0, $a - $k), 'error_rate' => $k ? $f / $k : null,
            ];
            $attempts += $a;
            $known += $k;
            $failures += $f;
        }
        $costComplete = array_key_exists('cost_complete', $reader) && ($reader['cost_complete'] === true);
        $readerUsd = $costComplete ? (float) ($reader['cost_usd'] ?? 0) : null;
        $checkpointUsd = $costComplete && $checkpointCostComplete ? (float) ($checkpointUsage['cost_usd'] ?? 0) : null;
        $estimatedUsd = array_key_exists('estimated_cost_usd', $reader) ? (float) $reader['estimated_cost_usd'] : null;
        $input = (int) ($reader['input_tokens'] ?? 0);
        $cached = (int) ($reader['cached_tokens'] ?? 0);
        $compactions = array_values(array_filter((array) ($telemetry['compaction_events'] ?? []),
            fn (array $event): bool => ($event['role'] ?? null) === 'reader'));

        return [
            'production' => [
                'leads' => count($leads), 'ordinary_leads' => count($leads) - $checkpointLeads,
                'checkpoint_leads' => $checkpointLeads, 'enrichment_proposals' => count($enrichments),
                'reviewer_requests' => (int) ($reviewer['requests'] ?? 0),
                'enrichment_dispatches' => 0,
            ],
            'economy' => [
                'reader_provider_usd' => $readerUsd,
                'reader_cost_complete' => $costComplete,
                'checkpoint_provider_usd' => $checkpointUsd,
                'checkpoint_share' => $readerUsd !== null && $readerUsd > 0 && $checkpointUsd !== null ? $checkpointUsd / $readerUsd : null,
                'reader_estimated_usd' => $estimatedUsd,
                'reader_estimate_complete' => ($reader['estimated_cost_complete'] ?? false) === true,
                'usage_events' => (array) ($telemetry['reader_economic_events'] ?? []),
                'lead_events' => array_map(
                    fn (array $output): array => [
                        'output_id' => $output['output_id'], 'lead_id' => $output['lead_id'],
                        'elapsed_seconds' => $output['accepted_elapsed_seconds'] ?? $output['elapsed_seconds'] ?? null,
                        'phase' => isset($checkpointOutputs[(string) $output['output_id']]) ? 'checkpoint' : 'operational',
                    ],
                    array_values(array_filter((array) ($report['structured_outputs'] ?? []),
                        fn (array $output): bool => ($output['output_type'] ?? null) === 'ReaderLead' && ($output['accepted'] ?? false) === true)),
                ),
                'provider_usd_per_lead' => $readerUsd !== null && count($leads) ? $readerUsd / count($leads) : null,
                'reader_ep' => (float) ($reader['economic_points'] ?? 0),
                'reviewer_ep' => (float) ($reviewer['economic_points'] ?? 0),
                'cost_source_counts' => (array) ($telemetry['cost_source_counts'] ?? []),
            ],
            'tokens' => [
                'by_phase' => $tokens,
                'cache_hit_weighted' => $input ? $cached / $input : null,
            ],
            'tools' => [
                'by_name' => $toolRows, 'attempts' => $attempts, 'known_outcomes' => $known,
                'successes' => $known - $failures, 'failures' => $failures,
                'pending' => max(0, $attempts - $known),
                'error_rate' => $known ? $failures / $known : null,
                'outcome_coverage' => $attempts ? $known / $attempts : null,
            ],
            'reliability' => [
                'status' => $status, 'termination_reason' => $report['termination_reason'] ?? null,
                'provider_errors' => (int) data_get($telemetry, 'upstream_errors_by_role.reader', 0),
                'transport_failures' => count(array_filter((array) ($telemetry['model_request_transport_failures'] ?? []),
                    fn (array $event): bool => ($event['role'] ?? null) === 'reader')),
                'retries' => (int) data_get($telemetry, 'model_turn_retries_attempted.reader', 0),
            ],
            'time' => [
                'assignment_seconds' => $duration,
                'first_lead_seconds' => $telemetry['first_reader_lead_seconds'] ?? null,
                'leads_per_hour' => $duration > 0 ? count($leads) * 3600 / $duration : null,
            ],
            'context' => [
                'window' => (array) ($telemetry['reader_context'] ?? []),
                'checkpoints' => count($checkpoints), 'checkpoint_reasons' => $reasons,
                'compactions' => count($compactions), 'compaction_events' => $compactions,
                'input_samples' => (array) ($telemetry['reader_request_inputs'] ?? []),
                'input_distribution' => (array) ($telemetry['reader_input_distribution'] ?? []),
            ],
            'offline_evaluation' => [
                'status' => 'pending', 'useful_root_causes' => null, 'semantic_cve_matches' => null,
                'plausible_out_of_catalog' => null, 'duplicates_or_unsupported' => null,
                'usd_per_useful_root_cause' => null,
            ],
        ];
    }

    /** @param array<int, array<string, mixed>> $children */
    private function aggregateScorecards(array $children): array
    {
        $cards = array_column($children, 'scorecard');
        $sum = fn (string $path): float => array_sum(array_map(
            fn (array $card): float => (float) data_get($card, $path, 0), $cards,
        ));
        $leads = (int) $sum('production.leads');
        $readerCostsComplete = $cards !== [] && collect($cards)->every(
            fn (array $card): bool => data_get($card, 'economy.reader_cost_complete') === true,
        );
        $readerUsd = $readerCostsComplete ? $sum('economy.reader_provider_usd') : null;
        $estimatedComplete = $cards !== [] && collect($cards)->every(
            fn (array $card): bool => data_get($card, 'economy.reader_estimated_usd') !== null,
        );
        $checkpointUsd = $readerCostsComplete && collect($cards)->every(
            fn (array $card): bool => data_get($card, 'economy.checkpoint_provider_usd') !== null,
        ) ? $sum('economy.checkpoint_provider_usd') : null;
        $toolRows = [];
        $samples = ['operational' => [], 'checkpoint' => []];
        $reasons = [];
        $costSources = [];
        $tokens = [];
        foreach (['input_tokens', 'cached_tokens', 'uncached_tokens', 'output_tokens'] as $name) {
            foreach (['operational', 'checkpoint', 'total'] as $phase) {
                $tokens[$name][$phase] = (int) $sum("tokens.by_phase.{$name}.{$phase}");
            }
        }
        foreach ($cards as $card) {
            foreach ((array) data_get($card, 'tools.by_name', []) as $name => $row) {
                foreach (['attempts', 'known_outcomes', 'successes', 'validation_failures', 'execution_failures', 'pending'] as $metric) {
                    $toolRows[$name][$metric] = ($toolRows[$name][$metric] ?? 0) + (int) ($row[$metric] ?? 0);
                }
            }
            foreach ($samples as $phase => $_) {
                array_push($samples[$phase], ...(array) data_get($card, "context.input_samples.{$phase}", []));
            }
            foreach ((array) data_get($card, 'context.checkpoint_reasons', []) as $reason => $count) {
                $reasons[$reason] = ($reasons[$reason] ?? 0) + (int) $count;
            }
            foreach ((array) data_get($card, 'economy.cost_source_counts', []) as $source => $count) {
                $costSources[$source] = ($costSources[$source] ?? 0) + (int) $count;
            }
        }
        foreach ($toolRows as &$row) {
            $row['error_rate'] = $row['known_outcomes']
                ? ($row['validation_failures'] + $row['execution_failures']) / $row['known_outcomes'] : null;
        }
        unset($row);
        $distribution = [];
        foreach ($samples as $phase => $values) {
            sort($values);
            $distribution[$phase] = [
                'p95' => $values ? $values[max(0, (int) ceil(count($values) * 0.95) - 1)] : null,
                'max' => $values ? end($values) : null,
            ];
        }
        $attempts = (int) $sum('tools.attempts');
        $known = (int) $sum('tools.known_outcomes');
        $failures = (int) $sum('tools.failures');
        $input = $tokens['input_tokens']['total'];

        return [
            'production' => [
                'leads' => $leads,
                'ordinary_leads' => (int) $sum('production.ordinary_leads'),
                'checkpoint_leads' => (int) $sum('production.checkpoint_leads'),
                'enrichment_proposals' => (int) $sum('production.enrichment_proposals'),
                'reviewer_requests' => (int) $sum('production.reviewer_requests'),
                'enrichment_dispatches' => 0,
            ],
            'economy' => [
                'reader_provider_usd' => $readerUsd, 'reader_cost_complete' => $readerCostsComplete,
                'checkpoint_provider_usd' => $checkpointUsd,
                'checkpoint_share' => $readerUsd !== null && $readerUsd > 0 && $checkpointUsd !== null ? $checkpointUsd / $readerUsd : null,
                'reader_estimated_usd' => $estimatedComplete ? $sum('economy.reader_estimated_usd') : null,
                'reader_estimate_complete' => $cards !== [] && collect($cards)->every(
                    fn (array $card): bool => data_get($card, 'economy.reader_estimate_complete') === true,
                ),
                'provider_usd_per_lead' => $readerUsd !== null && $leads ? $readerUsd / $leads : null,
                'reader_ep' => $sum('economy.reader_ep'), 'reviewer_ep' => $sum('economy.reviewer_ep'),
                'cost_source_counts' => $costSources,
            ],
            'tokens' => [
                'by_phase' => $tokens,
                'cache_hit_weighted' => $input ? $tokens['cached_tokens']['total'] / $input : null,
            ],
            'tools' => [
                'by_name' => $toolRows, 'attempts' => $attempts, 'known_outcomes' => $known,
                'successes' => (int) $sum('tools.successes'), 'failures' => $failures,
                'pending' => $attempts - $known,
                'error_rate' => $known ? $failures / $known : null,
                'outcome_coverage' => $attempts ? $known / $attempts : null,
            ],
            'reliability' => [
                'complete' => count(array_filter($children, fn (array $child): bool => $child['status'] === 'complete')),
                'incomplete' => count(array_filter($children, fn (array $child): bool => $child['status'] === 'incomplete')),
                'failed' => count(array_filter($children, fn (array $child): bool => $child['status'] === 'technical_failure')),
                'provider_errors' => (int) $sum('reliability.provider_errors'),
                'transport_failures' => (int) $sum('reliability.transport_failures'),
                'retries' => (int) $sum('reliability.retries'),
            ],
            'time' => [
                'assignment_total_seconds' => $sum('time.assignment_seconds'),
                'first_lead_seconds' => collect($children)->map(
                    fn (array $child): ?float => data_get($child, 'scorecard.time.first_lead_seconds') !== null
                        ? (float) ($child['started_offset_seconds'] ?? 0) + (float) data_get($child, 'scorecard.time.first_lead_seconds')
                        : null,
                )->filter(fn (?float $value): bool => $value !== null)->min(),
                'leads_per_assignment_hour' => $sum('time.assignment_seconds') > 0
                    ? $leads * 3600 / $sum('time.assignment_seconds') : null,
            ],
            'context' => [
                'windows_by_assignment' => array_column($children, 'reader_context', 'area_id'),
                'checkpoints' => (int) $sum('context.checkpoints'),
                'checkpoint_reasons' => $reasons,
                'compactions' => (int) $sum('context.compactions'),
                'input_distribution' => $distribution,
            ],
            'offline_evaluation' => [
                'status' => 'pending', 'useful_root_causes' => null, 'semantic_cve_matches' => null,
                'plausible_out_of_catalog' => null, 'duplicates_or_unsupported' => null,
                'usd_per_useful_root_cause' => null,
            ],
        ];
    }

    /** @param array<int, object> $manifests */
    private function sharedSnapshot(array $manifests, string $target): string
    {
        $values = array_values(array_unique(array_map(
            fn ($manifest): string => (string) (data_get($manifest->data, 'source.commit')
                ?: data_get($manifest->data, 'source.snapshot')),
            $manifests,
        )));
        if (count($values) !== 1 || $values[0] === '') {
            throw new InvalidArgumentException("I manifest di {$target} non condividono lo stesso snapshot.");
        }

        return $values[0];
    }

    private function validateRecon(BenchmarkStageArtifact $recon, string $target, string $snapshot): void
    {
        if ($recon->project_key !== $target || $recon->category !== 'global'
            || $recon->source_commit !== $snapshot || $recon->role !== 'recon'
            || $recon->output_type !== 'CategoryRecon' || $recon->status !== 'valid'
            || ! $recon->accepted || data_get($recon->payload, 'status') !== 'ready'
            || (array) data_get($recon->payload, 'areas', []) === []) {
            throw new InvalidArgumentException('Recon globale incompatibile: richiesto target/snapshot ready con almeno un area.');
        }
    }

    /** @param array<string, mixed> $area @return array<string, mixed> */
    private function projectedFixture(
        BenchmarkStageArtifact $recon, array $area, string $target, string $snapshot,
    ): array {
        $payload = (array) $recon->payload;
        $payload['areas'] = [$area];

        return [
            'schema' => 'lailaps.benchmark-stage-input', 'version' => 1,
            'role' => 'recon', 'mode' => 'global_reader_assignment',
            'artifact_id' => $recon->id, 'parent_artifact_id' => $recon->id,
            'assignment_area_id' => $area['area_id'], 'project_key' => $target,
            'category' => 'global', 'source_commit' => $snapshot, 'output' => $payload,
        ];
    }

    /** @param array<string, mixed> $child */
    private function monitorPartial(array &$child, RunStorage $storage, bool $followed): void
    {
        $outcome = $storage->outcome($child['directory'], $child['run_id']);
        foreach ((array) data_get($outcome, 'report.structured_outputs', []) as $index => $output) {
            if (! is_array($output) || ($output['accepted'] ?? false) !== true) {
                continue;
            }
            $key = (string) ($output['output_id'] ?? $index.':'.($output['output_type'] ?? 'unknown'));
            if (isset($child['seen_outputs'][$key])) {
                continue;
            }
            $child['seen_outputs'][$key] = true;
            if ($followed) {
                continue;
            }
            $payload = (array) ($output['payload'] ?? []);
            if (($output['output_type'] ?? null) === 'ReaderLead') {
                $this->line(sprintf(
                    '[batch %d slot %d %s] lead: %s - %s:%d',
                    $child['batch'], $child['slot'], $child['area']['area_id'],
                    (string) ($payload['title'] ?? 'senza titolo'),
                    (string) ($payload['primary_file'] ?? '?'), (int) ($payload['primary_line'] ?? 0),
                ));
            } elseif (($output['output_type'] ?? null) === 'AreaEnrichmentLead') {
                $this->line(sprintf(
                    '[batch %d slot %d %s] enrichment approvato: %s',
                    $child['batch'], $child['slot'], $child['area']['area_id'],
                    (string) ($payload['title'] ?? 'senza titolo'),
                ));
            }
        }
    }

    private function writeFollowed(int $batch, int $slot, string $area, string $buffer): void
    {
        $color = self::SLOT_COLORS[$slot];
        foreach (preg_split('/(?<=\n)/', $buffer, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $line) {
            $this->output->write("<fg={$color}>[b{$batch} s{$slot} {$area}]</> ".$line);
        }
    }

    /** @param array<string, mixed> $proposal @return array<string, mixed> */
    private function enrichmentArea(array $proposal, string $hash): array
    {
        return [
            'area_id' => 'area-enrichment-'.substr($hash, 0, 16),
            'title' => (string) ($proposal['title'] ?? 'Enrichment'),
            'paths' => array_values(array_unique((array) ($proposal['paths'] ?? []))),
            'qualified_names' => array_values(array_unique((array) ($proposal['qualified_names'] ?? []))),
            'next_check' => (string) ($proposal['next_check'] ?? $proposal['rationale'] ?? 'Esplora la superficie proposta.'),
        ];
    }

    private function normalizePayload(mixed $value): mixed
    {
        if (is_string($value)) {
            return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
        }
        if (! is_array($value)) {
            return $value;
        }
        $normalized = array_map(fn (mixed $item): mixed => $this->normalizePayload($item), $value);
        if (! array_is_list($normalized)) {
            ksort($normalized);
        }

        return $normalized;
    }

    /** @param array<string, mixed> $proposal @return array<string, mixed> */
    private function normalizeEnrichmentProposal(array $proposal): array
    {
        $normalized = (array) $this->normalizePayload($proposal);
        foreach (['paths', 'qualified_names', 'seed_checks', 'source_ref_ids'] as $field) {
            if (! is_array($normalized[$field] ?? null)) {
                continue;
            }
            $normalized[$field] = array_values(array_unique(array_map('strval', $normalized[$field])));
            sort($normalized[$field]);
        }

        return $normalized;
    }

    private function absolutePath(string $path): string
    {
        $candidate = preg_match('#^(?:[A-Za-z]:[\\\\/]|/)#', $path) ? $path : base_path($path);
        $resolved = realpath($candidate);

        return $resolved === false ? '' : str_replace('\\', '/', $resolved);
    }

    private function relativePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
