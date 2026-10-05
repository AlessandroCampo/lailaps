<?php

use App\Enums\AuditRunStatus;
use App\Models\AuditRun;
use App\Models\User;
use App\Services\Audit\AuditRunFinalizer;
use App\Services\Audit\RunStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('preserves Worker batch results and refreshes recovery counters idempotently', function (): void {
    $id = 'worker-finalization-test-'.bin2hex(random_bytes(4));
    $storage = app(RunStorage::class);
    $root = $storage->forId('yeswiki', ['injection'], $id);
    $storage->writeOutcome($root, $id, [
        'publication_state' => 'final', 'termination_reason' => 'worker_batch_complete_with_errors',
        'worker_batch' => ['attempted_count' => 3, 'terminal_decision_count' => 2,
            'technical_failure_count' => 1, 'not_run_count' => 0, 'completed' => true],
        'environment' => ['state' => 'valid'],
    ], ['mode' => 'worker_checkpoint_batch', 'completed' => 3, 'episodes' => [['artifact_id' => 'saved']]]);
    $run = AuditRun::query()->create([
        'id' => (string) Str::ulid(), 'user_id' => User::factory()->create()->id,
        'audit_id' => $id, 'type' => 'benchmark', 'status' => AuditRunStatus::Failed,
        'parameters' => ['benchmark_id' => 'yeswiki', 'categories' => ['injection']],
        'run_path' => $root, 'exit_code' => 1,
    ]);
    $finalizer = app(AuditRunFinalizer::class);
    $finalizer->finalize($run);
    $finalizer->finalize($run->fresh());
    $benchmark = $storage->outcome($root, $id)['benchmark'];
    expect($benchmark['mode'])->toBe('worker_checkpoint_batch')
        ->and($benchmark['episodes'])->toBe([['artifact_id' => 'saved']])
        ->and($benchmark['attempted'])->toBe(3)
        ->and($benchmark['terminal_decisions'])->toBe(2)
        ->and($benchmark['technical_failures'])->toBe(1)
        ->and($benchmark['not_run'])->toBe(0)
        ->and($benchmark['completed_all'])->toBeTrue()
        ->and($benchmark['termination_reason'])->toBe('worker_batch_complete_with_errors');
});
