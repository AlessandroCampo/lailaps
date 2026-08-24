<?php

namespace App\Console\Commands;

use App\Models\AuditRun;
use App\Services\Audit\AuditRunFinalizer;
use App\Services\Audit\RunStorage;
use App\Services\Pentest\BenchmarkCatalog;
use App\Services\Pentest\BenchmarkEvaluator;
use App\Services\Pentest\BenchmarkResultAggregator;
use Illuminate\Console\Command;

final class BenchmarkEvaluate extends Command
{
    protected $signature = 'benchmark:evaluate {--run= : ID DB o audit_id} {--evaluator=3.2.1}';

    protected $description = 'Ricalcola il benchmark dagli artefatti senza rilanciare il modello';

    public function handle(BenchmarkCatalog $catalog, BenchmarkEvaluator $evaluator, BenchmarkResultAggregator $aggregator, AuditRunFinalizer $finalizer, RunStorage $storage): int
    {
        if ((string) $this->option('evaluator') !== BenchmarkEvaluator::VERSION) {
            $this->error('Evaluator non disponibile: '.$this->option('evaluator'));

            return self::INVALID;
        }
        $identity = (string) $this->option('run');
        $run = AuditRun::query()->where('id', $identity)->orWhere('audit_id', $identity)->firstOrFail();
        $targetId = (string) ($run->parameters['benchmark_id'] ?? $run->parameters['preset'] ?? '');
        if ($targetId === '') {
            $this->error('La run non dichiara un benchmark target.');

            return self::INVALID;
        }
        $source = base_path('targets/'.$targetId);
        $suiteRoot = rtrim((string) $run->run_path, '/\\');
        $outcome = $storage->outcome($run);
        $hasReport = is_array($outcome['report'] ?? null);
        $results = [];
        foreach ($catalog->forTarget($targetId) as $manifest) {
            $slug = $manifest->benchmarkCategory() ?: strtolower($manifest->categoryId());
            if (! $hasReport) {
                $results[$slug] = $evaluator->missingResult($manifest, ['run_state' => $run->status->value]);

                continue;
            }
            $results[$slug] = $evaluator->evaluate($suiteRoot, $manifest, $source, ['run_state' => $run->status->value]);
        }
        $aggregate = $aggregator->aggregate($targetId, $run->audit_id, $results, ['run_state' => $run->status->value]);
        $path = $storage->outcomePath($suiteRoot, $run->audit_id);
        $storage->writeOutcome($suiteRoot, $run->audit_id, $outcome['report'] ?? null, $aggregate);
        $finalizer->finalize($run->fresh());
        $this->info("Evaluation {$aggregate['status']} salvata in {$path}");

        return $aggregate['status'] === 'incomplete' ? self::FAILURE : self::SUCCESS;
    }
}
