<?php

namespace App\Console\Commands;

use App\Services\Pentest\BenchmarkCatalog;
use App\Services\Pentest\BenchmarkStageArtifactRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class BenchmarkReconImport extends Command
{
    protected $signature = 'benchmark:recon:import
        {target-id : Target benchmark}
        {outcome : File *-outcome.json prodotto dalla Recon}
        {--golden : Promuove la Recon importata a Golden Recon}';

    protected $description = 'Importa una CategoryRecon globale accettata nel registry benchmark';

    public function handle(
        BenchmarkCatalog $catalog,
        BenchmarkStageArtifactRegistry $registry,
    ): int {
        $target = (string) $this->argument('target-id');
        $path = $this->absolutePath((string) $this->argument('outcome'));
        if ($path === '' || ! is_file($path)) {
            throw new InvalidArgumentException("Outcome inesistente: {$path}");
        }
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || ! is_array($decoded['report'] ?? null)) {
            throw new InvalidArgumentException('Outcome privo di report strutturato.');
        }
        $report = $decoded['report'];
        $outputs = array_values(array_filter(
            (array) ($report['structured_outputs'] ?? []),
            fn (mixed $row): bool => is_array($row)
                && ($row['role'] ?? null) === 'recon'
                && ($row['output_type'] ?? null) === 'CategoryRecon'
                && ($row['accepted'] ?? false) === true,
        ));
        if (count($outputs) !== 1 || ! is_array($outputs[0]['payload'] ?? null)) {
            throw new InvalidArgumentException(
                'L outcome deve contenere un unico output recon/CategoryRecon accettato.'
            );
        }
        $payload = $outputs[0]['payload'];
        if (($payload['status'] ?? null) !== 'ready' || count((array) ($payload['areas'] ?? [])) !== 16) {
            throw new InvalidArgumentException('La Golden Recon richiesta deve essere ready con esattamente 16 aree.');
        }

        $manifests = $catalog->forTarget($target);
        if ($manifests === []) {
            throw new InvalidArgumentException('Il target non contiene manifest benchmark.');
        }
        $snapshots = array_values(array_unique(array_map(
            fn ($manifest): string => (string) (data_get($manifest->data, 'source.commit')
                ?: data_get($manifest->data, 'source.snapshot')),
            $manifests,
        )));
        if (count($snapshots) !== 1 || $snapshots[0] === '') {
            throw new InvalidArgumentException('I manifest del target non condividono uno snapshot univoco.');
        }
        $snapshot = $snapshots[0];
        $outcomeTarget = data_get($decoded, 'benchmark.target_id')
            ?? data_get($report, 'benchmark_context.target_id');
        if (is_string($outcomeTarget) && $outcomeTarget !== $target) {
            throw new InvalidArgumentException('Outcome appartenente a un progetto diverso.');
        }
        $outcomeSnapshot = data_get($decoded, 'benchmark.source_snapshot')
            ?? data_get($decoded, 'benchmark.source_commit')
            ?? data_get($report, 'source_snapshot')
            ?? data_get($report, 'source_commit');
        if (is_string($outcomeSnapshot) && $outcomeSnapshot !== '' && $outcomeSnapshot !== $snapshot) {
            throw new InvalidArgumentException('Outcome appartenente a uno snapshot incompatibile.');
        }
        $runId = trim((string) ($decoded['run_id'] ?? ''));
        if ($runId === '' || (! is_string($outcomeTarget) && ! str_starts_with($runId, $target.'-'))) {
            throw new InvalidArgumentException('Impossibile validare il progetto dell outcome.');
        }

        $tree = $registry->recordReconTree([
            'project_key' => $target,
            'category' => 'global',
            'source_commit' => $snapshot,
            'run_id' => $runId,
            'repetition' => 1,
            'role' => 'recon',
            'output_type' => 'CategoryRecon',
            'model' => data_get($report, 'telemetry.role_usage.category_recon.model'),
            'status' => 'valid',
            'accepted' => true,
            'payload' => $payload,
            'usage' => (array) data_get($report, 'telemetry.role_usage.category_recon', []),
            'metrics' => ['imported_from' => $path, 'area_count' => 16],
            'evaluator_version' => 'golden-import-v1',
        ]);
        $parent = $tree['parent'];
        if ($this->option('golden')) {
            $parent = $registry->selectGoldenRecon(new Collection([$parent])) ?? $parent;
        }

        $this->info("Recon importata: {$parent->id}");
        $this->line('16 aree');
        if ($this->option('golden')) {
            $this->info('Golden Recon global promossa.');
        }

        return self::SUCCESS;
    }

    private function absolutePath(string $path): string
    {
        $candidate = preg_match('#^(?:[A-Za-z]:[\\\\/]|/)#', $path) ? $path : base_path($path);
        $resolved = realpath($candidate);
        if ($resolved === false && is_file($candidate)) {
            $resolved = $candidate;
        }

        return $resolved === false ? '' : str_replace('\\', '/', $resolved);
    }
}
