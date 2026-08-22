<?php

namespace App\Services\Audit;

use App\Models\AuditRun;
use App\Models\BenchmarkEvaluation;
use App\Services\Pentest\BenchmarkCatalog;
use App\Services\Pentest\BenchmarkEvaluator;
use App\Services\Pentest\BenchmarkManifest;
use App\Services\Pentest\BenchmarkResultAggregator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

final class AuditRunFinalizer
{
    public function __construct(
        private readonly BenchmarkCatalog $catalog,
        private readonly BenchmarkEvaluator $evaluator,
        private readonly BenchmarkResultAggregator $aggregator,
        private readonly RunStorage $storage,
    ) {}

    public function finalize(AuditRun $run): void
    {
        $root = rtrim((string) $run->run_path, '/\\');
        $targetId = (string) ($run->parameters['benchmark_id'] ?? $run->parameters['preset'] ?? '');
        $outcome = $this->storage->outcome($run);
        $benchmark = is_array($outcome['benchmark'] ?? null) ? $outcome['benchmark'] : null;
        if ($targetId !== '') {
            $benchmark = $this->recoverBenchmark($run, $targetId, $root, $benchmark) ?? $benchmark;
        }
        if ($benchmark !== null && $targetId !== '') {
            $benchmark = $this->refreshBenchmarkLifecycle($run, $targetId, $root, $benchmark);
        }
        $descriptor = $targetId !== '' ? $this->catalog->descriptor($targetId) : null;
        $revision = $this->git(['rev-parse', 'HEAD']);
        $dirty = $this->git(['status', '--porcelain', '--untracked-files=no']) !== '';
        $run->update([
            'harness_revision' => $revision ?: null,
            'target_commit' => $descriptor?->commit(),
            'reproducible' => ! $dirty,
        ]);
        if ($benchmark !== null && Schema::hasTable('benchmark_evaluations')) {
            $this->storage->writeOutcome($root, $run->audit_id, is_array($outcome['report'] ?? null) ? $outcome['report'] : null, $benchmark);
            $this->projectBenchmark($run, $benchmark, $this->storage->outcomePath($root, $run->audit_id));
        }
        if ($run->experiment !== null) {
            $experiment = $run->experiment;
            $terminal = ['completed', 'failed', 'cancelled'];
            $remaining = $experiment->runs()->whereNotIn('status', $terminal)->count();
            $experiment->update([
                'status' => $remaining === 0
                    ? ($experiment->runs()->where('status', 'failed')->exists() ? 'completed_with_failures' : 'completed')
                    : 'running',
                'started_at' => $experiment->started_at ?? now(),
                'finished_at' => $remaining === 0 ? now() : null,
            ]);
        }
    }

    /** @param array<string, mixed> $benchmark @return array<string, mixed> */
    private function refreshBenchmarkLifecycle(AuditRun $run, string $targetId, string $root, array $benchmark): array
    {
        if (isset($benchmark['by_category']) && is_array($benchmark['by_category'])) {
            $results = [];
            foreach ($benchmark['by_category'] as $slug => $result) {
                if (! is_array($result)) {
                    continue;
                }
                $result['run_state'] = $run->status->value;
                $environment = $this->environmentState($root, $run->audit_id);
                if ($environment !== 'unknown') {
                    $result['environment_state'] = $environment;
                }
                $results[(string) $slug] = $result;
            }
            $benchmark = $this->aggregator->aggregate($targetId, $run->audit_id, $results, [
                'run_state' => $run->status->value,
            ]);
        } else {
            $benchmark['run_state'] = $run->status->value;
            $environment = $this->environmentState($root, $run->audit_id);
            if ($environment !== 'unknown') {
                $benchmark['environment_state'] = $environment;
            }
        }
        $outcome = $this->storage->outcome($root, $run->audit_id);
        $this->storage->writeOutcome($root, $run->audit_id, is_array($outcome['report'] ?? null) ? $outcome['report'] : null, $benchmark);

        return $benchmark;
    }

