<?php

namespace App\Console\Commands;

use App\Services\Audit\RunStorage;
use App\Services\Pentest\BenchmarkAuditSource;
use App\Services\Pentest\BenchmarkCatalog;
use App\Services\Pentest\BenchmarkManifest;
use App\Services\Pentest\BenchmarkReconEvaluator;
use App\Services\Pentest\BenchmarkStageArtifactRegistry;
use App\Services\Pentest\BenchmarkStageProcessRunner;
use App\Services\Pentest\BenchmarkTechnicalFailure;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

final class BenchmarkRecon extends Command
{
    protected $signature = 'benchmark:recon
        {target-id : ID del target benchmark}
        {--path= : Path sorgente; default targets/<target-id>}
        {--global : Esegue una sola Recon trasversale su tutti i manifest del target}
        {--category= : Benchmark category o OWASP id; incompatibile con --global}
        {--recon-model= : Modello usato da Recon}
        {--reader-model= : Alias deprecato di --recon-model}
        {--reviewer-model= : Modello Reviewer configurato nel processo}
        {--repetitions= : Ripetizioni; default 3 global, 1 categoriale, massimo 20}
        {--timeout=1200 : Timeout della singola Recon in secondi}
        {--tool-output : Mostra gli output compatti dei tool}';

    protected $description = 'Esegue e valuta soltanto Recon, senza Reader o runtime dinamico';

