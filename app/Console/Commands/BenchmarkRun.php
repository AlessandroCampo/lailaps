<?php

namespace App\Console\Commands;

use App\Services\Pentest\BenchmarkAuditSource;
use App\Services\Pentest\BenchmarkCatalog;
use App\Services\Pentest\BenchmarkEvaluator;
use App\Services\Pentest\BenchmarkManifest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
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
        {--dual-agent : Separa reader white-box e worker di conferma}
        {--reader-model= : Modello OpenRouter per il reader dual-agent}
        {--worker-model= : Modello OpenRouter per il worker dual-agent}
        {--tool-output=true : Mostra chiamate e risposte dei tool durante la run}
        {--audit-id= : ID della suite}
        {--keep=true : Mantiene le sandbox avviate}
        {--no-test : Disabilita rebuild e reasoning diagnostico dellâ€™agente}
        {--test : Ricostruisce l’immagine dell’agente}';

    protected $description = 'Esegue benchmark statici con contract lailaps.benchmark';

    public function handle(
        BenchmarkCatalog $catalog,
        BenchmarkEvaluator $evaluator,
        BenchmarkAuditSource $auditSource,
    ): int {
        $targetId = (string) $this->argument('target-id');
        $manifests = $catalog->forTarget($targetId);
        $requested = array_values(array_filter(array_map(
            fn (string $value): string => strtolower(trim($value)),
            (array) $this->option('category'),
        )));
        if (count($requested) > 1) {
            throw new InvalidArgumentException('Il benchmark supporta una sola sotto-categoria per run. Ometti --category per eseguirle tutte.');
        }
        if ($requested !== []) {
            $manifests = array_values(array_filter($manifests, function (BenchmarkManifest $manifest) use ($requested): bool {
                $categoryId = strtolower($manifest->categoryId());
                $categoryName = strtolower((string) ($manifest->data['category']['name'] ?? ''));
                $benchmarkCategory = strtolower((string) $manifest->benchmarkCategory());

                foreach ($requested as $value) {
                    // Accept both the generic OWASP label used by pentest:run
                    // (A05:2025 Injection) and the benchmark slice (sqli/cmdi/...).
                    $genericId = strtolower(trim(explode(':', $value, 2)[0]));
                    if ($value === $benchmarkCategory || $value === $categoryId || $value === $categoryName || $genericId === $categoryId) {
                        return true;
                    }
                }

                return false;
            }));
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

        $suiteId = (string) ($this->option('audit-id') ?: 'benchmark-'.date('YmdHis').'-'.bin2hex(random_bytes(3)));
        $suiteArtifacts = storage_path("app/audits/{$suiteId}/artifacts");
        if (! is_dir($suiteArtifacts) && ! mkdir($suiteArtifacts, 0755, true) && ! is_dir($suiteArtifacts)) {
            throw new \RuntimeException("Impossibile creare gli artefatti: {$suiteArtifacts}");
        }
        $agentSource = $auditSource->materialize($source, $suiteArtifacts.'/source', $targetId);

        $results = [];
        foreach ($manifests as $manifest) {
            $categorySlug = $manifest->benchmarkCategory() ?: strtolower($manifest->categoryId());
            $childAuditId = $suiteId.'-'.$categorySlug;
            $parameters = [
                '--path' => $agentSource,
                '--audit-id' => $childAuditId,
                '--category' => ['A05:2025 Injection'],
                '--dual-agent' => (bool) $this->option('dual-agent'),
                '--reader-model' => $this->option('reader-model'),
                '--worker-model' => $this->option('worker-model'),
                '--tool-output' => $this->enabledOption('tool-output'),
                '--keep' => $this->enabledOption('keep'),
                '--test' => $this->enabledOption('test'),
            ];
            foreach (['url', 'db', 'health-path', 'skip-health'] as $option) {
                if ($this->option($option)) {
                    $parameters['--'.$option] = $this->option($option);
                }
            }
            $parameters['--assume-authorized'] = $this->enabledOption('assume-authorized');
            $exit = Artisan::call('pentest:run', $parameters, $this->output);
            $childArtifacts = storage_path("app/audits/{$childAuditId}/artifacts");
            if (! is_file($childArtifacts.'/report.json')) {
                $results[$categorySlug] = ['status' => 'incomplete', 'benchmark_id' => $manifest->id(), 'reason' => 'report.json mancante', 'exit_code' => $exit];

                continue;
            }
            $result = $evaluator->evaluate($childArtifacts, $manifest, $source);
            $result['audit_id'] = $childAuditId;
            $results[$categorySlug] = $result;
            file_put_contents($suiteArtifacts.'/'.$categorySlug.'.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        }

        $aggregate = $this->aggregate($targetId, $suiteId, $results);
        file_put_contents($suiteArtifacts.'/benchmark.json', json_encode($aggregate, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $this->info("Risultato benchmark salvato in {$suiteArtifacts}/benchmark.json");

        return array_intersect(['incomplete', 'incompatible'], array_column($results, 'status')) !== [] ? self::FAILURE : self::SUCCESS;
    }

    /** @param array<string, array<string, mixed>> $results @return array<string, mixed> */
    private function aggregate(string $targetId, string $suiteId, array $results): array
    {
        $allCases = [];
        $cost = ['total_tokens' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'model_requests' => 0, 'tool_calls' => 0, 'http_requests' => 0];
        foreach ($results as $result) {
            foreach ($result['cases'] ?? [] as $case) {
                $allCases[] = $case;
            }
            foreach ($cost as $key => $value) {
                $cost[$key] += (int) ($result['cost'][$key] ?? 0);
            }
        }

        return [
            'schema' => 'lailaps.benchmark-result',
            'status' => in_array('incomplete', array_column($results, 'status'), true) || in_array('incompatible', array_column($results, 'status'), true) ? 'incomplete' : 'scored',
            'target_id' => $targetId, 'suite_id' => $suiteId,
            'detection' => $this->metrics($allCases, 'detection'),
            'confirmation' => $this->metrics($allCases, 'confirmation'),
            'by_category' => $results, 'cases' => $allCases, 'cost' => $cost,
        ];
    }

    /** @param array<int, array<string, mixed>> $cases @return array<string, int|float> */
    private function metrics(array $cases, string $mode): array
    {
        $counts = ['tp' => 0, 'fp' => 0, 'tn' => 0, 'fn' => 0];
        foreach ($cases as $case) {
            $key = match ($case[$mode] ?? null) {
                'true_positive' => 'tp', 'false_positive' => 'fp', 'true_negative' => 'tn', 'false_negative' => 'fn', default => null
            };
            if ($key !== null) {
                $counts[$key]++;
            }
        }
        $precision = $counts['tp'] + $counts['fp'] > 0 ? $counts['tp'] / ($counts['tp'] + $counts['fp']) : 0.0;
        $recall = $counts['tp'] + $counts['fn'] > 0 ? $counts['tp'] / ($counts['tp'] + $counts['fn']) : 0.0;

        return [...$counts, 'precision' => $precision, 'recall' => $recall, 'f1' => $precision + $recall > 0 ? 2 * $precision * $recall / ($precision + $recall) : 0.0, 'false_positive_rate' => $counts['fp'] + $counts['tn'] > 0 ? $counts['fp'] / ($counts['fp'] + $counts['tn']) : 0.0];
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
}
