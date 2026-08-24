<?php

namespace App\Console\Commands;

use App\Services\Audit\RunStorage;
use App\Services\Pentest\BenchmarkAuditSource;
use App\Services\Pentest\BenchmarkCatalog;
use App\Services\Pentest\BenchmarkEvaluator;
use App\Services\Pentest\BenchmarkManifest;
use App\Services\Pentest\BenchmarkResultAggregator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

final class BenchmarkRun extends Command
{
    protected $signature = 'benchmark:run
        {target-id : ID della directory target con manifest statici}
        {--path= : Path del target, default targets/<target-id>}
        {--url= : URL di un ambiente già avviato}
        {--db= : DSN del DB del target remoto da verificare}
        {--health-path= : Endpoint applicativo che deve rispondere 2xx}
        {--category= : Sotto-categoria benchmark opzionale (es. sqli); default tutte}
        {--skip-health : Salta health check del target remoto}
        {--assume-authorized=true : Conferma autorizzazione per target non locale}
        {--reader-model= : Modello OpenRouter per il Reader}
        {--reviewer-model= : Modello OpenRouter per l Exploration Reviewer}
        {--confirmer-model= : Modello OpenRouter per il Confirmer}
        {--worker-model= : Modello OpenRouter per il Worker}
        {--judge-model= : Modello OpenRouter per il Dynamic Judge}
        {--operative-model= : Modello OpenRouter per Confirmer e Worker; sostituisce i default di ruolo}
        {--budget-category= : Preset cap categoria: small, regular, big oppure huge}
        {--tool-output : Mostra una sintesi compatta delle risposte dei tool durante la run}
        {--audit-id= : ID parlante della run}
        {--keep=true : Mantiene le sandbox avviate}
        {--no-test : Disabilita rebuild e reasoning diagnostico dell’agente}
        {--test : Ricostruisce l’immagine dell’agente}';

    protected $description = 'Esegue benchmark statici e salva report e benchmark nel singolo outcome della run';

