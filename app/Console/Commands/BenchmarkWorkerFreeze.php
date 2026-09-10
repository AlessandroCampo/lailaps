<?php

namespace App\Console\Commands;

use App\Models\BenchmarkStageArtifact;
use App\Services\Pentest\BenchmarkStageArtifactRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

final class BenchmarkWorkerFreeze extends Command
{
    protected $signature = 'benchmark:worker:freeze
        {target-id}
        {--dataset=worker-gold-v1 : Dataset version to create}
        {--definition= : JSON definition; defaults to the versioned dataset}';

    protected $description = 'Creates immutable Worker subjects only from frozen Confirmer CandidateHandoff artifacts';

    public function handle(BenchmarkStageArtifactRegistry $registry): int
    {
        $target = (string) $this->argument('target-id');
        $dataset = (string) $this->option('dataset');
        $definition = $this->option('definition') ?: base_path("agent/pentest-agent/benchmarks/experiments/{$dataset}.json");
        if (! is_file($definition)) {
            throw new InvalidArgumentException('Definizione del dataset Worker assente.');
        }
        $spec = json_decode(File::get($definition), true, 512, JSON_THROW_ON_ERROR);
        if (($spec['dataset'] ?? null) !== $dataset || ($spec['project_key'] ?? null) !== $target) {
            throw new InvalidArgumentException('La definizione non corrisponde a target/dataset richiesti.');
        }
        if (BenchmarkStageArtifact::query()->where('project_key', $target)->where('role', 'confirmer')->where('label_set_version', $dataset)->exists()) {
            throw new InvalidArgumentException("Dataset {$dataset} gia' congelato: non viene sovrascritto.");
        }

        foreach ((array) ($spec['candidates'] ?? []) as $row) {
            if (! is_string($row['artifact_id'] ?? null) || trim($row['artifact_id']) === '') {
                throw new InvalidArgumentException('Il dataset Worker deve riferire artifact_id prodotti dal benchmark Confirmer; candidate curati non sono ammessi.');
            }
            $payload = $this->historicalPayload($target, $row['artifact_id']);
            $provenance = ['type' => 'confirmer_benchmark_candidate', 'artifact_id' => $row['artifact_id']];
            $parent = BenchmarkStageArtifact::query()->findOrFail($row['artifact_id']);
            $registry->record([
                'project_key' => $target, 'category' => (string) ($row['category'] ?? $parent?->category ?? ''),
                'source_commit' => (string) ($spec['source_commit'] ?? $parent?->source_commit ?? ''),
                'parent_artifact_id' => $parent?->id, 'role' => 'confirmer', 'output_type' => 'CandidateHandoff',
                'model' => 'benchmark:frozen-candidate',
                'payload' => $payload, 'accepted' => true, 'is_canonical' => true,
                'label' => (string) ($row['label'] ?? 'unresolved'), 'matched_case_id' => (string) ($row['case_id'] ?? ''),
                'label_set_version' => $dataset,
                'label_notes' => 'Frozen clone; Confirmer payload remains immutable.',
                'metrics' => ['dataset' => $dataset, 'oracle' => (array) ($row['oracle'] ?? []), 'provenance' => $provenance],
            ]);
        }
        $this->info("Frozen dataset {$dataset} created.");
        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function historicalPayload(string $target, string $id): array
    {
        $artifact = BenchmarkStageArtifact::query()->findOrFail($id);
        if ($artifact->project_key !== $target || $artifact->role !== 'confirmer' || $artifact->output_type !== 'CandidateHandoff' || $artifact->status !== 'valid') {
            throw new InvalidArgumentException("Artifact storico non e' un CandidateHandoff valido del target: {$id}");
        }
        if (blank($artifact->label_set_version) || blank(data_get($artifact->metrics, 'oracle_version'))) {
            throw new InvalidArgumentException("Artifact {$id} non proviene da una valutazione benchmark Confirmer versionata.");
        }
        return (array) $artifact->payload;
    }

}
