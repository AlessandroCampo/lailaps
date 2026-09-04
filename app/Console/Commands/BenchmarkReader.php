<?php

namespace App\Console\Commands;

use App\Models\BenchmarkStageArtifact;
use App\Services\Audit\RunStorage;
use App\Services\Pentest\BenchmarkAuditSource;
use App\Services\Pentest\BenchmarkCatalog;
use App\Services\Pentest\BenchmarkEvaluator;
use App\Services\Pentest\BenchmarkManifest;
use App\Services\Pentest\BenchmarkStageArtifactRegistry;
use App\Services\Pentest\BenchmarkStageProcessRunner;
use App\Services\Pentest\BenchmarkTechnicalFailure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

final class BenchmarkReader extends Command
{
    protected $signature = 'benchmark:reader
        {target-id : Project key del target benchmark}
        {--path= : Path sorgente; default targets/<target-id>}
        {--category= : Benchmark category o OWASP id}
        {--recon-artifact= : Artifact Recon; default golden del progetto/categoria}
        {--reader-model= : Modello Reader}
        {--reviewer-model= : Reviewer operativo fisso}
        {--budget-category= : Preset economico; default identico alla run configurata}
        {--repetitions=3 : Ripetizioni, da 1 a 20}
        {--tool-output : Mostra output tool compatti}';

    protected $description = 'Valuta Reader da una Recon fixture project-scoped congelata';