    public function handle(
        BenchmarkCatalog $catalog,
        BenchmarkEvaluator $evaluator,
        BenchmarkAuditSource $auditSource,
        BenchmarkResultAggregator $aggregator,
        RunStorage $storage,
    ): int {
        $targetId = (string) $this->argument('target-id');
        $manifests = $catalog->forTarget($targetId);
        $requested = array_values(array_filter(array_map(
            fn (string $value): string => strtolower(trim($value)),
            (array) $this->option('category'),
        )));
        if (count($requested) > 1) {
            throw new InvalidArgumentException('Il benchmark supporta una sola sotto-categoria per run.');
        }
        if ($requested !== []) {
            $manifests = array_values(array_filter($manifests, fn (BenchmarkManifest $manifest): bool => $this->matches($manifest, $requested[0])));
        }
        if ($manifests === []) {
            throw new InvalidArgumentException('Nessun manifest corrisponde al target/categoria richiesto.');
        }

        $source = $this->option('path')
            ? $this->absolutePath((string) $this->option('path'))
            : base_path('targets/'.$targetId);
        if (! is_dir($source)) {
            throw new InvalidArgumentException("Path target inesistente: {$source}");
        }

        $categories = $requested !== [] ? $requested : ['all'];
        $forcedDirectory = getenv('LAILAPS_RUN_DIRECTORY');
        $requestedRunId = $this->option('audit-id') ?: getenv('LAILAPS_RUN_ID');
        if ((is_string($forcedDirectory) && $forcedDirectory !== '' && (! is_string($requestedRunId) || $requestedRunId === ''))
            || (is_string($requestedRunId) && $requestedRunId !== ''
                && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}$/', $requestedRunId) !== 1)) {
            throw new InvalidArgumentException('audit-id non valido.');
        }
        if (is_string($forcedDirectory) && $forcedDirectory !== '') {
            $runId = (string) $requestedRunId;
            $directory = $forcedDirectory;
            $storage->initialize($directory, $runId);
        } elseif ($this->option('audit-id')) {
            $runId = (string) $this->option('audit-id');
            $directory = $storage->forId($targetId, $categories, $runId);
        } else {
            $location = $storage->create($targetId, $categories);
            $runId = $location['run_id'];
            $directory = $location['directory'];
        }
        $startMessage = 'Benchmark '.$targetId.' | categorie: '.implode(', ', $categories);
        $this->info($startMessage);
        $this->appendRunLog($storage, $directory, $runId, "[benchmark] {$startMessage}\n");

        $oldDirectory = getenv('LAILAPS_RUN_DIRECTORY');
        $oldRunId = getenv('LAILAPS_RUN_ID');
        $oldOutcome = getenv('LAILAPS_OUTCOME_FILE');
        $oldHarnessEnvironment = [];
        putenv('LAILAPS_RUN_DIRECTORY='.$directory);
        putenv('LAILAPS_RUN_ID='.$runId);
        putenv('LAILAPS_OUTCOME_FILE='.$storage->outcomePath($directory, $runId));

        $workDirectory = storage_path("framework/lailaps-benchmark/{$runId}");
        if (is_dir($workDirectory)) {
            File::deleteDirectory($workDirectory);
        }
        $results = [];
        $reports = [];
        try {
            $descriptor = $catalog->descriptor($targetId);
            $agentSource = $auditSource->materialize($source, $workDirectory.'/source', $targetId, $descriptor);
            foreach ($descriptor?->harnessEnvironment() ?? [] as $name => $value) {
                $oldHarnessEnvironment[$name] = getenv($name);
                putenv($name.'='.$value);
            }
            $fixtureProbeFile = $this->writeFixtureProbeFile($workDirectory, $manifests);
            foreach ($manifests as $manifest) {
                $categorySlug = $manifest->benchmarkCategory() ?: strtolower($manifest->categoryId());
                $parameters = [
                    '--path' => $agentSource,
                    '--audit-id' => $runId,
                    '--project-name' => $targetId,
                    '--category' => [$manifest->auditCategory()],
                    '--reader-model' => $this->option('reader-model'),
                    '--reviewer-model' => $this->option('reviewer-model'),
                    '--confirmer-model' => $this->option('confirmer-model'),
                    '--worker-model' => $this->option('worker-model'),
                    '--judge-model' => $this->option('judge-model'),
                    '--operative-model' => $this->option('operative-model'),
                    '--budget-category' => $this->option('budget-category'),
                    '--tool-output' => $this->enabledOption('tool-output'),
                    '--keep' => $this->enabledOption('keep'),
                    '--test' => $this->enabledOption('test'),
                ];
                if ($fixtureProbeFile !== null) {
                    $parameters['--fixture-probes'] = $fixtureProbeFile;
                }
                foreach (['url', 'db', 'health-path', 'skip-health'] as $option) {
                    if ($this->option($option)) {
                        $parameters['--'.$option] = $this->option($option);
                    }
                }
                $parameters['--assume-authorized'] = $this->enabledOption('assume-authorized');
                $exit = Artisan::call('pentest:run', $parameters, $this->output);
                $outcome = $storage->outcome($directory, $runId);
                $report = is_array($outcome['report'] ?? null) ? $outcome['report'] : null;
                if ($report === null) {
                    $result = $evaluator->missingResult($manifest, ['run_state' => $exit === 0 ? 'completed' : 'failed']);
                } else {
                    $reports[$categorySlug] = $report;
                    $result = $evaluator->evaluate($directory, $manifest, $source, ['run_state' => $exit === 0 ? 'completed' : 'failed']);
                }
                $result['exit_code'] = $exit;
                $result['audit_id'] = $runId;
                $results[$categorySlug] = $result;
            }

            $runState = in_array('failed', array_column($results, 'run_state'), true) ? 'failed' : 'completed';
            $benchmark = $aggregator->aggregate($targetId, $runId, $results, ['run_state' => $runState]);
            $report = $this->aggregateReports($reports);
            $storage->writeOutcome($directory, $runId, $report, $benchmark);

            $outcomeMessage = 'Outcome benchmark: '.$storage->outcomePath($directory, $runId);
            $this->info($outcomeMessage);
            $this->appendRunLog($storage, $directory, $runId, $outcomeMessage."\n");
            $summary = $this->benchmarkSummary($benchmark);
            foreach ($summary as $line) {
                $this->line($line);
                $this->appendRunLog($storage, $directory, $runId, "[benchmark] {$line}\n");
            }
            $metricRows = [
                $this->metricRow('Suspected', $benchmark['suspected']),
                $this->metricRow('Static validation', $benchmark['static_validation']),
                $this->metricRow('Dynamic confirmation', $benchmark['dynamically_confirmed']),
            ];
            $this->table(['Mode', 'TP', 'FP', 'FN', 'Precision', 'Recall', 'F1'], $metricRows);
            foreach ($metricRows as $row) {
                $this->appendRunLog(
                    $storage,
                    $directory,
                    $runId,
                    sprintf(
                        "[benchmark] %s | TP %s, FP %s, FN %s | precision %s, recall %s, F1 %s\n",
                        ...$row,
                    ),
                );
            }

        return array_intersect(['incomplete', 'incompatible'], array_column($results, 'status')) !== [] ? self::FAILURE : self::SUCCESS;
        } finally {
            File::deleteDirectory($workDirectory);
            $this->restoreEnv('LAILAPS_RUN_DIRECTORY', $oldDirectory);
            $this->restoreEnv('LAILAPS_RUN_ID', $oldRunId);
            $this->restoreEnv('LAILAPS_OUTCOME_FILE', $oldOutcome);
            foreach ($oldHarnessEnvironment as $name => $value) {
                $this->restoreEnv($name, $value);
            }
            $this->appendRunLog($storage, $directory, $runId, "[benchmark] completato\n");
            $this->info('Benchmark completato.');
        }
    }

