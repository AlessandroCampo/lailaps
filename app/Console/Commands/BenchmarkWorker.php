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
        {--path= : Path target; default targets/<target-id>}
        {--category= : Limita alla benchmark category}
        {--worker-model= : Modello Worker}
        {--dataset= : Dataset congelato; se assente usa gli artifact validi dal DB}
        {--envelope-points=300000 : Hard cap Worker per episodio}
        {--repetitions=3 : Ripetizioni per candidate, da 1 a 20}
        {--tool-output : Mostra output tool compatti}
        {--test=true : Ricostruisce immagine agente; usa false solo con immagine pubblicata}';

    protected $description = 'Valuta il solo Worker da CandidateHandoff frozen o validi nel DB, senza Judge';

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
        $manifests = $catalog->forTarget($projectKey);
        $artifacts = $this->artifacts($subjectSelector, $projectKey);
        if ($artifacts->isEmpty()) {
            $this->warn('Nessun CandidateHandoff valido per i filtri richiesti.');

            return self::SUCCESS;
        }

        $repetitions = max(1, min(20, (int) $this->option('repetitions')));
        $failed = false;
        foreach ($artifacts as $candidate) {
            $manifest = $this->manifestFor($manifests, $candidate);
            $expectedCommit = (string) data_get($manifest->data, 'source.commit', '');
            if ($candidate->source_commit !== $expectedCommit) {
                throw new InvalidArgumentException("Commit CandidateHandoff incompatibile: {$candidate->id}");
            }
            foreach (range(1, $repetitions) as $repetition) {
                $location = $storage->create($projectKey, ['worker-'.$candidate->category]);
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
                File::put($subjectPath, json_encode($dataset->modelSubject($candidate), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                $fixtureProbes = array_values(array_filter((array) data_get($candidate->metrics, 'oracle.fixture_probes', []), 'is_array'));
                File::put($probePath, json_encode($fixtureProbes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                $comparison = $this->comparison($candidate, $manifest, $descriptor, (float) $envelope, $agentSource, $fixtureProbes);
                $signature = hash('sha256', json_encode($comparison, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

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
                        '--category' => [$manifest->auditCategory()],
                        '--candidate-subject' => $subjectPath,
                        '--fixture-probes' => $probePath,
                        '--envelope-points' => $envelope,
                        '--worker-model' => $this->option('worker-model'),
                        '--tool-output' => (bool) $this->option('tool-output'),
                        // Every repetition owns a fresh sandbox and its fresh volumes.
                        '--keep' => false,
                        '--test' => $this->enabledBooleanOption('test'),
                        '--assume-authorized' => true,
                    ], $this->output);
                    $outcome = $storage->outcome($directory, $runId);
                    $report = is_array($outcome['report'] ?? null) ? $outcome['report'] : [];
                    $technical = $technicalFailure->failed($report, $exit);
                    $summary = $this->persist(
                        $registry, $oracle, $candidate, $report, $runId, $repetition,
                        $technical, $technicalFailure->summary($report, $exit), $signature, $comparison,
                    );
                    $storage->writeOutcome($directory, $runId, $report, $summary);
                    $failed = $failed || $technical;
                    $this->line(sprintf(
                        '%s [%d/%d] proposal=%s evidence=%s requests=%d | %s',
                        $candidate->id, $repetition, $repetitions,
                        $summary['proposal'] ?? 'missing', $summary['evidence_sufficiency'] ?? 'absent',
                        (int) ($summary['http_requests'] ?? 0), $technical ? 'TECHNICAL FAILURE' : 'valid',
                    ));
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
            'schema' => 'lailaps.worker-comparison', 'version' => 1,
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
            'continuity_policy' => 'continue-until-terminal-or-budget-v1',
            'stop_policy' => 'first-valid-worker-terminal-v1',
            'toolset' => 'normal-worker-toolset-v1',
            'source_access' => filter_var(data_get(config('pentest.agent.env'), 'WORKER_SOURCE_ACCESS_ENABLED', true), FILTER_VALIDATE_BOOL),
        ];
    }

    /** @param array<string, mixed> $report @param array<string, mixed> $comparison @return array<string, mixed> */
    private function persist(BenchmarkStageArtifactRegistry $registry, WorkerBenchmarkOracle $oracleEvaluator, BenchmarkStageArtifact $candidate, array $report, string $runId, int $repetition, bool $technical, ?string $technicalError, string $signature, array $comparison): array
    {
        $output = collect((array) ($report['structured_outputs'] ?? []))->first(
            fn ($row): bool => is_array($row) && ($row['role'] ?? null) === 'worker'
                && in_array($row['output_type'] ?? null, ['ConfirmedDecision', 'RejectedDecision', 'BlockedDecision', 'NeedsInfoDecision'], true)
        );
        $proposalType = is_array($output) ? (string) ($output['output_type'] ?? '') : null;
        $proposal = is_array($output) ? (array) ($output['payload'] ?? []) : null;
        $evidence = (array) ($report['worker_benchmark'] ?? []);
        $termination = (string) ($report['termination_reason'] ?? 'missing_report');
        $evaluation = $oracleEvaluator->evaluate((array) data_get($candidate->metrics, 'oracle', []), $proposalType, $proposal, $evidence, $termination, $technical);
        $usage = (array) data_get($report, 'telemetry.role_usage.worker', []);
        $registry->record([
            'project_key' => $candidate->project_key, 'category' => $candidate->category,
            'source_commit' => $candidate->source_commit, 'parent_artifact_id' => $candidate->id,
            'run_id' => $runId, 'repetition' => $repetition, 'role' => 'worker',
            'output_type' => $proposalType ?: 'WorkerRun', 'model' => data_get($report, 'telemetry.role_usage.worker.model'),
            'status' => $technical ? 'technical_failure' : 'valid', 'accepted' => $proposal !== null && ! $technical,
            'configuration_signature' => $signature,
            'payload' => ['output' => $proposal, 'evidence' => $evidence, 'termination_reason' => $termination],
            'usage' => $usage,
            'metrics' => [...$evaluation, 'comparison_signature' => $signature, 'comparison' => $comparison, 'economic_points' => (float) ($usage['economic_points'] ?? 0), 'tool_calls' => (int) ($usage['tool_calls'] ?? 0), 'duration_ms' => (float) ($usage['duration_ms'] ?? 0), 'postflight_ready' => data_get($report, 'environment.postflight_ready')],
            'evaluator_version' => WorkerBenchmarkOracle::VERSION,
            'label' => $evaluation['proposal_correct'] ? $candidate->label : 'unresolved',
            'matched_case_id' => $candidate->matched_case_id, 'label_set_version' => $candidate->label_set_version,
            'technical_error' => $technicalError,
        ]);

        return ['mode' => 'worker_only', 'dataset' => $candidate->label_set_version, 'comparison_signature' => $signature, 'proposal' => $evaluation['proposal_decision'], 'proposal_correct' => $evaluation['proposal_correct'], 'evidence_sufficiency' => $evaluation['evidence_sufficiency'], 'http_requests' => $evaluation['http_requests'], 'technical_failure' => $technical, 'termination_reason' => $termination];
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
