<?php

use App\Enums\AuditRunStatus;
use App\Models\AuditRun;
use App\Models\User;
use App\Services\Audit\AuditRunFinalizer;
use App\Services\Audit\RunStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('recovers a partial benchmark after a terminal outer-run path', function (AuditRunStatus $status, int $exitCode, string $reason): void {
    $id = 'yeswiki-injection-test-'.bin2hex(random_bytes(4));
    $storage = app(RunStorage::class);
    $root = $storage->forId('yeswiki', ['injection'], $id);
    $storage->writeOutcome($root, $id, [
        'schema_version' => 8,
        'publication_state' => 'partial',
        'outcome' => 'incomplete',
        'confirmed' => [], 'suspected' => [], 'rejected_inconclusive' => [],
        'files_seen' => [], 'source_observations' => [],
        'termination_reason' => $reason,
        'telemetry' => ['total_tokens' => 123],
        'models' => [
            ['role' => 'reader', 'requested_model' => 'deepseek/deepseek-v4-flash-0731', 'effective_model' => 'deepseek/deepseek-v4-flash-0731', 'reasoning_effort' => 'medium'],
            ['role' => 'reviewer', 'requested_model' => 'deepseek/deepseek-v4-flash-0731', 'effective_model' => 'deepseek/deepseek-v4-flash-0731', 'reasoning_effort' => 'low'],
            ['role' => 'confirmer', 'requested_model' => 'meta/muse-spark-1.2-contributor', 'effective_model' => 'meta/muse-spark-1.2-contributor', 'reasoning_effort' => 'high'],
            ['role' => 'worker', 'requested_model' => 'meta/muse-spark-1.2-contributor', 'effective_model' => 'meta/muse-spark-1.2-contributor', 'reasoning_effort' => 'high'],
        ],
        'environment' => ['state' => 'valid'],
    ], null);

    $initialStatus = $status === AuditRunStatus::Cancelled ? AuditRunStatus::Completed : $status;
    $run = AuditRun::query()->create([
        'id' => (string) Str::ulid(), 'user_id' => User::factory()->create()->id,
        'audit_id' => $id, 'type' => 'benchmark', 'status' => $initialStatus,
        'parameters' => ['type' => 'benchmark', 'benchmark_id' => 'yeswiki', 'categories' => ['A05:2025 Injection']],
        'run_path' => $root, 'exit_code' => $exitCode,
    ]);

    app(AuditRunFinalizer::class)->finalize($run);
    if ($status === AuditRunStatus::Cancelled) {
        $run->update(['status' => AuditRunStatus::Cancelled, 'exit_code' => $exitCode]);
        app(AuditRunFinalizer::class)->finalize($run->fresh());
    }

    $benchmark = $storage->outcome($root, $id)['benchmark'];
    expect($benchmark['artifact_state'])->toBe('partial')
        ->and($benchmark['run_state'])->toBe($status->value)
        ->and($benchmark['environment_state'])->toBe('valid')
        ->and($benchmark['termination_reason'])->toBe($reason)
        ->and($benchmark['score']['normalized'])->toBe(0)
        ->and($benchmark['score']['max_points'])->toBe(30)
        ->and($benchmark['cases'])->toHaveCount(7)
        ->and($run->evaluations()->count())->toBe(1)
        ->and($run->models()->count())->toBe(4)
        ->and($run->models()->where('role', 'reviewer')->first()->reasoning_effort)->toBe('low')
        ->and($run->evaluations()->first()->artifact_state)->toBe('partial');
})->with([
    'non-zero exit' => [AuditRunStatus::Failed, 1, 'model_exit_non_zero'],
    'timeout' => [AuditRunStatus::Failed, 124, 'agent_timeout'],
    'cancellation' => [AuditRunStatus::Cancelled, 130, 'cancelled'],
]);

it('refreshes the same evaluation when a partial report becomes final', function (): void {
    $id = 'yeswiki-injection-test-'.bin2hex(random_bytes(4));
    $storage = app(RunStorage::class);
    $root = $storage->forId('yeswiki', ['injection'], $id);
    $partial = [
        'schema_version' => 8, 'publication_state' => 'partial', 'outcome' => 'incomplete',
        'confirmed' => [], 'suspected' => [], 'rejected_inconclusive' => [],
        'files_seen' => [], 'source_observations' => [],
        'termination_reason' => 'economic_budget_exhausted',
        'environment' => ['state' => 'valid'],
    ];
    $storage->writeOutcome($root, $id, $partial, null);
    $run = AuditRun::query()->create([
        'id' => (string) Str::ulid(), 'user_id' => User::factory()->create()->id,
        'audit_id' => $id, 'type' => 'benchmark', 'status' => AuditRunStatus::Completed,
        'parameters' => ['type' => 'benchmark', 'benchmark_id' => 'yeswiki', 'categories' => ['injection']],
        'run_path' => $root, 'exit_code' => 0,
    ]);

    $finalizer = app(AuditRunFinalizer::class);
    $finalizer->finalize($run);
    expect($run->evaluations()->first()->artifact_state)->toBe('partial');

    $partial['publication_state'] = 'final';
    $storage->writeOutcome($root, $id, $partial, $storage->outcome($root, $id)['benchmark']);
    $finalizer->finalize($run->fresh());
    $benchmark = $storage->outcome($root, $id)['benchmark'];

    expect($run->evaluations()->count())->toBe(1)
        ->and($run->evaluations()->first()->artifact_state)->toBe('final')
        ->and($benchmark['artifact_state'])->toBe('final');
});