    /** @param array<int, BenchmarkManifest> $manifests */
    private function writeFixtureProbeFile(string $workDirectory, array $manifests): ?string
    {
        $probes = [];
        foreach ($manifests as $manifest) {
            foreach ($manifest->fixtureProbes() as $probe) {
                $probe['manifest_id'] = $manifest->id();
                $probes[] = $probe;
            }
        }
        if ($probes === []) {
            return null;
        }

        $path = $workDirectory.'/fixture-probes.json';
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($probes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $path;
    }

    /** @param array<string, mixed> $benchmark @return list<string> */
    private function benchmarkSummary(array $benchmark): array
    {
        $score = (array) ($benchmark['score'] ?? []);
        $fileReach = (array) data_get($benchmark, 'reach.file_reached', []);
        $anchorReach = (array) data_get($benchmark, 'reach.anchor_reached', []);
        $conversions = (array) ($benchmark['conversions'] ?? []);

        return [
            sprintf(
                'Benchmark: %s | artifact %s | run %s | adjudication %s | pending %d',
                (string) ($benchmark['status'] ?? 'unknown'),
                (string) ($benchmark['artifact_state'] ?? 'unknown'),
                (string) ($benchmark['run_state'] ?? 'unknown'),
                (string) ($benchmark['adjudication_state'] ?? 'unknown'),
                (int) ($benchmark['pending_adjudications'] ?? 0),
            ),
            sprintf(
                'Score: %s/%s pt (%s%%) | positive %s | penalty %s',
                $this->formatMetric($score['points'] ?? null),
                $this->formatMetric($score['max_points'] ?? null),
                $this->formatMetric($score['normalized'] ?? null),
                $this->formatMetric($score['positive_points'] ?? null),
                $this->formatMetric($score['negative_penalty'] ?? null),
            ),
            sprintf(
                'Reach: files %d/%d (%s%%) | anchors %d/%d (%s%%)',
                (int) ($fileReach['reached'] ?? 0),
                (int) ($fileReach['total'] ?? 0),
                $this->formatMetric(100 * (float) ($fileReach['recall'] ?? 0)),
                (int) ($anchorReach['reached'] ?? 0),
                (int) ($anchorReach['total'] ?? 0),
                $this->formatMetric(100 * (float) ($anchorReach['recall'] ?? 0)),
            ),
            sprintf(
                'Conversions: anchor→suspected %s | suspected→static %s | static→dynamic %s',
                $this->formatRatio($conversions['anchor_to_suspected'] ?? null),
                $this->formatRatio($conversions['suspected_to_static'] ?? null),
                $this->formatRatio($conversions['static_to_dynamic'] ?? null),
            ),
        ];
    }

    private function formatMetric(mixed $value): string
    {
        return $value === null ? '—' : number_format((float) $value, 2, '.', '');
    }

    private function formatRatio(mixed $value): string
    {
        return $value === null ? '—' : number_format(100 * (float) $value, 1, '.', '').'%';
    }

    private function matches(BenchmarkManifest $manifest, string $value): bool
    {
        $genericId = strtolower(trim(explode(':', $value, 2)[0]));
        $generic = preg_match('/^a\d{2}(?::\d{4})?$/', $value) === 1;

        return in_array($value, [
            strtolower($manifest->categoryId()),
            strtolower((string) ($manifest->data['category']['name'] ?? '')),
            strtolower((string) $manifest->benchmarkCategory()),
            strtolower($manifest->auditCategory()),
        ], true) || ($generic && $genericId === strtolower($manifest->categoryId()));
    }

    /** @param array<string, array<string, mixed>> $reports */
    private function aggregateReports(array $reports): ?array
    {
        if ($reports === []) {
            return null;
        }
        if (count($reports) === 1) {
            return array_values($reports)[0];
        }
        $aggregate = [
            'schema_version' => 8,
            'publication_state' => 'final',
            'outcome' => 'clean',
            'categories' => [],
            'confirmed' => [],
            'suspected' => [],
            'rejected_inconclusive' => [],
            'blockers' => [],
            'files_seen' => [],
            'source_observations' => [],
            'telemetry' => [],
            'models' => [],
        ];
        $environmentStates = [];
        foreach ($reports as $slug => $report) {
            if ($aggregate['models'] === [] && is_array($report['models'] ?? null)) {
                $aggregate['models'] = $report['models'];
            }
            $aggregate['categories'][] = [
                'slug' => $slug,
                'outcome' => $report['outcome'] ?? null,
                'telemetry' => $report['telemetry'] ?? [],
                'environment' => $report['environment'] ?? null,
            ];
            $aggregate['telemetry'] = $this->sumTelemetry($aggregate['telemetry'], (array) ($report['telemetry'] ?? []));
            $state = data_get($report, 'environment.state');
            if (is_string($state) && $state !== '') {
                $environmentStates[] = $state;
            }
            foreach (['confirmed', 'suspected', 'rejected_inconclusive'] as $key) {
                foreach ((array) ($report[$key] ?? []) as $finding) {
                    if (! is_array($finding)) {
                        continue;
                    }
                    $localId = (string) ($finding['finding_id'] ?? $finding['lead_id'] ?? 'finding');
                    $finding['source_finding_id'] = $localId;
                    $finding['finding_id'] = $slug.':'.$localId;
                    $finding['lead_id'] = $finding['finding_id'];
                    $aggregate[$key][] = $finding;
                }
            }
            foreach (['blockers', 'files_seen', 'source_observations'] as $key) {
                $aggregate[$key] = [...$aggregate[$key], ...(array) ($report[$key] ?? [])];
            }
        }
        $aggregate['files_seen'] = array_values(array_unique($aggregate['files_seen']));
        $aggregate['outcome'] = $aggregate['confirmed'] !== [] || $aggregate['suspected'] !== [] ? 'findings' : 'clean';
        if ($environmentStates !== []) {
            $aggregate['environment'] = ['state' => in_array('invalid', $environmentStates, true) ? 'invalid' : (count(array_unique($environmentStates)) === 1 ? $environmentStates[0] : 'unknown')];
        }

        return $aggregate;
    }

    /** @param array<string, mixed> $left @param array<string, mixed> $right @return array<string, mixed> */
    private function sumTelemetry(array $left, array $right): array
    {
        foreach ($right as $key => $value) {
            if (is_int($value) || is_float($value)) {
                $left[$key] = (is_numeric($left[$key] ?? null) ? $left[$key] : 0) + $value;
            } elseif ($key === 'model' && is_string($value)) {
                $left[$key] = ! isset($left[$key]) || $left[$key] === $value ? $value : 'mixed';
            } elseif (is_array($value)) {
                $left[$key] = $this->sumTelemetry(is_array($left[$key] ?? null) ? $left[$key] : [], $value);
            }
        }

        return $left;
    }

    private function absolutePath(string $path): string
    {
        $candidate = preg_match('#^(?:[A-Za-z]:[\\/]|/)#', $path) ? $path : base_path($path);

        return str_replace('\\', '/', (string) realpath($candidate));
    }

    private function enabledOption(string $name): bool
    {
        if ($name === 'test' && $this->option('no-test')) {
            return false;
        }
        $value = $this->option($name);
        if ($value === null || ($name === 'test' && $value === false)) {
            return true;
        }
        if (is_bool($value)) {
            return $value;
        }
        $normalized = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($normalized === null) {
            throw new InvalidArgumentException("--{$name} accetta solo true o false.");
        }

        return $normalized;
    }

    private function restoreEnv(string $name, string|false $value): void
    {
        putenv($value === false ? $name : $name.'='.$value);
    }

    private function appendRunLog(RunStorage $storage, string $directory, string $runId, string $contents): void
    {
        if (getenv('LAILAPS_SUPERVISED_LOG') !== '1') {
            $storage->appendLog($directory, $runId, $contents);
        }
    }

    /** @param array<string, int|float> $metric @return list<string|int> */
    private function metricRow(string $label, array $metric): array
    {
        return [$label, $metric['tp'] ?? 0, $metric['fp'] ?? 0, $metric['fn'] ?? 0,
            number_format((float) ($metric['precision'] ?? 0), 3),
            number_format((float) ($metric['recall'] ?? 0), 3),
            number_format((float) ($metric['f1'] ?? 0), 3)];
    }
}