    public function handle(
        BenchmarkCatalog $catalog,
        BenchmarkAuditSource $auditSource,
        BenchmarkEvaluator $evaluator,
        BenchmarkStageArtifactRegistry $registry,
        BenchmarkStageProcessRunner $runner,
        BenchmarkTechnicalFailure $technicalFailure,
        RunStorage $storage,
    ): int {
        $projectKey = (string) $this->argument('target-id');
        $manifest = $this->selectManifest($catalog->forTarget($projectKey));
        $source = $this->option('path')
            ? $this->absolutePath((string) $this->option('path'))
            : base_path('targets/'.$projectKey);
        if (! is_dir($source)) {
            throw new InvalidArgumentException("Path target inesistente: {$source}");
        }
        $commit = (string) data_get($manifest->data, 'source.commit', '');
        $artifactCategory = $manifest->benchmarkCategory() ?: strtolower($manifest->categoryId());
        $recon = $this->option('recon-artifact')
            ? BenchmarkStageArtifact::query()->findOrFail((string) $this->option('recon-artifact'))
            : $registry->goldenRecon($projectKey, $artifactCategory, $commit);
        if ($recon->project_key !== $projectKey || $recon->role !== 'recon' || $recon->status !== 'valid') {
            throw new InvalidArgumentException('Artifact Recon non compatibile con il progetto richiesto.');
        }

        $repetitions = max(1, min(20, (int) $this->option('repetitions')));
        $failed = false;
        foreach (range(1, $repetitions) as $repetition) {
            $location = $storage->create($projectKey, ['reader-'.$artifactCategory]);
            $runId = $location['run_id'];
            $directory = $location['directory'];
            $workDirectory = storage_path("framework/lailaps-reader/{$runId}");
            try {
                $agentSource = $auditSource->materialize(
                    $source,
                    $workDirectory.'/source',
                    $projectKey,
                    $catalog->descriptor($projectKey),
                );
                File::ensureDirectoryExists($workDirectory);
                $fixture = $workDirectory.'/recon-fixture.json';
                $context = $workDirectory.'/benchmark-context.json';
                File::put($fixture, json_encode([
                    'schema' => 'lailaps.benchmark-stage-input', 'version' => 1,
                    'role' => 'recon', 'artifact_id' => $recon->id,
                    'project_key' => $projectKey, 'category' => $manifest->auditCategory(),
                    'source_commit' => $commit, 'output' => $recon->payload,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                File::put($context, json_encode([
                    'schema' => 'lailaps.benchmark', 'version' => 1,
                    'target_id' => $projectKey, 'category' => $manifest->data['category'],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                $containerized = $runner->containerized();
                $args = [
                    'reader', '--source-root', $containerized ? '/workspace' : $agentSource,
                    '--audit-id', $runId, '--category', $manifest->auditCategory(),
                    '--recon-fixture', $containerized ? '/recon-fixture.json' : $fixture,
                    '--benchmark-context', $containerized ? '/benchmark-context.json' : $context,
                ];
                if ($this->option('budget-category')) {
                    array_push($args, '--budget-category', (string) $this->option('budget-category'));
                }
                if ($this->option('reader-model')) {
                    array_push($args, '--reader-model', (string) $this->option('reader-model'));
                }
                if ($this->option('reviewer-model')) {
                    array_push($args, '--reviewer-model', (string) $this->option('reviewer-model'));
                }
                if ($this->option('tool-output')) {
                    $args[] = '--tool-output';
                }
                try {
                    $exit = $runner->run(
                        $agentSource,
                        $directory,
                        $runId,
                        $args,
                        [$fixture => '/recon-fixture.json', $context => '/benchmark-context.json'],
                        $storage,
                        function (string $type, string $buffer) use ($storage, $directory, $runId): void {
                            $this->output->write($buffer);
                            $storage->appendLog($directory, $runId, $buffer);
                        },
                    );
                } catch (ProcessTimedOutException $exception) {
                    // The Python ledger publishes atomic partial outcomes while the
                    // stage runs. Preserve every valid boundary already emitted instead
                    // of losing the whole repetition at the outer safety timeout.
                    $exit = 124;
                    $message = sprintf(
                        "[benchmark:reader] process timeout (%s); recupero outcome parziale.\n",
                        $exception->isGeneralTimeout() ? 'general' : 'idle',
                    );
                    $storage->appendLog($directory, $runId, $message);
                    $this->warn(trim($message));
                }
                $outcome = $storage->outcome($directory, $runId);
                $report = is_array($outcome['report'] ?? null) ? $outcome['report'] : [];
                $technical = $technicalFailure->failed($report, $exit);
                $result = $report === []
                    ? $evaluator->missingResult($manifest, ['run_state' => $technical ? 'failed' : 'completed'])
                    : $evaluator->evaluate($directory, $manifest, $source, ['run_state' => $technical ? 'failed' : 'completed']);
                $this->persistOutputs(
                    $registry, $report, $result, $recon, $projectKey, $manifest,
                    $commit, $runId, $repetition, $technical, $technicalFailure->summary($report, $exit),
                );
                $failed = $failed || $technical;
                $this->line(sprintf(
                    '[%d/%d] anchor %.3f | lead %.3f | punti %.0f | %s',
                    $repetition,
                    $repetitions,
                    (float) data_get($result, 'reach.anchor_reached.recall', 0),
                    (float) data_get($result, 'suspected.recall', 0),
                    (float) data_get($result, 'cost.economic_points', 0),
                    $technical ? 'TECHNICAL FAILURE' : 'valid',
                ));
                $storage->writeOutcome($directory, $runId, $report, $result);
            } finally {
                if (is_dir($workDirectory)) {
                    File::deleteDirectory($workDirectory);
                }
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @param list<BenchmarkManifest> $manifests */
    private function selectManifest(array $manifests): BenchmarkManifest
    {
        $requested = strtolower(trim((string) $this->option('category')));
        if ($requested !== '') {
            $manifests = array_values(array_filter($manifests, fn (BenchmarkManifest $manifest): bool => in_array($requested, [
                strtolower($manifest->benchmarkCategory() ?? ''), strtolower($manifest->categoryId()),
            ], true)));
        }
        if (count($manifests) !== 1) {
            throw new InvalidArgumentException('Seleziona esattamente un manifest con --category.');
        }

        return $manifests[0];
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  array<string, mixed>  $result
     */
    private function persistOutputs(
        BenchmarkStageArtifactRegistry $registry,
        array $report,
        array $result,
        BenchmarkStageArtifact $recon,
        string $projectKey,
        BenchmarkManifest $manifest,
        string $commit,
        string $runId,
        int $repetition,
        bool $technical,
        ?string $technicalError,
    ): void {
        $outputs = array_values(array_filter((array) ($report['structured_outputs'] ?? []), 'is_array'));
        $acceptedLeadCount = count(array_filter($outputs, fn (array $output): bool => ($output['role'] ?? null) === 'reader'
            && ($output['output_type'] ?? null) === 'ReaderLead'
            && (bool) ($output['accepted'] ?? false)));
        $economicPoints = (float) data_get($result, 'cost.economic_points', 0);
        $readerRun = $registry->record([
            'project_key' => $projectKey,
            'category' => $manifest->benchmarkCategory() ?: strtolower($manifest->categoryId()),
            'source_commit' => $commit, 'parent_artifact_id' => $recon->id,
            'run_id' => $runId, 'repetition' => $repetition, 'role' => 'reader',
            'output_type' => 'ReaderRun', 'status' => $technical ? 'technical_failure' : 'valid',
            'accepted' => ! $technical,
            'payload' => [
                'termination_reason' => $report['termination_reason'] ?? null,
                'coverage' => $report['coverage'] ?? null,
                'parent_recon_artifact_id' => $recon->id,
            ],
            'usage' => [
                'reader' => (array) data_get($report, 'telemetry.role_usage.reader', []),
                'reviewer' => (array) data_get($report, 'telemetry.role_usage.reviewer', []),
            ],
            'technical_error' => $technicalError,
            'metrics' => [
                'score' => data_get($result, 'score.normalized'),
                'file_recall' => data_get($result, 'reach.file_reached.recall'),
                'anchor_recall' => data_get($result, 'reach.anchor_reached.recall'),
                'suspected_recall' => data_get($result, 'suspected.recall'),
                'suspected_precision' => data_get($result, 'suspected.precision'),
                'accepted_leads' => $acceptedLeadCount,
                'economic_points' => $economicPoints,
                'accepted_leads_per_100k_points' => $economicPoints > 0
                    ? 100_000 * $acceptedLeadCount / $economicPoints : null,
            ],
            'evaluator_version' => BenchmarkEvaluator::VERSION,
        ]);
        if ($outputs === []) {
            return;
        }
        foreach ($outputs as $output) {
            if (($output['role'] ?? null) !== 'reader') {
                continue;
            }
            $leadId = is_string($output['lead_id'] ?? null) ? $output['lead_id'] : null;
            $matched = $leadId === null ? [] : collect((array) ($result['cases'] ?? []))
                ->filter(fn (array $case): bool => collect((array) ($case['matched_findings'] ?? []))
                    ->contains(fn (array $finding): bool => ($finding['finding_id'] ?? null) === $leadId))
                ->pluck('id')->values()->all();
            $isReaderLead = ($output['output_type'] ?? null) === 'ReaderLead';
            $registry->record([
                'project_key' => $projectKey,
                'category' => $manifest->benchmarkCategory() ?: strtolower($manifest->categoryId()),
                'source_commit' => $commit, 'parent_artifact_id' => $readerRun->id,
                'run_id' => $runId, 'repetition' => $repetition, 'role' => 'reader',
                'output_type' => (string) ($output['output_type'] ?? 'unknown'),
                'model' => data_get($report, 'telemetry.role_usage.reader.model'),
                // A technical failure belongs to ReaderRun. A structured output
                // already validated by the agent remains a reusable valid artifact.
                'status' => 'valid',
                'accepted' => (bool) ($output['accepted'] ?? false),
                'payload' => [
                    'output' => (array) ($output['payload'] ?? []),
                    'source_refs' => (array) ($output['source_refs'] ?? []),
                    'lead_id' => $leadId,
                ],
                'usage' => (array) ($output['usage'] ?? []),
                'metrics' => [
                    'matched_case_ids' => $matched,
                    'economic_points' => (float) data_get($output, 'usage.economic_points', 0),
                    'run_score' => data_get($result, 'score.normalized'),
                ],
                'evaluator_version' => BenchmarkEvaluator::VERSION,
                'label' => $isReaderLead && count($matched) === 1 ? 'benchmark_positive' : null,
                'matched_case_id' => $isReaderLead && count($matched) === 1 ? $matched[0] : null,
                'label_set_version' => $isReaderLead && count($matched) === 1 ? 'manifest-auto-v1' : null,
                'technical_error' => null,
            ]);
        }
    }

    private function absolutePath(string $path): string
    {
        $candidate = preg_match('#^(?:[A-Za-z]:[\\/]|/)#', $path) ? $path : base_path($path);
        $resolved = realpath($candidate);

        return $resolved === false ? '' : str_replace('\\', '/', $resolved);
    }
}
