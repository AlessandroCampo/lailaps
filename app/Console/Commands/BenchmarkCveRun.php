<?php

namespace App\Console\Commands;

use App\Data\Pentest\CveSubjectDTO;
use App\Services\Audit\RunStorage;
use App\Services\Pentest\BenchmarkCatalog;
use App\Services\Pentest\BenchmarkEvaluator;
use App\Services\Pentest\BenchmarkResultAggregator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

final class BenchmarkCveRun extends Command
{
    protected $signature = 'benchmark:cve:run
        {target-id : ID della directory benchmark}
        {case-id : ID della CVE/case nel manifest del target}
        {--path= : Path del target, default targets/<target-id>}
        {--url= : URL di un ambiente già avviato}
        {--db= : DSN del DB del target remoto}
        {--health-path= : Endpoint applicativo che deve rispondere 2xx}
        {--skip-health : Salta health check del target remoto}
        {--assume-authorized=true : Conferma autorizzazione per target non locale}
        {--confirmer-model= : Modello OpenRouter per il Confirmer}
        {--worker-model= : Modello OpenRouter per il Worker}
        {--judge-model= : Modello OpenRouter per il Dynamic Judge}
        {--operative-model= : Modello OpenRouter per Confirmer e Worker}
        {--envelope-points= : Hard cap condiviso per Confirmer, Worker e Judge}
        {--tool-output : Mostra una sintesi compatta delle risposte dei tool}
        {--audit-id= : ID parlante della run}
        {--keep : Mantiene la sandbox avviata solo per diagnostica}
        {--no-test : Disabilita rebuild e reasoning diagnostico dell’agente}
        {--test : Ricostruisce l’immagine dell’agente}';

    protected $description = 'Conferma una singola CVE benchmark senza Recon, Reader o Reviewer';

    public function handle(
        BenchmarkCatalog $catalog,
        BenchmarkEvaluator $evaluator,
        BenchmarkResultAggregator $aggregator,
        RunStorage $storage,
    ): int {
        $targetId = (string) $this->argument('target-id');
        $caseId = (string) $this->argument('case-id');
        $manifest = null;
        foreach ($catalog->forTarget($targetId) as $candidate) {
            if (array_filter($candidate->cases(), fn (array $case): bool => (string) $case['id'] === $caseId) !== []) {
                if ($manifest !== null) {
                    throw new InvalidArgumentException("Case benchmark ambigua: {$caseId}");
                }
                $manifest = $candidate;
            }
        }
        if ($manifest === null) {
            throw new InvalidArgumentException("Case benchmark non trovata per {$targetId}: {$caseId}");
        }
        $singleManifest = $manifest->onlyCase($caseId);
        $subject = CveSubjectDTO::fromManifest($manifest, $caseId);
        $envelope = $this->option('envelope-points');
        if (! is_numeric($envelope) || (float) $envelope <= 0) {
            throw new InvalidArgumentException('--envelope-points deve essere un numero positivo.');
        }
        $source = $this->option('path') ?: base_path('targets/'.$targetId);
        $source = str_replace('\\', '/', (string) realpath((string) $source));
        if (! is_dir($source)) {
            throw new InvalidArgumentException("Path target inesistente: {$source}");
        }
        $requested = $this->option('audit-id');
        if ($requested && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}$/', (string) $requested) !== 1) {
            throw new InvalidArgumentException('audit-id non valido.');
        }
        $location = $requested
            ? ['run_id' => (string) $requested, 'directory' => $storage->forId($targetId, ['cve'], (string) $requested)]
            : $storage->create($targetId, ['cve']);
        $runId = $location['run_id'];
        $directory = $location['directory'];
        $workDirectory = storage_path("framework/lailaps-benchmark-cve/{$runId}");
        File::ensureDirectoryExists($workDirectory);
        $subjectPath = $workDirectory.'/cve-subject.json';
        $fixturePath = $workDirectory.'/fixture-probes.json';
        File::put($subjectPath, json_encode($subject->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        File::put($fixturePath, json_encode($subject->toArray()['fixture_probes'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $oldDirectory = getenv('LAILAPS_RUN_DIRECTORY');
        $oldRunId = getenv('LAILAPS_RUN_ID');
        $oldOutcome = getenv('LAILAPS_OUTCOME_FILE');
        putenv('LAILAPS_RUN_DIRECTORY='.$directory);
        putenv('LAILAPS_RUN_ID='.$runId);
        putenv('LAILAPS_OUTCOME_FILE='.$storage->outcomePath($directory, $runId));
        try {
            $parameters = [
                '--path' => $source, '--audit-id' => $runId, '--project-name' => $targetId,
                '--category' => [$manifest->auditCategory()], '--cve-subject' => $subjectPath,
                '--fixture-probes' => $fixturePath, '--envelope-points' => $envelope,
                '--confirmer-model' => $this->option('confirmer-model'), '--worker-model' => $this->option('worker-model'),
                '--judge-model' => $this->option('judge-model'), '--operative-model' => $this->option('operative-model'),
                '--tool-output' => $this->option('tool-output'),
                // A single-CVE benchmark is repeatable only with a pristine DB.
                // Keep is deliberately opt-in; PentestRun then tears down both
                // containers and named volumes after every ordinary run.
                '--keep' => (bool) $this->option('keep'),
                '--test' => $this->option('test'),
            ];
            foreach (['url', 'db', 'health-path', 'skip-health', 'assume-authorized'] as $option) {
                if ($this->option($option)) $parameters['--'.$option] = $this->option($option);
            }
            $exit = Artisan::call('pentest:run', $parameters, $this->output);
            $outcome = $storage->outcome($directory, $runId);
            $report = is_array($outcome['report'] ?? null) ? $outcome['report'] : null;
            $result = $report === null
                ? $evaluator->missingResult($singleManifest, ['run_state' => $exit === 0 ? 'completed' : 'failed'])
                : $evaluator->evaluate($directory, $singleManifest, $source, ['run_state' => $exit === 0 ? 'completed' : 'failed']);
            $result['exit_code'] = $exit;
            $result['audit_id'] = $runId;
            $slug = $manifest->benchmarkCategory() ?: strtolower($manifest->categoryId());
            $benchmark = $aggregator->aggregate($targetId, $runId, [$slug => $result], ['run_state' => $exit === 0 ? 'completed' : 'failed']);
            $storage->writeOutcome($directory, $runId, $report, $benchmark);
            $this->info('Outcome CVE: '.$storage->outcomePath($directory, $runId));
            return $exit === 0 ? self::SUCCESS : self::FAILURE;
        } finally {
            File::deleteDirectory($workDirectory);
            putenv($oldDirectory === false ? 'LAILAPS_RUN_DIRECTORY' : 'LAILAPS_RUN_DIRECTORY='.$oldDirectory);
            putenv($oldRunId === false ? 'LAILAPS_RUN_ID' : 'LAILAPS_RUN_ID='.$oldRunId);
            putenv($oldOutcome === false ? 'LAILAPS_OUTCOME_FILE' : 'LAILAPS_OUTCOME_FILE='.$oldOutcome);
        }
    }
}
