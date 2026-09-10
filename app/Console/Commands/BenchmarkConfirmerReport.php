<?php

namespace App\Console\Commands;

use App\Models\BenchmarkStageArtifact;
use Illuminate\Console\Command;

final class BenchmarkConfirmerReport extends Command
{
    protected $signature = 'benchmark:confirmer:report {target-id} {--dataset=confirmer-gold-v1} {--model=}';
    protected $description = 'Reports Confirmer classification, evidence quality, cost and stability for a frozen dataset';

    public function handle(): int
    {
        $subjects = BenchmarkStageArtifact::query()->where('project_key', $this->argument('target-id'))
            ->where('role', 'reader')->where('output_type', 'ReaderLead')
            ->where('label_set_version', (string) $this->option('dataset'))->get()->keyBy('id');
        $runs = BenchmarkStageArtifact::query()->where('project_key', $this->argument('target-id'))
            ->where('role', 'confirmer')->whereIn('parent_artifact_id', $subjects->keys())
            ->when($this->option('model'), fn ($query) => $query->where('model', $this->option('model')))->get();
        $valid = $runs->where('status', 'valid')->filter(fn ($run) => in_array($run->output_type, ['CandidateHandoff', 'LeadClosure'], true));
        $positive = $valid->filter(fn ($run) => data_get($subjects[$run->parent_artifact_id]?->metrics, 'oracle.expected_decision') === 'CandidateHandoff');
        $negative = $valid->filter(fn ($run) => data_get($subjects[$run->parent_artifact_id]?->metrics, 'oracle.expected_decision') === 'LeadClosure');
        $rate = static fn ($rows): ?float => $rows->isEmpty() ? null : round(100 * $rows->avg(fn ($r) => (bool) data_get($r->metrics, 'classification_correct')), 2);
        $recall = $rate($positive); $specificity = $rate($negative);
        $quality = [];
        foreach (['source', 'propagation', 'sink', 'controllable_input', 'reachability', 'verification_plan', 'closure_basis', 'barrier_anchors'] as $dimension) {
            $rows = $valid->filter(fn ($run) => data_get($run->metrics, "quality_dimensions.{$dimension}") !== null);
            if ($rows->isNotEmpty()) $quality[$dimension] = $rate($rows);
        }
        $matrix = $subjects->map(function ($subject) use ($runs): array {
            $rows = $runs->where('parent_artifact_id', $subject->id);
            return ['subject' => $subject->id, 'model' => $rows->pluck('model')->unique()->values(), 'decisions' => $rows->pluck('output_type')->values(), 'classification' => $rows->pluck('metrics.classification_correct')->values(), 'quality' => $rows->pluck('metrics.quality_score')->values(), 'economic_points' => $rows->sum(fn ($r) => (float) data_get($r->metrics, 'economic_points', 0)), 'technical_failures' => $rows->where('status', 'technical_failure')->count(), 'stable' => $rows->where('status', 'valid')->pluck('output_type')->unique()->count() <= 1];
        })->values();
        $report = ['dataset' => $this->option('dataset'), 'semantic_denominator' => $valid->count(), 'technical_failures' => $runs->where('status', 'technical_failure')->count(), 'recall_positive_pct' => $recall, 'specificity_negative_pct' => $specificity, 'balanced_accuracy_pct' => $recall === null || $specificity === null ? null : round(($recall + $specificity) / 2, 2), 'quality_pct' => $quality, 'retry_or_output_failures' => $runs->where('output_type', 'ConfirmerRun')->count(), 'points_per_correct_decision' => ($correct = $valid->filter(fn ($r) => data_get($r->metrics, 'classification_correct'))->count()) ? round($valid->sum(fn ($r) => (float) data_get($r->metrics, 'economic_points', 0)) / $correct, 2) : null, 'matrix' => $matrix];
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return self::SUCCESS;
    }
}