    public function handle(
        BenchmarkCatalog $catalog,
        BenchmarkAuditSource $auditSource,
        BenchmarkReconEvaluator $evaluator,
        BenchmarkStageArtifactRegistry $registry,
        BenchmarkStageProcessRunner $runner,
        BenchmarkTechnicalFailure $technicalFailure,
        RunStorage $storage,
    ): int {
        $targetId = (string) $this->argument('target-id');
        $global = (bool) $this->option('global');
        if ($global && trim((string) $this->option('category')) !== '') {
            throw new InvalidArgumentException('--global e --category sono incompatibili.');
        }
        if ($this->option('recon-model') && $this->option('reader-model')) {
            throw new InvalidArgumentException('Usa soltanto --recon-model; --reader-model e un alias deprecato.');
        }
        $reconModel = $this->option('recon-model') ?: $this->option('reader-model');
        if ($this->option('reader-model')) {
            $this->warn('--reader-model e deprecato per benchmark:recon; usa --recon-model.');
        }

        $availableManifests = $catalog->forTarget($targetId);
        $manifests = $global
            ? $this->validateGlobalManifests($availableManifests, $targetId)
            : [$this->selectManifest($availableManifests)];
        if ($global) {
            // Fail before materialization/model use when duplicated cases disagree.
            $evaluator->evaluateGlobal([], $manifests);
        }
        $source = $this->option('path')
            ? $this->absolutePath((string) $this->option('path'))
            : base_path('targets/'.$targetId);
        if (! is_dir($source)) {
            throw new InvalidArgumentException("Path target inesistente: {$source}");
        }
        $requestedRepetitions = $this->option('repetitions');
        $repetitions = max(1, min(20, $requestedRepetitions === null
            ? ($global ? 3 : 1)
            : (int) $requestedRepetitions));
        $timeout = (int) $this->option('timeout');
        if ($timeout <= 0) {
            throw new InvalidArgumentException('--timeout deve essere positivo.');
        }

        $failed = false;
        $results = [];
        $stageArtifacts = new Collection;
        $commit = $this->sourceIdentity($manifests[0]);
        $artifactCategory = $global
            ? 'global'
            : ($manifests[0]->benchmarkCategory() ?: strtolower($manifests[0]->categoryId()));

        foreach (range(1, $repetitions) as $repetition) {
            $location = $storage->create($targetId, ['recon-'.$artifactCategory]);
            $runId = $location['run_id'];
            $directory = $location['directory'];
            $workDirectory = storage_path("framework/lailaps-recon/{$runId}");
            $safeToDelete = true;
            try {
                $agentSource = $auditSource->materialize(
                    $source,
                    $workDirectory.'/source',
                    $targetId,
                    $catalog->descriptor($targetId),
                );
                $context = $workDirectory.'/benchmark-context.json';
                File::ensureDirectoryExists($workDirectory);
                File::put($context, json_encode([
                    'schema' => 'lailaps.benchmark',
                    'version' => 1,
                    'target_id' => $targetId,
                    'category' => $global
                        ? ['taxonomy' => 'scope', 'id' => 'global', 'name' => 'Global Recon']
                        : $manifests[0]->data['category'],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

                $started = microtime(true);
                $safeToDelete = false;
                try {
                    $exit = $this->runReconAgent(
                        $runner,
                        $agentSource,
                        $context,
                        $directory,
                        $runId,
                        $manifests[0],
                        $storage,
                        $global,
                        $reconModel ? (string) $reconModel : null,
                        $timeout,
                    );
                    $safeToDelete = true;
                } catch (ProcessTimedOutException $exception) {
                    $safeToDelete = true;
                    $exit = 124;
                    $message = sprintf(
                        "[benchmark:recon] process timeout (%s); recupero outcome parziale.\n",
                        $exception->isGeneralTimeout() ? 'general' : 'idle',
                    );
                    $storage->appendLog($directory, $runId, $message);
                    $this->warn(trim($message));
                }
                $wallDuration = round(max(0.0, microtime(true) - $started), 3);
                $outcome = $storage->outcome($directory, $runId);
                $report = is_array($outcome['report'] ?? null) ? $outcome['report'] : [];
                $result = $global
                    ? $evaluator->evaluateGlobal($report, $manifests)
                    : $evaluator->evaluate($report, $manifests[0]);
                $result['exit_code'] = $exit;
                $result['repetition'] = $repetition;
                $result['timing'] = [
                    'wall_duration_seconds' => $wallDuration,
                    'bootstrap_duration_ms' => data_get($report, 'telemetry.cbm_index_duration_ms'),
                    'recon_duration_seconds' => data_get(
                        $report,
                        'telemetry.role_usage.category_recon.duration_seconds',
                    ),
                ];
                $results[] = $result;
                $technical = $technicalFailure->failed($report, $exit);
                $payload = is_array($report['category_recon'] ?? null)
                    ? $report['category_recon'] : null;
                $values = [
                    'project_key' => $targetId,
                    'category' => $artifactCategory,
                    'source_commit' => $commit,
                    'run_id' => $runId,
                    'repetition' => $repetition,
                    'role' => 'recon',
                    'output_type' => 'CategoryRecon',
                    'model' => data_get($report, 'model.requested_model'),
                    'reasoning_effort' => data_get($report, 'model.reasoning_effort'),
                    'status' => $technical ? 'technical_failure' : 'valid',
                    'accepted' => ! $technical && data_get($report, 'category_recon.status') === 'ready',
                    'payload' => $payload,
                    'usage' => (array) data_get($report, 'telemetry.role_usage.category_recon', []),
                    'metrics' => [
                        ...$result,
                        'economic_points' => (float) data_get(
                            $report,
                            'telemetry.role_usage.category_recon.economic_points',
                            0,
                        ),
                        'recon_profile' => (array) ($report['recon_profile'] ?? []),
                        'timeout_seconds' => $timeout,
                    ],
                    'configuration_signature' => hash('sha256', json_encode([
                        'global' => $global,
                        'model' => data_get($report, 'model.requested_model'),
                        'reasoning_effort' => data_get($report, 'model.reasoning_effort'),
                        'profile' => $report['recon_profile'] ?? null,
                        'timeout_seconds' => $timeout,
                    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
                    'evaluator_version' => BenchmarkReconEvaluator::VERSION,
                    'technical_error' => $technicalFailure->summary($report, $exit),
                ];
                $parent = $payload === null
                    ? $registry->record($values)
                    : $registry->recordReconTree($values)['parent'];
                $stageArtifacts->push($parent);
                $storage->writeOutcome($directory, $runId, $report, $result);

                $this->line(sprintf(
                    '[%d/%d] strict %.3f | guided %.3f | weak %.3f | score %.3f | areas %d | %.1fs',
                    $repetition,
                    $repetitions,
                    $result['strict_recall'],
                    $result['guided_recall'],
                    $result['weak_recall'],
                    $result['normalized_score'],
                    $result['area_count'],
                    $wallDuration,
                ));
                $this->info('Log Recon: '.$storage->logPath($directory, $runId));
                $this->info('Outcome Recon: '.$storage->outcomePath($directory, $runId));
                $failed = $failed || $technical;
            } finally {
                if ($safeToDelete && is_dir($workDirectory)) {
                    File::deleteDirectory($workDirectory);
                }
            }
        }

        $golden = $registry->selectGoldenRecon($stageArtifacts);
        if ($golden !== null) {
            $this->info("Recon fixture predefinita: {$golden->id}");
        }
        if (count($results) > 1) {
            $guided = array_map(fn (array $result): float => (float) $result['guided_recall'], $results);
            $strict = array_map(fn (array $result): float => (float) $result['strict_recall'], $results);
            $this->info(sprintf(
                'Stabilita Recon | strict avg %.3f | guided avg %.3f | guided min/max %.3f/%.3f',
                array_sum($strict) / count($strict),
                array_sum($guided) / count($guided),
                min($guided),
                max($guided),
            ));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @param list<BenchmarkManifest> $manifests */
    private function selectManifest(array $manifests): BenchmarkManifest
    {
        $requested = strtolower(trim((string) $this->option('category')));
        if ($requested !== '') {
            $manifests = array_values(array_filter($manifests, fn (BenchmarkManifest $manifest): bool => in_array($requested, [
                strtolower($manifest->benchmarkCategory() ?? ''),
                strtolower($manifest->categoryId()),
            ], true)));
        }
        if (count($manifests) !== 1) {
            throw new InvalidArgumentException('Seleziona esattamente un manifest con --category.');
        }

        return $manifests[0];
    }

    /** @param list<BenchmarkManifest> $manifests @return list<BenchmarkManifest> */
    private function validateGlobalManifests(array $manifests, string $targetId): array
    {
        if ($manifests === []) {
            throw new InvalidArgumentException('Global Recon richiede almeno un manifest.');
        }
        $identity = $this->sourceIdentity($manifests[0]);
        foreach ($manifests as $manifest) {
            if ($manifest->targetId() !== $targetId || $this->sourceIdentity($manifest) !== $identity) {
                throw new InvalidArgumentException(
                    'Global Recon richiede manifest dello stesso target e snapshot.'
                );
            }
        }

        return $manifests;
    }

    private function sourceIdentity(BenchmarkManifest $manifest): string
    {
        return (string) data_get($manifest->data, 'source.commit')
            ?: (string) data_get($manifest->data, 'source.snapshot');
    }

    private function runReconAgent(
        BenchmarkStageProcessRunner $runner,
        string $source,
        string $context,
        string $directory,
        string $runId,
        BenchmarkManifest $manifest,
        RunStorage $storage,
        bool $global,
        ?string $reconModel,
        int $timeout,
    ): int {
        $containerized = $runner->containerized();
        $args = [
            'recon', '--source-root', $containerized ? '/workspace' : $source,
            '--audit-id', $runId,
            '--benchmark-context', $containerized ? '/benchmark-context.json' : $context,
        ];
        if ($global) {
            $args[] = '--global';
        } else {
            array_push($args, '--category', $manifest->auditCategory());
        }
        if ($reconModel !== null && $reconModel !== '') {
            array_push($args, '--recon-model', $reconModel);
        }
        if ($this->option('reviewer-model')) {
            array_push($args, '--reviewer-model', (string) $this->option('reviewer-model'));
        }
        $args[] = '--tool-output';

        $storage->appendLog($directory, $runId, "[benchmark:recon] source={$source}\n");
        $storage->appendLog(
            $directory,
            $runId,
            '[benchmark:recon] structured outcome: '.$storage->outcomePath($directory, $runId)."\n",
        );

        return $runner->run(
            $source,
            $directory,
            $runId,
            $args,
            [$context => '/benchmark-context.json'],
            $storage,
            function (string $type, string $buffer) use ($storage, $directory, $runId): void {
                $this->output->write($this->consoleTranscript($buffer));
                $storage->appendLog($directory, $runId, $buffer);
            },
            $timeout,
        );
    }

    private function consoleTranscript(string $buffer): string
    {
        if ($this->option('tool-output')) {
            return $buffer;
        }

        return (string) preg_replace(
            '/^\[category_recon:(?:tool|result(?::full)?)\].*(?:\R|$)/m',
            '',
            $buffer,
        );
    }

    private function absolutePath(string $path): string
    {
        $candidate = preg_match('#^(?:[A-Za-z]:[\\/]|/)#', $path) ? $path : base_path($path);
        $resolved = realpath($candidate);

        return $resolved === false ? '' : str_replace('\\', '/', $resolved);
    }
}
