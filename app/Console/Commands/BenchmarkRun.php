<?php

namespace App\Console\Commands;

use App\Services\Pentest\BenchmarkMaterializer;
use App\Services\Pentest\RealWorldBenchmarkCatalog;
use App\Services\Pentest\RealWorldBenchmarkEvaluator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

final class BenchmarkRun extends Command
{
    protected $signature = 'benchmark:run {benchmark-id} {--keep} {--review-ambiguous} {--audit-id= : ID riservato agli orchestratori interni}';

    protected $description = 'Materializza ed esegue un benchmark real-world autorizzato';

    public function handle(RealWorldBenchmarkCatalog $catalog, BenchmarkMaterializer $materializer, RealWorldBenchmarkEvaluator $evaluator): int
    {
        $benchmark = $catalog->resolve((string) $this->argument('benchmark-id'));
        $source = $materializer->materialize($benchmark);
        $auditId = $this->option('audit-id') ?: 'benchmark-'.date('YmdHis').'-'.bin2hex(random_bytes(3));
        $artifacts = storage_path("app/audits/{$auditId}/artifacts");
        if (! is_dir($artifacts) && ! mkdir($artifacts, 0755, true) && ! is_dir($artifacts)) {
            throw new \RuntimeException('Impossibile creare la directory artefatti.');
        }
        $publicPath = "{$artifacts}/benchmark-manifest.json";
        file_put_contents($publicPath, json_encode($benchmark->public, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        file_put_contents("{$artifacts}/snapshot-verification.json", json_encode([
            'repository' => data_get($benchmark->public, 'snapshot.repository'),
            'commit' => data_get($benchmark->public, 'snapshot.commit'),
            'verified_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        $parameters = [
            '--path' => $source,
            '--audit-id' => $auditId,
            '--compose' => $benchmark->composePath(),
            '--audit-profile' => $benchmark->profilePath(),
            '--benchmark-context' => $publicPath,
            '--category' => [(string) $benchmark->public['category']],
            '--keep' => (bool) $this->option('keep'),
        ];
        $exit = Artisan::call('pentest:run', $parameters, $this->output);
        if (is_file("{$artifacts}/report.json")) {
            $score = $evaluator->evaluate(str_replace('\\', '/', $artifacts), $benchmark);
            file_put_contents("{$artifacts}/benchmark-score.json", json_encode($score, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $this->info("Score benchmark salvato in {$artifacts}/benchmark-score.json");
        }

        return $exit;
    }
}