    /** @return array<string, mixed>|null */
    private function recoverBenchmark(AuditRun $run, string $targetId, string $root, ?array $previous = null): ?array
    {
        $source = base_path('targets/'.$targetId);
        if (! is_dir($source)) {
            return null;
        }
        $requested = array_values(array_filter(array_map(
            fn (mixed $value): string => strtolower(trim((string) $value)),
            (array) ($run->parameters['categories'] ?? []),
        )));
        $results = [];
        $outcome = $this->storage->outcome($root, $run->audit_id);
        foreach ($this->catalog->forTarget($targetId) as $manifest) {
            if ($requested !== [] && ! $this->requestedManifest($manifest, $requested)) {
                continue;
            }
            $slug = $manifest->benchmarkCategory() ?: strtolower($manifest->categoryId());
            $hasReport = is_array($outcome['report'] ?? null);
            if ($hasReport) {
                $results[$slug] = $this->evaluator->evaluate($root, $manifest, $source, [
                    'run_state' => $run->status->value,
                    'environment_state' => $this->environmentState($root, $run->audit_id),
                ]);
                $results[$slug]['audit_id'] = $run->audit_id;

                continue;
            }
            $previousCategory = data_get($previous, 'by_category.'.$slug);
            $existing = is_array($previousCategory) ? $previousCategory : null;
            if ($existing !== null && ($existing['artifact_state'] ?? 'missing') !== 'missing') {
                $existing['run_state'] = $run->status->value;
                $childEnvironment = $this->environmentState($root, $run->audit_id);
                if ($childEnvironment !== 'unknown') {
                    $existing['environment_state'] = $childEnvironment;
                }
                $results[$slug] = $existing;

                continue;
            }
            $results[$slug] = $this->evaluator->missingResult($manifest, [
                'run_state' => $run->status->value,
                'environment_state' => $this->environmentState($root, $run->audit_id),
            ]);
        }
        $benchmark = $this->aggregator->aggregate($targetId, $run->audit_id, $results, [
            'run_state' => $run->status->value,
        ]);
        if (! is_dir($root) && ! mkdir($root, 0755, true) && ! is_dir($root)) {
            return null;
        }
        $this->storage->writeOutcome($root, $run->audit_id, is_array($outcome['report'] ?? null) ? $outcome['report'] : null, $benchmark);

        return $benchmark;
    }

    /** @param list<string> $requested */
    private function requestedManifest(BenchmarkManifest $manifest, array $requested): bool
    {
        $values = [
            strtolower($manifest->categoryId()),
            strtolower((string) $manifest->benchmarkCategory()),
            strtolower((string) ($manifest->data['category']['name'] ?? '')),
            strtolower($manifest->auditCategory()),
        ];
        foreach ($requested as $request) {
            $generic = strtolower(trim(explode(':', $request, 2)[0]));
            $genericRequest = preg_match('/^a\d{2}(?::\d{4})?$/', $request) === 1;
            if (in_array($request, $values, true) || ($genericRequest && $generic === strtolower($manifest->categoryId()))) {
                return true;
            }
        }

        return false;
    }

    private function environmentState(string $artifactRoot, string $runId): string
    {
        $outcome = $this->storage->outcome($artifactRoot, $runId);

        return (string) data_get($outcome, 'report.environment.state', 'unknown');
    }

