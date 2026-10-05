<?php

namespace App\Console\Commands;

use App\Models\BenchmarkStageArtifact;
use App\Services\Audit\RunStorage;
use App\Services\Pentest\BenchmarkAuditSource;
use App\Services\Pentest\BenchmarkCatalog;
use App\Services\Pentest\BenchmarkManifest;
use App\Services\Pentest\BenchmarkStageArtifactRegistry;
use App\Services\Pentest\BenchmarkStageSubjectSelector;
use App\Services\Pentest\BenchmarkTargetDescriptor;
use App\Services\Pentest\BenchmarkTechnicalFailure;
use App\Services\Pentest\WorkerBenchmarkDataset;
use App\Services\Pentest\WorkerBenchmarkOracle;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Symfony\Component\Process\Process;

final class BenchmarkWorker extends Command
{
    protected $signature = 'benchmark:worker
        {target-id : Project key del target benchmark}
        {--artifact=* : CandidateHandoff artifact; override della selezione automatica}
        {--parent-run-id= : ID artifact o run_id di origine; esegue tutti gli handoff in un batch condiviso}
        {--leads-limit= : Massimo di handoff selezionati, nell ordine esistente; default nessun limite}
        {--path= : Path target; default targets/<target-id>}
        {--category= : Limita alla benchmark category}
        {--worker-model= : Modello Worker}
        {--dataset= : Dataset congelato; se assente usa gli artifact validi dal DB}
        {--envelope-points=3000000 : Cap EP Worker per handoff, inclusi checkpoint e chiusura}
        {--repetitions=3 : Ripetizioni per candidato o intero batch parent, da 1 a 20}
        {--tool-output : Mostra output tool compatti}
        {--test=true : Ricostruisce immagine agente; usa false solo con immagine pubblicata}';

    protected $description = 'Valuta Worker autonomo da CandidateHandoff frozen o validi nel DB';

