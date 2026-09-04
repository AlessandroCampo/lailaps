<?php

namespace App\Console\Commands;

use App\Enums\AuditRunStatus;
use App\Jobs\ExecuteAuditRun;
use App\Models\AuditRun;
use App\Models\BenchmarkExperiment;
use App\Services\Audit\AuditCommandBuilder;
use App\Services\Audit\AuditRunFinalizer;
use App\Services\Audit\RunStorage;
use App\Services\Pentest\BenchmarkCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

final class BenchmarkExperimentRun extends Command
{
    protected $signature = 'benchmark:experiment:run
        {--config= : JSON o YAML della matrice}
        {--foreground : Esegue le run sequenzialmente mostrando il transcript live, senza queue worker}
        {--allow-dirty : Consente una run marcata non riproducibile}';

    protected $description = 'Accoda o esegue in foreground una matrice target × categoria × modelli × ripetizioni';

    public function handle(
        BenchmarkCatalog $catalog,
        RunStorage $storage,
        AuditCommandBuilder $commands,
        AuditRunFinalizer $finalizer,
    ): int {
        $path = (string) $this->option('config');
        if (! is_file($path)) {
            $this->error('Config esperimento inesistente.');

            return self::INVALID;
        }
        $definition = str_ends_with(strtolower($path), '.json')
            ? json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)
            : Yaml::parseFile($path);
        if (! is_array($definition) || ! is_array($definition['targets'] ?? null)) {
            $this->error('La matrice richiede targets e una matrice modelli valida.');

            return self::INVALID;
        }
        $modelMatrix = $this->modelMatrix($definition);
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
            'reproducible' => ! $dirty,
        ]);
        $repetitions = max(1, min(20, (int) ($definition['repetitions'] ?? 3)));
        $runs = [];
        foreach ($definition['targets'] as $target) {
            $target = is_string($target) ? ['id' => $target] : $target;
            $targetId = (string) ($target['id'] ?? '');
            $descriptor = $catalog->descriptor($targetId);
            if ($descriptor === null) {
                throw new \InvalidArgumentException("Descriptor target mancante: {$targetId}");
            }
            $categories = (array) ($target['categories'] ?? []);
            foreach ($modelMatrix as $models) {
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
                        'judge_model' => $models['judge'] ?? null,
                        'benchmark_subject_role' => $definition['subject_role'] ?? null,
                        'target_mode' => 'sandbox', 'url' => null, 'db' => null,
                        'health_path' => null, 'skip_health' => false, 'authorized' => true,
                        'budget_category' => $definition['budget_category'] ?? null,
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
                        'reproducible' => ! $dirty,
                    ]);
                    $runs[] = $run;
                }
            }
        }
        if (! $this->option('foreground')) {
            foreach ($runs as $run) {
                ExecuteAuditRun::dispatch($run->id);
            }
            $this->info("Esperimento {$experiment->id}: ".count($runs).' run accodate.');

            return self::SUCCESS;
        }

        $experiment->update(['status' => 'running', 'started_at' => now()]);
        $this->info("Esperimento {$experiment->id}: ".count($runs).' run foreground sequenziali.');
        $failed = false;
        foreach ($runs as $index => $run) {
            $number = $index + 1;
            $model = $this->subjectModel($run);
            $this->newLine();
            $this->info("[{$number}/".count($runs)."] {$run->audit_id} | {$model}");
            $this->line('Log persistente: '.$storage->logPath((string) $run->run_path, $run->audit_id));
            (new ExecuteAuditRun($run->id))->execute(
                $commands,
                $finalizer,
                $storage,
                fn (string $_type, string $buffer) => $this->output->write($buffer),
            );
            $run->refresh();
            $failed = $failed || $run->status !== AuditRunStatus::Completed;
            $this->info("Run {$run->status->value}; exit_code=".($run->exit_code ?? 'n/a'));
        }
        $this->info("Esperimento {$experiment->id} terminato.");

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function subjectModel(AuditRun $run): string
    {
        $role = strtolower((string) ($run->parameters['benchmark_subject_role'] ?? ''));
        $parameterRole = $role === 'recon' ? 'reader' : $role;
        $model = $run->parameters[$parameterRole.'_model'] ?? null;

        return $role !== '' && is_string($model) && $model !== ''
            ? "{$role}={$model}"
            : 'configurazione completa';
    }

    /** @param array<string, mixed> $definition @return list<array<string, mixed>> */
    private function modelMatrix(array $definition): array
    {
        if (is_array($definition['models'] ?? null) && $definition['models'] !== []) {
            return array_values($definition['models']);
        }
        $role = strtolower(trim((string) ($definition['subject_role'] ?? '')));
        $subjectModels = $definition['subject_models'] ?? null;
        $controls = $definition['control_models'] ?? null;
        if (! in_array($role, ['recon', 'reader', 'reviewer', 'confirmer', 'worker', 'judge'], true)
            || ! is_array($subjectModels) || $subjectModels === [] || ! is_array($controls)) {
            throw new \InvalidArgumentException(
                'Usa models oppure subject_role + subject_models + control_models.',
            );
        }
        $parameterRole = $role === 'recon' ? 'reader' : $role;
        $requiredControls = array_values(array_diff(['reader', 'reviewer', 'confirmer', 'worker', 'judge'], [$parameterRole]));
        foreach ($requiredControls as $requiredRole) {
            if (! is_string($controls[$requiredRole] ?? null) || trim((string) $controls[$requiredRole]) === '') {
                throw new \InvalidArgumentException("control_models deve fissare il ruolo {$requiredRole}.");
            }
        }

        return array_values(array_map(function (mixed $model) use ($controls, $parameterRole): array {
            if (! is_string($model) || trim($model) === '') {
                throw new \InvalidArgumentException('subject_models deve contenere identificatori modello non vuoti.');
            }

            return [...$controls, $parameterRole => trim($model)];
        }, $subjectModels));
    }

    /** @param list<string> $arguments */
    private function git(array $arguments): string
    {
        $process = new Process(['git', ...$arguments], base_path(), timeout: 10);
        $process->mustRun();

        return trim($process->getOutput());
    }
}