    /** @param array<string, mixed> $benchmark */
    private function projectBenchmark(AuditRun $run, array $benchmark, string $path): void
    {
        $results = isset($benchmark['by_category']) ? (array) $benchmark['by_category'] : [$benchmark];
        DB::transaction(function () use ($run, $results, $path): void {
            foreach ($results as $result) {
                if (! is_array($result) || ! isset($result['benchmark_id'])) {
                    continue;
                }
                $checksum = hash('sha256', (string) file_get_contents($path).(string) $result['benchmark_id']);
                $evaluation = BenchmarkEvaluation::query()->updateOrCreate([
                    'audit_run_id' => $run->id,
                    'benchmark_id' => (string) $result['benchmark_id'],
                    'evaluator_version' => (string) ($result['evaluator_version'] ?? BenchmarkEvaluator::VERSION),
                ], [
                    'artifact_checksum' => $checksum,
                    'target_id' => (string) ($result['target_id'] ?? $run->parameters['benchmark_id'] ?? 'unknown'),
                    'category' => (string) ($result['benchmark_category'] ?? data_get($result, 'category.id', 'unknown')),
                    'status' => (string) ($result['status'] ?? 'incomplete'),
                    'oracle_version' => data_get($result, 'oracle.version'),
                    'artifact_state' => (string) ($result['artifact_state'] ?? 'unknown'),
                    'run_state' => (string) ($result['run_state'] ?? $run->status->value),
                    'adjudication_state' => (string) ($result['adjudication_state'] ?? 'provisional'),
                    'environment_state' => (string) ($result['environment_state'] ?? 'unknown'),
                    'reach' => (array) ($result['reach'] ?? []),
                    'detection' => (array) ($result['detection'] ?? []),
                    'static_validation' => (array) ($result['static_validation'] ?? []),
                    'confirmation' => (array) ($result['confirmation'] ?? []),
                    'cost' => (array) ($result['cost'] ?? []),
                    'payload' => $result,
                    'pending_adjudications' => (int) ($result['pending_adjudications'] ?? 0),
                    'global_score' => data_get($result, 'score.normalized'),
                    'adjudicated_score' => data_get($result, 'adjudicated_score.normalized'),
                    'file_recall' => (float) data_get($result, 'reach.file_reached.recall', 0),
                    'anchor_recall' => (float) data_get($result, 'reach.anchor_reached.recall', 0),
                    'detection_recall' => (float) data_get($result, 'detection.recall', 0),
                    'static_validation_recall' => (float) data_get($result, 'static_validation.recall', 0),
                    'confirmation_recall' => (float) data_get($result, 'confirmation.recall', 0),
                    'confirmation_f1' => (float) data_get($result, 'confirmation.f1', 0),
                    'total_tokens' => (int) data_get($result, 'cost.total_tokens', 0),
                    'provider_cost_usd' => (float) data_get($result, 'cost.provider_cost_usd', 0),
                    'evaluated_at' => now(),
                ]);
                foreach ((array) ($result['cases'] ?? []) as $case) {
                    if (! is_array($case) || ! isset($case['id'])) {
                        continue;
                    }
                    $evaluation->cases()->updateOrCreate(['case_id' => (string) $case['id']], [
                        'expected_vulnerable' => (bool) ($case['expected_vulnerable'] ?? false),
                        'stage' => (string) ($case['stage'] ?? 'not_reached'),
                        'stage_score' => (float) ($case['stage_score'] ?? 0),
                        'score_contribution' => (float) ($case['score_contribution'] ?? 0),
                        'reach' => (array) ($case['reach'] ?? []),
                        'milestones' => (array) ($case['milestones'] ?? []),
                        'detection' => (string) ($case['detection'] ?? 'unknown'),
                        'suspected' => (string) ($case['suspected'] ?? $case['detection'] ?? 'unknown'),
                        'static_validation' => (string) ($case['static_validation'] ?? 'unknown'),
                        'confirmation' => (string) ($case['confirmation'] ?? 'unknown'),
                        'dynamically_confirmed' => (string) ($case['dynamically_confirmed'] ?? $case['confirmation'] ?? 'unknown'),
                        'match_mode' => (string) ($case['match_mode'] ?? 'none'),
                        'matched_findings' => (array) ($case['matched_findings'] ?? []),
                        'payload' => $case,
                    ]);
                }
            }
        });
    }

    /** @param list<string> $arguments */
    private function git(array $arguments): string
    {
        $process = new Process(['git', ...$arguments], base_path(), timeout: 10);
        $process->run();

        return $process->isSuccessful() ? trim($process->getOutput()) : '';
    }
}
