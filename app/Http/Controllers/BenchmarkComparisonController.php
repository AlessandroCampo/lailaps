<?php

namespace App\Http\Controllers;

use App\Models\AuditRunModel;
use App\Models\BenchmarkEvaluation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

final class BenchmarkComparisonController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $query = BenchmarkEvaluation::query()
            ->whereHas('run', fn (Builder $query) => $query->where('user_id', $request->user()->id))
            ->with(['run.models', 'run.experiment']);
        foreach (['target' => 'target_id', 'category' => 'category', 'status' => 'status', 'artifact' => 'artifact_state', 'environment' => 'environment_state'] as $input => $column) {
            if ($request->filled($input)) {
                $query->where($column, (string) $request->input($input));
            }
        }
        if (! $request->filled('environment')) {
            $query->where('environment_state', '!=', 'invalid');
        }
        if ($request->filled('experiment')) {
            $query->whereHas('run', fn (Builder $query) => $query->where('benchmark_experiment_id', $request->input('experiment')));
        }
        if ($request->filled('revision')) {
            $query->whereHas('run', fn (Builder $query) => $query->where('harness_revision', $request->input('revision')));
        }
        foreach (['reader', 'worker', 'confirmer'] as $role) {
            if ($request->filled($role)) {
                $query->whereHas('run.models', fn (Builder $query) => $query->where('role', $role)->where('effective_model', $request->input($role)));
            }
        }
        if ($request->filled('from')) {
            $query->whereDate('evaluated_at', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('evaluated_at', '<=', $request->input('to'));
        }
        $evaluations = $query->latest('evaluated_at')->limit(1000)->get();

        return Inertia::render('audits/Compare', [
            'groups' => $this->groups($evaluations),
            'filters' => $request->only(['target', 'category', 'status', 'artifact', 'environment', 'experiment', 'revision', 'reader', 'worker', 'confirmer', 'from', 'to']),
            'options' => $this->options($request),
        ]);
    }

    /** @param Collection<int, BenchmarkEvaluation> $evaluations @return list<array<string, mixed>> */
    private function groups(Collection $evaluations): array
    {
        return $evaluations->groupBy(function (BenchmarkEvaluation $evaluation): string {
            $models = $evaluation->run->models->pluck('effective_model', 'role');

            return implode('|', [$evaluation->benchmark_id, $evaluation->target_id, $evaluation->category,
                $evaluation->run->harness_revision, $evaluation->run->target_commit,
                data_get($evaluation->payload, 'comparison_signature', 'unknown-budget'),
                $models['reader'] ?? 'unknown', $models['worker'] ?? 'unknown', $models['confirmer'] ?? 'unknown']);
        })->map(function (Collection $items, string $key): array {
            /** @var BenchmarkEvaluation $first */
            $first = $items->first();
            $models = $first->run->models->pluck('effective_model', 'role');

            return [
                'key' => hash('sha256', $key), 'target' => $first->target_id, 'category' => $first->category,
                'harnessRevision' => $first->run->harness_revision, 'targetCommit' => $first->run->target_commit,
                'models' => ['reader' => $models['reader'] ?? null, 'worker' => $models['worker'] ?? null, 'confirmer' => $models['confirmer'] ?? null],
                'n' => $items->count(),
                'status' => $items->every(fn (BenchmarkEvaluation $item) => $item->status === 'scored') ? 'scored' : 'provisional',
                'completionRate' => $items->filter(fn (BenchmarkEvaluation $item) => $item->run->status->value === 'completed')->count() / max(1, $items->count()),
                'metrics' => [
                    'globalScore' => $this->summary($items->pluck('global_score')),
                    'fileRecall' => $this->summary($items->pluck('file_recall')),
                    'anchorRecall' => $this->summary($items->pluck('anchor_recall')),
                    'detectionRecall' => $this->summary($items->pluck('detection_recall')),
                    'staticValidationRecall' => $this->summary($items->pluck('static_validation_recall')),
                    'confirmationRecall' => $this->summary($items->pluck('confirmation_recall')),
                    'confirmationF1' => $this->summary($items->pluck('confirmation_f1')),
                    'totalTokens' => $this->summary($items->pluck('total_tokens')),
                    'providerCostUsd' => $this->summary($items->pluck('provider_cost_usd')),
                    'readerCacheHitRate' => $this->summary($items->map(fn (BenchmarkEvaluation $evaluation): float => (float) data_get($evaluation->cost, 'reader_cache_hit_rate', 0))),
                    'readerCandidatesPer1kTokens' => $this->summary($items->map(fn (BenchmarkEvaluation $evaluation): float => (float) data_get($evaluation->cost, 'reader_candidates_per_1k_tokens', 0))),
                    'tokensPerConfirmedTp' => $this->summary($items->map(function (BenchmarkEvaluation $evaluation): ?float {
                        $tp = (int) data_get($evaluation->confirmation, 'tp', 0);

                        return $tp > 0 ? $evaluation->total_tokens / $tp : null;
                    })->filter(fn ($value) => $value !== null)),
                    'durationSeconds' => $this->summary($items->map(fn (BenchmarkEvaluation $evaluation): ?int => $evaluation->run->started_at && $evaluation->run->finished_at ? (int) $evaluation->run->started_at->diffInSeconds($evaluation->run->finished_at) : null)->filter(fn ($value) => $value !== null)),
                ],
                'runs' => $items->map(fn (BenchmarkEvaluation $item): array => [
                    'evaluationId' => $item->id, 'runId' => $item->run->id, 'auditId' => $item->run->audit_id,
                    'experimentId' => $item->run->benchmark_experiment_id, 'experiment' => $item->run->experiment?->name,
                    'repetition' => $item->run->repetition, 'status' => $item->status,
                    'artifactState' => $item->artifact_state, 'environmentState' => $item->environment_state,
                    'score' => $item->global_score,
                    'pending' => $item->pending_adjudications, 'detection' => $item->detection,
                    'staticValidation' => $item->static_validation, 'confirmation' => $item->confirmation, 'cost' => $item->cost,
                ])->values(),
            ];
        })->values()->all();
    }

    /** @param Collection<int, mixed> $values @return array{median: float|null, q1: float|null, q3: float|null} */
    private function summary(Collection $values): array
    {
        $sorted = $values->filter(fn ($value) => is_numeric($value))->map(fn ($value): float => (float) $value)->sort()->values();
        if ($sorted->isEmpty()) {
            return ['median' => null, 'q1' => null, 'q3' => null];
        }

        return ['median' => $this->percentile($sorted, .5), 'q1' => $this->percentile($sorted, .25), 'q3' => $this->percentile($sorted, .75)];
    }

    /** @param Collection<int, float> $values */
    private function percentile(Collection $values, float $percentile): float
    {
        $position = ($values->count() - 1) * $percentile;
        $lower = (int) floor($position);
        $upper = (int) ceil($position);
        if ($lower === $upper) {
            return (float) $values[$lower];
        }

        return (float) $values[$lower] + ((float) $values[$upper] - (float) $values[$lower]) * ($position - $lower);
    }

    /** @return array<string, mixed> */
    private function options(Request $request): array
    {
        $base = BenchmarkEvaluation::query()->whereHas('run', fn (Builder $query) => $query->where('user_id', $request->user()->id));
        $models = fn (string $role): array => AuditRunModel::query()->where('role', $role)->whereHas('run', fn (Builder $query) => $query->where('user_id', $request->user()->id))->whereNotNull('effective_model')->distinct()->orderBy('effective_model')->pluck('effective_model')->all();

        return [
            'targets' => (clone $base)->distinct()->orderBy('target_id')->pluck('target_id')->all(),
            'categories' => (clone $base)->distinct()->orderBy('category')->pluck('category')->all(),
            'statuses' => (clone $base)->distinct()->orderBy('status')->pluck('status')->all(),
            'artifacts' => (clone $base)->distinct()->orderBy('artifact_state')->pluck('artifact_state')->all(),
            'environments' => (clone $base)->distinct()->orderBy('environment_state')->pluck('environment_state')->all(),
            'revisions' => $request->user()->auditRuns()->whereNotNull('harness_revision')->distinct()->pluck('harness_revision')->all(),
            'experiments' => $request->user()->benchmarkExperiments()->orderByDesc('created_at')->pluck('name', 'id')->all(),
            'readers' => $models('reader'), 'workers' => $models('worker'), 'confirmers' => $models('confirmer'),
        ];
    }
}
