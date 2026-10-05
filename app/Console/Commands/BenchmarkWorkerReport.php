<?php

namespace App\Console\Commands;

use App\Models\BenchmarkStageArtifact;
use Illuminate\Console\Command;

final class BenchmarkWorkerReport extends Command
{
    protected $signature = 'benchmark:worker:report {target-id} {--dataset=worker-gold-v1} {--model=} {--signature= : Comparison signature da analizzare}';

    protected $description = 'Reports Worker decision accuracy, evidence sufficiency, abstention, cost and stability';

    public function handle(): int
    {
        $subjects = BenchmarkStageArtifact::query()->where('project_key', $this->argument('target-id'))
            ->where('role', 'confirmer')->where('output_type', 'CandidateHandoff')
            ->where('label_set_version', (string) $this->option('dataset'))->get()->keyBy('id');
        $runs = BenchmarkStageArtifact::query()->where('project_key', $this->argument('target-id'))
            ->where('role', 'worker')->whereIn('parent_artifact_id', $subjects->keys())
            ->when($this->option('signature'), fn ($query) => $query->where('configuration_signature', $this->option('signature')))
            ->when($this->option('model'), fn ($query) => $query->where('model', $this->option('model')))->get();
        $signatures = $runs->pluck('configuration_signature')->filter()->unique()->values();
        if ($signatures->count() > 1 && ! $this->option('signature')) {
            $this->line(json_encode(['comparison_signatures' => $signatures, 'requires_signature_selection' => true], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        $valid = $runs->where('status', 'valid');
        $classified = $valid->filter(fn ($row) => ! data_get($row->metrics, 'unclassified', false)
            && data_get($row->metrics, 'proposal_correct') !== null);
        $terminal = $valid->whereIn('output_type', [
            'JudgeConfirmedDecision', 'JudgeRejectedDecision', 'JudgeSuspectedDecision',
            'JudgeBlockedDecision', 'JudgeRequestConfirmerDecision',
            // Historical Worker-only benchmark artifacts remain reportable.
            'ConfirmedDecision', 'RejectedDecision', 'BlockedDecision', 'SuspectedDecision', 'NeedsInfoDecision',
        ]);
        $rate = static fn ($rows, string $key): ?float => $rows->isEmpty() ? null : round(100 * $rows->avg(fn ($row) => (bool) data_get($row->metrics, $key)), 2);
        $matrix = $subjects->map(function ($subject) use ($runs): array {
            $rows = $runs->where('parent_artifact_id', $subject->id);

            return [
                'subject' => $subject->id, 'case_id' => $subject->matched_case_id,
                'runs' => $rows->count(), 'valid' => $rows->where('status', 'valid')->count(),
                'technical_failures' => $rows->where('status', 'technical_failure')->count(),
                'proposals' => $rows->pluck('metrics.proposal_decision')->values(),
                'evidence' => $rows->pluck('metrics.evidence_sufficiency')->values(),
                'stable' => $rows->where('status', 'valid')->pluck('metrics.proposal_decision')->unique()->count() <= 1,
            ];
        })->values();
        $report = [
            'dataset' => $this->option('dataset'), 'total_runs' => $runs->count(),
            'semantic_denominator' => $classified->count(),
            'unclassified' => $valid->count() - $classified->count(),
            'terminal_decisions' => $terminal->count(),
            'terminal_proposals' => $terminal->count(),
            'technical_failures' => $runs->where('status', 'technical_failure')->count(),
            'decision_accuracy_pct' => $rate($classified, 'proposal_correct'),
            'proposal_accuracy_pct' => $rate($classified, 'proposal_correct'),
            'full_credit_pct' => $rate($classified, 'full_credit'),
            'sufficient_evidence_pct' => $valid->isEmpty() ? null : round(100 * $valid->where('metrics.evidence_sufficiency', 'sufficient')->count() / $valid->count(), 2),
            'partial_evidence' => $valid->where('metrics.evidence_sufficiency', 'partial')->count(),
            'absent_evidence' => $valid->where('metrics.evidence_sufficiency', 'absent')->count(),
            'abstentions' => $valid->filter(fn ($row) => (bool) data_get($row->metrics, 'abstained'))->count(),
            'http_requests' => $runs->sum(fn ($row) => (int) data_get($row->metrics, 'http_requests', 0)),
            'economic_points' => $runs->sum(fn ($row) => (float) data_get($row->metrics, 'economic_points', 0)),
            'comparison_signatures' => $runs->pluck('metrics.comparison_signature')->filter()->unique()->values(),
            'matrix' => $matrix,
        ];
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
