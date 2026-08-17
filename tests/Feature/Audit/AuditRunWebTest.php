<?php

use App\Enums\AuditRunStatus;
use App\Jobs\ExecuteAuditRun;
use App\Models\AuditRun;
use App\Models\User;
use App\Services\Audit\AuditEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function webAuditRun(User $user, array $overrides = []): AuditRun
{
    $id = (string) Str::ulid();

    return AuditRun::query()->create(array_replace([
        'id' => $id,
        'user_id' => $user->id,
        'audit_id' => strtolower($id),
        'type' => 'audit',
        'status' => AuditRunStatus::Queued,
        'parameters' => ['type' => 'audit', 'preset' => 'dvwa', 'ttl' => 1800],
        'artifact_path' => storage_path('framework/testing/audits/'.$id.'/artifacts'),
    ], $overrides));
}

test('an authenticated user can queue a preset audit', function () {
    Queue::fake();
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('audits.store'), [
        'type' => 'audit',
        'preset' => 'dvwa',
        'target_mode' => 'sandbox',
        'categories' => ['A01:2025 Broken Access Control'],
        'ttl' => 1800,
        'test' => true,
        'keep' => false,
    ]);

    $run = AuditRun::query()->firstOrFail();
    $response->assertRedirect(route('audits.show', $run));
    expect($run->user_id)->toBe($user->id)->and($run->status)->toBe(AuditRunStatus::Queued);
    Queue::assertPushed(ExecuteAuditRun::class, fn (ExecuteAuditRun $job) => $job->runId === $run->id);
});

test('a remote audit requires explicit authorization', function () {
    Queue::fake();
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('audits.store'), [
        'type' => 'audit', 'preset' => 'dvwa', 'target_mode' => 'remote',
        'url' => 'https://example.test', 'authorized' => false, 'ttl' => 1800,
    ])->assertSessionHasErrors('authorized');

    Queue::assertNothingPushed();
});

test('runs are isolated by owner', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $run = webAuditRun($owner);

    $this->actingAs($other)->get(route('audits.show', $run))->assertNotFound();
    $this->actingAs($other)->get(route('audits.events', $run))->assertNotFound();
});

test('a run can be cancelled and rerun', function () {
    Queue::fake();
    $user = User::factory()->create();
    $run = webAuditRun($user);

    $this->actingAs($user)->post(route('audits.cancel', $run))->assertRedirect();
    expect($run->refresh()->cancellation_requested)->toBeTrue();
    expect($run->events()->latest('sequence')->first()->payload['cancellation_requested'])->toBeTrue();

    $this->actingAs($user)->post(route('audits.rerun', $run))->assertRedirect();
    expect(AuditRun::query()->count())->toBe(2);
    Queue::assertPushed(ExecuteAuditRun::class);
});

test('the queued job honours cancellation before spawning a process', function () {
    $user = User::factory()->create();
    $run = webAuditRun($user, ['cancellation_requested' => true]);

    app()->call([new ExecuteAuditRun($run->id), 'handle']);

    expect($run->refresh()->status)->toBe(AuditRunStatus::Cancelled)
        ->and($run->exit_code)->toBe(130)
        ->and($run->events()->latest('sequence')->first()->payload['status'])->toBe('cancelled');
});

test('the event stream replays events after its cursor', function () {
    $user = User::factory()->create();
    $run = webAuditRun($user, ['status' => AuditRunStatus::Completed]);
    $recorder = app(AuditEventRecorder::class);
    $recorder->record($run, 'status', ['status' => 'running']);
    $recorder->record($run, 'usage', ['total_tokens' => 42]);

    $response = $this->actingAs($user)->get(route('audits.events', ['audit' => $run, 'after' => 1]));
    $response->assertOk()->assertHeader('content-type', 'text/event-stream; charset=UTF-8');
    $content = $response->streamedContent();
    expect($content)->toContain('event: usage')->toContain('"total_tokens":42')->not->toContain('event: status');
});
