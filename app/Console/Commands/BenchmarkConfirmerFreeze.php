<?php

namespace App\Console\Commands;

use App\Models\BenchmarkStageArtifact;
use App\Services\Pentest\BenchmarkStageArtifactRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Arr;
use InvalidArgumentException;

final class BenchmarkConfirmerFreeze extends Command
{
    protected $signature = 'benchmark:confirmer:freeze
        {target-id}
        {--dataset=confirmer-gold-v1 : Dataset version to create}
        {--definition= : JSON definition; defaults to the versioned YesWiki definition}';

    protected $description = 'Creates immutable, versioned Confirmer subjects from historical Reader artifacts and curated negatives';

    public function handle(BenchmarkStageArtifactRegistry $registry): int
    {
        $dataset = (string) $this->option('dataset');
        $definition = $this->option('definition') ?: base_path("agent/pentest-agent/benchmarks/experiments/{$dataset}.json");
        if (! is_file($definition)) {
            throw new InvalidArgumentException("Definizione dataset assente: {$definition}");
        }
        $spec = json_decode(File::get($definition), true, 512, JSON_THROW_ON_ERROR);
        if (($spec['dataset'] ?? null) !== $dataset || ($spec['project_key'] ?? null) !== $this->argument('target-id')) {
            throw new InvalidArgumentException('La definizione non corrisponde a target/dataset richiesti.');
        }
        if (BenchmarkStageArtifact::query()->where('project_key', $spec['project_key'])->where('label_set_version', $dataset)->exists()) {
            throw new InvalidArgumentException("Dataset {$dataset} gia' congelato: non viene sovrascritto.");
        }
        foreach ((array) ($spec['historical'] ?? []) as $row) {
            $parent = BenchmarkStageArtifact::query()->findOrFail((string) $row['artifact_id']);
            if ($parent->project_key !== $spec['project_key'] || $parent->role !== 'reader' || $parent->output_type !== 'ReaderLead') {
                throw new InvalidArgumentException("Artifact storico non e' una ReaderLead del target: {$parent->id}");
            }
            $payload = $this->normalizePayload((array) $parent->payload, (string) $row['case_id']);
            $registry->record([
                'project_key' => $parent->project_key, 'category' => $parent->category,
                'source_commit' => $parent->source_commit, 'parent_artifact_id' => $parent->id,
                'role' => 'reader', 'output_type' => 'ReaderLead', 'model' => 'benchmark:frozen-reader-lead',
                'payload' => $payload, 'usage' => $parent->usage, 'accepted' => true,
                'is_canonical' => true, 'label' => $row['label'], 'matched_case_id' => $row['case_id'],
                'label_set_version' => $dataset, 'label_notes' => 'Frozen clone; historical payload is immutable.',
                'metrics' => ['dataset' => $dataset, 'oracle' => $row['oracle'], 'provenance' => ['type' => 'historical_reader_artifact', 'artifact_id' => $parent->id]],
            ]);
        }
        foreach ((array) ($spec['curated_negatives'] ?? []) as $row) {
            $registry->record([
                'project_key' => $spec['project_key'], 'category' => $row['category'], 'source_commit' => $spec['source_commit'],
                'role' => 'reader', 'output_type' => 'ReaderLead', 'model' => 'benchmark:curated-negative',
                'payload' => ['output' => $row['lead'], 'source_refs' => $row['source_refs']], 'accepted' => true,
                'is_canonical' => true, 'label' => 'false_positive', 'label_set_version' => $dataset,
                'label_notes' => 'Curated static hard negative; oracle verified in dataset definition.',
                'metrics' => ['dataset' => $dataset, 'oracle' => $row['oracle'], 'provenance' => ['type' => 'curated_negative', 'definition' => basename($definition)]],
            ]);
        }
        $this->info("Frozen dataset {$dataset} created.");
        return self::SUCCESS;
    }

    /** Deterministic compatibility adapter for pre-flat ReaderLead artifacts only. */
    private function normalizePayload(array $payload, string $subject): array
    {
        $output = (array) ($payload['output'] ?? []);
        $refs = array_values(array_filter((array) ($payload['source_refs'] ?? []), 'is_array'));
        foreach (['title', 'hypothesis', 'suspicious_operation', 'initial_evidence'] as $key) {
            if (trim((string) ($output[$key] ?? '')) === '') {
                throw new InvalidArgumentException("Artifact storico {$subject} non normalizzabile: manca {$key}.");
            }
        }
        $output['owasp_category'] ??= 'A05:2025 Injection';
        $output['primary_file'] ??= (string) data_get($refs, '0.file', '');
        $output['primary_line'] ??= (int) data_get($refs, '0.start_line', 1);
        $output['source_ref_ids'] = array_values(array_filter(array_map(
            static fn (array $ref): ?string => isset($ref['source_ref_id']) ? (string) $ref['source_ref_id'] : null,
            $refs,
        )));
        if ($refs === [] || $output['source_ref_ids'] === []) {
            throw new InvalidArgumentException("Artifact storico {$subject} non ha source refs utilizzabili.");
        }
        $payload['output'] = Arr::only($output, ['title', 'hypothesis', 'owasp_category', 'suspicious_operation', 'primary_file', 'primary_line', 'source_ref_ids', 'initial_evidence', 'unknowns', 'coverage_delta', 'next_focus']);
        $payload['source_refs'] = $refs;
        return $payload;
    }
}
