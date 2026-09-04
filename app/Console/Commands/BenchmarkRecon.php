<?php

namespace App\Console\Commands;

use App\Services\Audit\RunStorage;
use App\Services\Pentest\BenchmarkAuditSource;
use App\Services\Pentest\BenchmarkCatalog;
use App\Services\Pentest\BenchmarkManifest;
use App\Services\Pentest\BenchmarkReconEvaluator;
use App\Services\Pentest\BenchmarkStageArtifactRegistry;
use App\Services\Pentest\BenchmarkTechnicalFailure;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Symfony\Component\Process\Process;

final class BenchmarkRecon extends Command
{
    protected $signature = 'benchmark:recon
        {target-id : ID del target benchmark}
        {--path= : Path sorgente; default targets/<target-id>}
        {--category= : Benchmark category o OWASP id}
        {--reader-model= : Modello usato da CategoryRecon}
        {--reviewer-model= : Modello Reviewer configurato nel processo}
        {--repetitions=1 : Numero di ripetizioni, da 1 a 20}
        {--tool-output : Mostra gli output compatti dei tool}';

    protected $description = 'Esegue e valuta soltanto CategoryRecon, senza runtime o health check';

    public function handle(
        BenchmarkCatalog $catalog,
        BenchmarkAuditSource $auditSource,
        BenchmarkReconEvaluator $evaluator,
        BenchmarkStageArtifactRegistry $registry,
        BenchmarkTechnicalFailure $technicalFailure,
        RunStorage $storage,
    ): int {
        $targetId = (string) $this->argument('target-id');
        $manifest = $this->selectManifest($catalog->forTarget($targetId));
        $source = $this->option('path')
            ? $this->absolutePath((string) $this->option('path'))
            : base_path('targets/'.$targetId);
        if (! is_dir($source)) {
            throw new InvalidArgumentException("Path target inesistente: {$source}");
        }
        $repetitions = max(1, min(20, (int) $this->option('repetitions')));
        $failed = false;
        $results = [];
        $stageArtifacts = new Collection;
        $commit = (string) data_get($manifest->data, 'source.commit', '');
        $artifactCategory = $manifest->benchmarkCategory() ?: strtolower($manifest->categoryId());
        foreach (range(1, $repetitions) as $repetition) {
            $location = $storage->create($targetId, ['recon-'.$artifactCategory]);
            $runId = $location['run_id'];
            $directory = $location['directory'];
            $workDirectory = storage_path("framework/lailaps-recon/{$runId}");
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
                    'category' => $manifest->data['category'],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                $exit = $this->runReconAgent($agentSource, $context, $directory, $runId, $manifest, $storage);
                $outcome = $storage->outcome($directory, $runId);
                $report = is_array($outcome['report'] ?? null) ? $outcome['report'] : [];
                $result = $evaluator->evaluate($report, $manifest);
                $result['exit_code'] = $exit;
                $result['repetition'] = $repetition;
                $results[] = $result;
                $technical = $technicalFailure->failed($report, $exit);
                $stageArtifacts->push($registry->record([
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
                    'accepted' => data_get($report, 'category_recon.status') === 'ready',
                    'payload' => is_array($report['category_recon'] ?? null)
                        ? $report['category_recon'] : null,
                    'usage' => (array) data_get($report, 'telemetry.role_usage.category_recon', []),
                    'metrics' => [
                        ...$result,
                        'economic_points' => (float) data_get($report, 'telemetry.role_usage.category_recon.economic_points', 0),
                    ],
                    'evaluator_version' => BenchmarkReconEvaluator::VERSION,
                    'technical_error' => $technicalFailure->summary($report, $exit),
                ]));
                $storage->writeOutcome($directory, $runId, $report, $result);
                $this->line(sprintf(
                    '[%d/%d] strict %.3f | guided %.3f | weak %.3f | score %.3f | areas %d',
                    $repetition,
                    $repetitions,
                    $result['strict_recall'],
                    $result['guided_recall'],
                    $result['weak_recall'],
                    $result['normalized_score'],
                    $result['area_count'],
                ));
                $this->info('Log Recon: '.$storage->logPath($directory, $runId));
                $this->info('Outcome Recon: '.$storage->outcomePath($directory, $runId));
                $failed = $failed || $technical;
            } finally {
                if (is_dir($workDirectory)) {
                    File::deleteDirectory($workDirectory);
                }
            }
        }
        $golden = $registry->selectGoldenRecon($stageArtifacts);
        if ($golden !== null) {
            $this->info("Golden Recon project-scoped: {$golden->id}");
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

    private function runReconAgent(string $source, string $context, string $directory, string $runId, BenchmarkManifest $manifest, RunStorage $storage): int
    {
        $config = config('pentest.agent');
        $containerized = $config['runtime'] === 'container';
        $args = [
            'recon', '--source-root', $containerized ? '/workspace' : $source,
            '--audit-id', $runId,
            '--category', $manifest->auditCategory(),
            '--benchmark-context', $containerized ? '/benchmark-context.json' : $context,
        ];
        if ($this->option('reader-model')) {
            array_push($args, '--reader-model', (string) $this->option('reader-model'));
        }
        if ($this->option('reviewer-model')) {
            array_push($args, '--reviewer-model', (string) $this->option('reviewer-model'));
        }
        if ($this->option('tool-output')) {
            $args[] = '--tool-output';
        }
        // Recon benchmark logs must include tool results even without an explicit
        // CLI flag; the run is intended to be fully inspectable post-mortem.
        if (! in_array('--tool-output', $args, true)) {
            $args[] = '--tool-output';
        }
        $configuredCommand = array_values(array_filter(
            (array) $config['command'],
            static fn (mixed $part): bool => is_string($part),
        ));
        $command = $containerized
            ? $this->containerCommand($config, $source, $context, $directory, $runId, $args)
            : $this->localCommand($configuredCommand, $args);
        $environment = (array) $config['env'];
        $environment['ARTIFACT_DIR'] = $directory;
        $environment['LAILAPS_RUN_ID'] = $runId;
        $environment['LAILAPS_OUTCOME_FILE'] = $storage->outcomePath($directory, $runId);
        $environment['LAILAPS_EVENT_STREAM'] = '0';
        $environment['LAILAPS_BENCHMARK_FULL_LOG'] = '1';
        $process = new Process($command, $containerized ? base_path() : $config['path'], $environment, null, (float) $config['timeout']);
        $storage->appendLog($directory, $runId, "[benchmark:recon] source={$source}\n");
        $storage->appendLog(
            $directory,
            $runId,
            '[benchmark:recon] structured outcome: '.$storage->outcomePath($directory, $runId)."\n"
        );

        return $process->run(function (string $type, string $buffer) use ($storage, $directory, $runId): void {
            // Keep the complete transcript on disk, but keep the default benchmark
            // console readable by hiding verbose tool call/result rows.
            $this->output->write($this->consoleTranscript($buffer));
            $storage->appendLog($directory, $runId, $buffer);
        });
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

    /**
     * @param  list<string>  $configured
     * @param  list<string>  $args
     * @return list<string>
     */
    private function localCommand(array $configured, array $args): array
    {
        if (($configured[array_key_last($configured)] ?? null) === 'scan') {
            array_pop($configured);
        }

        return [...$configured, ...$args];
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<string>  $args
     * @return list<string>
     */
    private function containerCommand(array $config, string $source, string $context, string $directory, string $runId, array $args): array
    {
        $cache = storage_path("framework/codebase-memory/{$runId}");
        File::ensureDirectoryExists($cache);
        $semgrepCache = storage_path('framework/semgrep-cache');
        File::ensureDirectoryExists($semgrepCache);
        $modelsFile = realpath((string) ($config['models_file'] ?? base_path('agent/pentest-agent/Models.json')));
        if ($modelsFile === false || ! is_file($modelsFile)) {
            throw new InvalidArgumentException('File Models.json dell agente inesistente.');
        }
        $command = [
            'docker', 'run', '--rm', '--name', "lailaps-recon-{$runId}",
            '--read-only', '--tmpfs', '/tmp:rw,noexec,nosuid,size=128m',
            '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges:true',
            // CBM can use up to 768 MiB in its one indexing worker; leave room for
            // the launcher and Python process instead of letting Docker OOM-kill it.
            '--memory', '2g', '--pids-limit', '256',
            '--volume', "{$source}:/workspace:ro",
            '--volume', "{$directory}:/artifacts:rw",
            '--volume', "{$cache}:/cbm-cache:rw",
            '--volume', str_replace('\\', '/', $semgrepCache).':/semgrep-cache:rw',
            '--volume', str_replace('\\', '/', $context).':/benchmark-context.json:ro',
            '--volume', str_replace('\\', '/', $modelsFile).':/models.json:ro',
            '--env', 'ARTIFACT_DIR=/artifacts', '--env', "LAILAPS_RUN_ID={$runId}",
            '--env', "LAILAPS_OUTCOME_FILE=/artifacts/{$runId}-outcome.json",
            '--env', 'CODEBASE_MEMORY_CACHE_ROOT=/cbm-cache',
            '--env', 'CODEBASE_MEMORY_NATIVE_CACHE_DIR=/opt/codebase-memory-launcher-cache',
            '--env', 'SURFACE_CONTEXT_CACHE_ROOT=/semgrep-cache',
            '--env', 'HOME=/tmp', '--env', 'XDG_CACHE_HOME=/tmp/.cache',
            '--env', 'PYTHONUTF8=1', '--env', 'PYTHONIOENCODING=utf-8',
            '--env', 'LAILAPS_EVENT_STREAM=0',
            '--env', 'MODELS_CONFIG_FILE=/models.json',
            '--env', 'LAILAPS_BENCHMARK_FULL_LOG=1',
        ];
        if (is_file((string) $config['env_file'])) {
            array_push($command, '--env-file', (string) realpath($config['env_file']));
        }

        return [...$command, $config['image'], ...$args];
    }

    private function absolutePath(string $path): string
    {
        $candidate = preg_match('#^(?:[A-Za-z]:[\\/]|/)#', $path) ? $path : base_path($path);
        $resolved = realpath($candidate);

        return $resolved === false ? '' : str_replace('\\', '/', $resolved);
    }
}
