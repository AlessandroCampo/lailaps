<?php

namespace App\Console\Commands;

use App\Enums\AuditRunStatus;
use App\Jobs\ExecuteAuditRun;
use App\Models\AuditRun;
use App\Models\BenchmarkExperiment;
use App\Services\Audit\RunStorage;
use App\Services\Pentest\BenchmarkCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

final class BenchmarkExperimentRun extends Command
{
    protected $signature = 'benchmark:experiment:run {--config= : JSON o YAML della matrice} {--allow-dirty : Consente una run marcata non riproducibile}';
    protected $description = 'Accoda una matrice target × categoria × modelli × ripetizioni';

    public function handle(BenchmarkCatalog $catalog, RunStorage $storage): int
    {
        $path = (string) $this->option('config');
        if (! is_file($path)) {
            $this->error('Config esperimento inesistente.');

            return self::INVALID;
        }
        $definition = str_ends_with(strtolower($path), '.json')
            ? json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)
            : Yaml::parseFile($path);
        if (! is_array($definition) || ! is_array($definition['targets'] ?? null) || ! is_array($definition['models'] ?? null)) {
            $this->error('La matrice richiede targets e models.');

            return self::INVALID;
        }
        $status = $this->git(['status', '--porcelain', '--untracked-files=no']);
        $dirty = $status !== '';
        if ($dirty && ! $this->option('allow-dirty')) {
            $this->error('Worktree dirty: usa --allow-dirty per una run non riproducibile.');

            return self::FAILURE;
        }
        $revision = $this->git(['rev-parse', 'HEAD']);
        $experiment = BenchmarkExperiment::query()->create([
            'id' => (string) Str::ulid(),
            'name' => (string) ($definition['name'] ?? 'experiment-'.now()->format('Ymd-His')),
            'status' => 'queued',
            'definition' => $definition,
            'harness_revision' => $revision,
            'reproducible' => !$dirty,
        ]);
        $repetitions = max(1, min(20, (int) ($definition['repetitions'] ?? 3)));
        $count = 0;
        foreach ($definition['targets'] as $target) {
            $target = is_string($target) ? ['id' => $target] : $target;
            $targetId = (string) ($target['id'] ?? '');
            $descriptor = $catalog->descriptor($targetId);
            if ($descriptor === null) {
                throw new \InvalidArgumentException("Descriptor target mancante: {$targetId}");
            }
            $categories = (array) ($target['categories'] ?? []);
            foreach ($definition['models'] as $models) {
                if (! is_array($models)) {
                    throw new \InvalidArgumentException('Ogni configurazione models deve essere un oggetto.');
                }
                foreach (range(1, $repetitions) as $repetition) {
                    $id = (string) Str::ulid();
                    $parameters = [
                        'type' => 'benchmark', 'benchmark_id' => $targetId,
                        'categories' => $categories,
                        'reader_model' => $models['reader'] ?? null,
                        'reviewer_model' => $models['reviewer'] ?? null,
                        'worker_model' => $models['worker'] ?? null,
                        'confirmer_model' => $models['confirmer'] ?? null,
                        'target_mode' => 'sandbox', 'url' => null, 'db' => null,
                        'health_path' => null, 'skip_health' => false, 'authorized' => true,
                        'keep' => false, 'test' => (bool) ($definition['test'] ?? false),
                        'ttl' => (int) ($definition['ttl'] ?? 3600),
                    ];
                    $location = $storage->create($targetId, $categories);
                    $run = AuditRun::query()->create([
                        'id' => $id, 'benchmark_experiment_id' => $experiment->id,
                        'audit_id' => $location['run_id'], 'type' => 'benchmark', 'repetition' => $repetition,
                        'status' => AuditRunStatus::Queued, 'parameters' => $parameters,
                        'run_path' => str_replace('\\', '/', $location['directory']),
                        'harness_revision' => $revision, 'target_commit' => $descriptor->commit(),
                        'reproducible' => !$dirty,
                    ]);
                    ExecuteAuditRun::dispatch($run->id);
                    $count++;
                }
            }
        }
        $this->info("Esperimento {$experiment->id}: {$count} run accodate.");

        return self::SUCCESS;
    }

    /** @param list<string> $arguments */
    private function git(array $arguments): string
    {
        $process = new Process(['git', ...$arguments], base_path(), timeout: 10);
        $process->mustRun();

        return trim($process->getOutput());
    }
}