    public function handle(
        BenchmarkCatalog $catalog,
        BenchmarkAuditSource $auditSource,
        BenchmarkStageArtifactRegistry $registry,
        BenchmarkStageSubjectSelector $subjectSelector,
        BenchmarkTechnicalFailure $technicalFailure,
        WorkerBenchmarkDataset $dataset,
        WorkerBenchmarkOracle $oracle,
        RunStorage $storage,
    ): int {
        $projectKey = (string) $this->argument('target-id');
        $source = $this->option('path') ?: base_path('targets/'.$projectKey);
        $resolved = realpath((string) $source);
        if ($resolved === false || ! is_dir($resolved)) {
            throw new InvalidArgumentException("Path target inesistente: {$source}");
        }
        $source = str_replace('\\', '/', $resolved);
        $descriptor = $catalog->descriptor($projectKey);
        if ($descriptor === null) {
            throw new InvalidArgumentException("Descriptor benchmark assente: {$projectKey}");
        }
        $this->assertPinnedSource($source, $descriptor);
        $envelope = $this->option('envelope-points');
        if (! is_numeric($envelope) || (float) $envelope <= 0) {
            throw new InvalidArgumentException('--envelope-points deve essere positivo.');
        }
        $leadsLimit = $this->option('leads-limit');
        if ($leadsLimit !== null && filter_var($leadsLimit, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new InvalidArgumentException('--leads-limit deve essere un intero positivo.');
        }
        $manifests = $catalog->forTarget($projectKey);
        $artifacts = $this->artifacts($subjectSelector, $projectKey);
        if ($leadsLimit !== null) {
            $available = $artifacts->count();
            $artifacts = $artifacts->take((int) $leadsLimit);
            $this->info("CandidateHandoff selezionati: {$artifacts->count()}/{$available} (--leads-limit={$leadsLimit}).");
        }
        if ($artifacts->isEmpty()) {
            $this->warn('Nessun CandidateHandoff valido per i filtri richiesti.');

            return self::SUCCESS;
        }

        $repetitions = max(1, min(20, (int) $this->option('repetitions')));
        $batchMode = $this->option('parent-run-id') !== null;
        $batches = $batchMode ? [$artifacts] : $artifacts->map(fn ($candidate) => new Collection([$candidate]));
        $failed = false;
        foreach ($batches as $batch) {
            foreach ($batch as $candidate) {
                $manifest = $this->manifestFor($manifests, $candidate);
                $expectedCommit = (string) data_get($manifest->data, 'source.commit', '');
                if ($candidate->source_commit !== $expectedCommit) {
                    throw new InvalidArgumentException("Commit CandidateHandoff incompatibile: {$candidate->id}");
                }
            }
            foreach (range(1, $repetitions) as $repetition) {
                $location = $storage->create($projectKey, [$batchMode ? 'worker-batch' : 'worker-'.$batch->first()->category]);
                $runId = $location['run_id'];
                $directory = $location['directory'];
                $workDirectory = storage_path("framework/lailaps-worker/{$runId}");
                File::ensureDirectoryExists($workDirectory);
                $agentSource = $auditSource->materialize(
                    $source,
                    $workDirectory.'/source',
                    $projectKey,
                    $catalog->descriptor($projectKey),
                );
                $subjectPath = $workDirectory.'/candidate-subject.json';
                $probePath = $workDirectory.'/fixture-probes.json';
                $subjects = $batch->map(fn ($candidate) => $dataset->modelSubject($candidate))->all();
                $subject = $batchMode
                    ? ['schema' => 'lailaps.worker-batch-input', 'version' => 1, 'candidates' => $subjects]
                    : $subjects[0];
                File::put($subjectPath, json_encode($subject, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                $fixtureProbes = $batch->flatMap(fn ($candidate) => array_filter((array) data_get($candidate->metrics, 'oracle.fixture_probes', []), 'is_array'))
                    ->unique(fn ($probe) => json_encode($probe, JSON_THROW_ON_ERROR))->values()->all();
                File::put($probePath, json_encode($fixtureProbes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

                $oldDirectory = getenv('LAILAPS_RUN_DIRECTORY');
                $oldRunId = getenv('LAILAPS_RUN_ID');
                $oldOutcome = getenv('LAILAPS_OUTCOME_FILE');
                $oldHarnessEnvironment = [];
                putenv('LAILAPS_RUN_DIRECTORY='.$directory);
                putenv('LAILAPS_RUN_ID='.$runId);
                putenv('LAILAPS_OUTCOME_FILE='.$storage->outcomePath($directory, $runId));
                foreach ($descriptor->harnessEnvironment() as $name => $value) {
                    $oldHarnessEnvironment[$name] = getenv($name);
                    putenv($name.'='.$value);
                }
                try {
                    $exit = Artisan::call('pentest:run', [
                        '--path' => $agentSource,
                        '--audit-id' => $runId,
                        '--project-name' => $projectKey,
                        '--category' => [$this->manifestFor($manifests, $batch->first())->auditCategory()],
                        '--candidate-subject' => $subjectPath,
                        '--fixture-probes' => $probePath,
                        '--envelope-points' => $envelope,
                        '--worker-model' => $this->option('worker-model'),
                        '--tool-output' => (bool) $this->option('tool-output'),
                        // A parent repetition shares one runtime and sandbox across its candidates.
                        '--keep' => false,
                        '--ttl' => max(9000, (int) config('pentest.agent.timeout') * $batch->count() + 900),
                        '--test' => $this->enabledBooleanOption('test'),
                        '--assume-authorized' => true,
                    ], $this->output);
                    $outcome = $storage->outcome($directory, $runId);
                    $report = is_array($outcome['report'] ?? null) ? $outcome['report'] : [];
                    $episodeReports = $batchMode ? (array) data_get($report, 'worker_batch.episodes', []) : [$report];
                    $summaries = [];
                    foreach ($batch as $candidate) {
                        $episode = $batchMode
                            ? collect($episodeReports)->first(fn ($row) => data_get($row, 'worker_benchmark.artifact_id') === $candidate->id)
                            : $report;
                        if (! is_array($episode)) {
                            $this->warn("{$candidate->id}: non concluso nel batch {$runId}.");
                            continue;
                        }
                        if (isset($report['environment'])) {
                            $episode['environment'] = $report['environment'];
                        }
                        $episodeExit = $batchMode ? ((bool) ($episode['command_failed'] ?? false) ? 1 : 0) : $exit;
                        $technical = $technicalFailure->failed($episode, $episodeExit);
                        $comparison = $this->comparison($candidate, $this->manifestFor($manifests, $candidate), $descriptor, (float) $envelope, $agentSource, $fixtureProbes);
                        if ($batchMode) {
                            $comparison['harness']['reset'] = 'fresh-sandbox-per-batch-repetition';
                            $comparison['batch_candidates'] = $batch->map(fn ($item) => ['artifact_id' => $item->id, 'content_hash' => $item->content_hash])->all();
                            $comparison['batch_recovery_policy'] = 'continue-after-local-failure-v1';
                        }
                        $signature = hash('sha256', json_encode($comparison, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                        $summary = $this->persist(
                            $registry, $oracle, $candidate, $episode, $runId, $repetition,
                            $technical, $technicalFailure->summary($episode, $episodeExit), $signature, $comparison,
                        );
                        $summaries[] = ['artifact_id' => $candidate->id, ...$summary];
                        $failed = $failed || $technical;
                        $this->line(sprintf(
                            '%s [%d/%d] decision=%s output=%s evidence=%s http=%d browser=%d | %s',
                            $candidate->id, $repetition, $repetitions,
                            $summary['proposal'] ?? 'missing',
                            $summary['worker_decision'] ?? 'missing',
                            $summary['evidence_sufficiency'] ?? 'absent',
                            (int) ($summary['http_requests'] ?? 0),
                            (int) ($summary['browser_actions'] ?? 0),
                            $technical ? 'TECHNICAL FAILURE' : 'valid',
                        ));
                    }
                    $failed = $failed || $technicalFailure->failed($report, $exit) || count($summaries) !== $batch->count();
                    $storage->writeOutcome($directory, $runId, $report, $batchMode
                        ? ['mode' => 'worker_checkpoint_batch', 'parent_run_id' => $this->option('parent-run-id'),
                            'candidates' => $batch->count(), 'completed' => count($summaries),
                            'attempted' => (int) data_get($report, 'worker_batch.attempted_count', count($summaries)),
                            'terminal_decisions' => count(array_filter($summaries, fn ($row) => $row['decision_applied'] && ! $row['technical_failure'])),
                            'technical_failures' => count(array_filter($summaries, fn ($row) => $row['technical_failure'])),
                            'not_run' => (int) data_get($report, 'worker_batch.not_run_count', $batch->count() - count($summaries)),
                            'completed_all' => (bool) data_get($report, 'worker_batch.completed', count($summaries) === $batch->count()),
                            'termination_reason' => $report['termination_reason'] ?? null,
                            'episodes' => $summaries]
                        : $summaries[0]);
                } finally {
                    File::deleteDirectory($workDirectory);
                    $this->restoreEnv('LAILAPS_RUN_DIRECTORY', $oldDirectory);
                    $this->restoreEnv('LAILAPS_RUN_ID', $oldRunId);
                    $this->restoreEnv('LAILAPS_OUTCOME_FILE', $oldOutcome);
                    foreach ($oldHarnessEnvironment as $name => $value) {
                        $this->restoreEnv($name, $value);
                    }
                }
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @return Collection<int, BenchmarkStageArtifact> */
    private function artifacts(BenchmarkStageSubjectSelector $selector, string $projectKey): Collection
    {
        $ids = array_values(array_filter((array) $this->option('artifact')));

        return $selector->select(
            projectKey: $projectKey,
            role: 'confirmer',
            outputType: 'CandidateHandoff',
            category: $this->option('category') ? (string) $this->option('category') : null,
            artifactIds: $ids,
            dataset: $this->option('dataset') ? (string) $this->option('dataset') : null,
            parentRunId: $this->option('parent-run-id') !== null ? (string) $this->option('parent-run-id') : null,
        );
    }

    /** @param list<BenchmarkManifest> $manifests */
    private function manifestFor(array $manifests, BenchmarkStageArtifact $artifact): BenchmarkManifest
    {
        $matches = array_values(array_filter($manifests, fn (BenchmarkManifest $manifest): bool => $manifest->benchmarkCategory() === $artifact->category
            || strtolower($manifest->categoryId()) === strtolower($artifact->category)));
        if (count($matches) !== 1) {
            throw new InvalidArgumentException("Manifest non univoco per {$artifact->category}.");
        }

        return $matches[0];
    }

    /** @param array<int, array<string, mixed>> $fixtureProbes @return array<string, mixed> */
    private function comparison(BenchmarkStageArtifact $candidate, BenchmarkManifest $manifest, BenchmarkTargetDescriptor $descriptor, float $budget, string $source, array $fixtureProbes): array
    {
        $profile = $source.'/lailaps.audit.yaml';

        return [
            'schema' => 'lailaps.worker-comparison', 'version' => 2,
            'label_set_version' => $candidate->label_set_version,
            'candidate_content_hash' => $candidate->content_hash,
            'source_commit' => $candidate->source_commit,
            'target_snapshot' => [
                'benchmark_id' => (string) data_get($manifest->data, 'benchmark_id', ''),
                'repository' => $descriptor->repository(),
                'commit' => $descriptor->commit(),
                'tree' => $descriptor->tree(),
            ],
            'harness' => [
                'profile_sha256' => is_file($profile) ? hash_file('sha256', $profile) : null,
                'dockerfile_sha256' => $this->fileHash($source.'/Dockerfile'),
                'compose_sha256' => $this->fileHash($source.'/compose.yml'),
                'fixture_probes_sha256' => hash('sha256', json_encode($fixtureProbes, JSON_THROW_ON_ERROR)),
                'reset' => 'fresh-sandbox-and-volumes-per-repetition',
            ],
            'evaluator_version' => WorkerBenchmarkOracle::VERSION,
            'budget_points' => $budget,
            'continuity_policy' => 'worker-self-checkpoint-v1',
            'stop_policy' => 'worker-checkpoint-terminal-v1',
            'toolset' => 'normal-worker-toolset-v1',
            'evidence_reference_policy' => 'http-transaction-or-observation-v1',
            'source_access' => true,
            'ingress_policy' => 'validated-candidate-handoff-v1',
        ];
    }

    /** @return array{model: string, reasoning_effort: string} */
    private function persist(BenchmarkStageArtifactRegistry $registry, WorkerBenchmarkOracle $oracleEvaluator, BenchmarkStageArtifact $candidate, array $report, string $runId, int $repetition, bool $technical, ?string $technicalError, string $signature, array $comparison): array
    {
        $workerOutput = collect((array) ($report['structured_outputs'] ?? []))->last(
            fn ($row): bool => is_array($row) && ($row['role'] ?? null) === 'worker'
                && in_array($row['output_type'] ?? null, ['ConfirmedDecision', 'RejectedDecision', 'BlockedDecision', 'SuspectedDecision'], true)
        );
        $decisionType = is_array($workerOutput) ? (string) ($workerOutput['output_type'] ?? '') : null;
        $decision = is_array($workerOutput) ? (array) ($workerOutput['payload'] ?? []) : null;
        $evidence = (array) ($report['worker_benchmark'] ?? []);
        $technicalError = $technical ? ($evidence['stop_reason'] ?? $technicalError) : null;
        $applied = is_array($workerOutput) && ($workerOutput['accepted'] ?? ($evidence['decision_applied'] ?? false)) === true;
        $termination = (string) ($report['termination_reason'] ?? 'missing_report');
        $evaluation = $oracleEvaluator->evaluate((array) data_get($candidate->metrics, 'oracle', []), $decisionType, $decision, $evidence, $termination, $technical);
        $evaluation['stop_cause'] = $evidence['stop_cause'] ?? null;
        $evaluation['stop_phase'] = $evidence['stop_phase'] ?? null;
        $evaluation['output_validation'] = (array) data_get($report, 'telemetry.output_validation_by_role.worker', []);
        if ($decision === null && ($evidence['lifecycle_status'] ?? null) === 'worker_stopped') {
            // Harness stops preserve suspected without fabricating a model verdict.
            $evaluation['abstained'] = true;
        }
        $usage = (array) data_get($report, 'telemetry.role_usage.worker', []);
        $registry->record([
            'project_key' => $candidate->project_key, 'category' => $candidate->category,
            'source_commit' => $candidate->source_commit, 'parent_artifact_id' => $candidate->id,
            'run_id' => $runId, 'repetition' => $repetition, 'role' => 'worker',
            'output_type' => $decisionType ?: 'WorkerCheckpointRun', 'model' => data_get($report, 'telemetry.role_usage.worker.model'),
            'status' => $technical ? 'technical_failure' : 'valid', 'accepted' => $applied && ! $technical,
            'configuration_signature' => $signature,
            'payload' => ['output' => $decision, 'evidence' => $evidence, 'termination_reason' => $termination],
            'usage' => $usage,
            'metrics' => [...$evaluation, 'worker_decision_type' => $decisionType, 'decision_applied' => $applied, 'comparison_signature' => $signature, 'comparison' => $comparison, 'economic_points' => (float) data_get($report, 'telemetry.economic_points_used', 0), 'tool_calls' => (int) ($usage['tool_calls'] ?? 0), 'duration_ms' => (float) ($usage['duration_ms'] ?? 0), 'postflight_ready' => data_get($report, 'environment.postflight_ready')],
            'evaluator_version' => WorkerBenchmarkOracle::VERSION,
            'label' => $evaluation['proposal_correct'] ? $candidate->label : 'unresolved',
            'matched_case_id' => $candidate->matched_case_id, 'label_set_version' => $candidate->label_set_version,
            'technical_error' => $technicalError,
        ]);

        return ['mode' => 'worker_checkpoint', 'dataset' => $candidate->label_set_version, 'comparison_signature' => $signature, 'proposal' => $evaluation['proposal_decision'], 'proposal_correct' => $evaluation['proposal_correct'], 'unclassified' => $evaluation['unclassified'], 'worker_decision' => $decisionType, 'decision_applied' => $applied, 'evidence_sufficiency' => $evaluation['evidence_sufficiency'], 'http_requests' => $evaluation['http_requests'], 'browser_actions' => $evaluation['browser_actions'], 'technical_failure' => $technical, 'termination_reason' => $termination, 'stop_cause' => $evidence['stop_cause'] ?? null, 'stop_phase' => $evidence['stop_phase'] ?? null];
    }

    private function restoreEnv(string $key, string|false $value): void
    {
        putenv($value === false ? $key : $key.'='.$value);
    }

    private function assertPinnedSource(string $source, BenchmarkTargetDescriptor $descriptor): void
    {
        $process = new Process(['git', '-C', $source, 'rev-parse', 'HEAD']);
        $process->run();
        $actual = strtolower(trim($process->getOutput()));
        if (! $process->isSuccessful() || ! preg_match('/^[0-9a-f]{40}$/', $actual)) {
            throw new InvalidArgumentException('Il benchmark Worker richiede una source Git al commit fissato dal descriptor.');
        }
        if ($actual !== $descriptor->commit()) {
            throw new InvalidArgumentException("Snapshot target incompatibile: atteso {$descriptor->commit()}, ottenuto {$actual}.");
        }
    }

    private function fileHash(string $path): ?string
    {
        return is_file($path) ? hash_file('sha256', $path) : null;
    }

    private function enabledBooleanOption(string $name): bool
    {
        $value = $this->option($name);
        if ($value === null) {
            return true;
        }
        $enabled = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($enabled === null) {
            throw new InvalidArgumentException("--{$name} accetta solo true o false.");
        }

        return $enabled;
    }
}
