<?php

use App\Enums\AuditRunStatus;
use App\Jobs\ExecuteAuditRun;
use App\Models\AuditRun;
use App\Models\User;
use App\Services\Audit\RunStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\DB;
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
        'run_path' => storage_path('app/runs/test/test/'.strtolower($id)),
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

test('the operator console launches the same pentest run through the web queue', function () {
    Queue::fake();
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('audits.console.launch'), [
        'type' => 'audit',
        'preset' => 'dvwa',
        'target_mode' => 'sandbox',
        'categories' => ['A01:2025 Broken Access Control'],
        'ttl' => 1800,
        'test' => true,
        'keep' => false,
    ]);

    $run = AuditRun::query()->firstOrFail();
    $response->assertRedirect(route('audits.console.show', $run));
    Queue::assertPushed(ExecuteAuditRun::class, fn (ExecuteAuditRun $job) => $job->runId === $run->id);
});

test('the audit console index exposes only the authenticated user runs', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $run = webAuditRun($user);
    $otherRun = webAuditRun($other);

    $this->actingAs($user)
        ->get(route('audits.console.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('audits/ConsoleIndex')
            ->where('runs', function ($runs) use ($run, $otherRun): bool {
                $ids = collect($runs)->pluck('id');

                return $ids->contains($run->id) && ! $ids->contains($otherRun->id);
            }));
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
    $this->actingAs($other)->get(route('audits.log-chunk', $run))->assertNotFound();
    $this->actingAs($other)->get(route('audits.snapshot', $run))->assertNotFound();
});

test('a run can be cancelled and rerun', function () {
    Queue::fake();
    $user = User::factory()->create();
    $run = webAuditRun($user);

    $this->actingAs($user)->post(route('audits.cancel', $run))->assertRedirect();
    expect($run->refresh()->cancellation_requested)->toBeTrue();

    $this->actingAs($user)->post(route('audits.rerun', $run))->assertRedirect();
    expect(AuditRun::query()->count())->toBe(2);
    Queue::assertPushed(ExecuteAuditRun::class);
});

test('the queued job honours cancellation before spawning a process', function () {
    $user = User::factory()->create();
    $run = webAuditRun($user, ['cancellation_requested' => true]);

    app()->call([new ExecuteAuditRun($run->id), 'handle']);

    expect($run->refresh()->status)->toBe(AuditRunStatus::Cancelled)
        ->and($run->exit_code)->toBe(130);
});

test('a queued run is reconciled as failed when its queue job is in failed_jobs', function () {
    $user = User::factory()->create();
    $run = webAuditRun($user);
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode([
            'displayName' => ExecuteAuditRun::class,
            'data' => [
                'commandName' => ExecuteAuditRun::class,
                'command' => 'run='.$run->id,
            ],
        ], JSON_THROW_ON_ERROR),
        'exception' => 'PDOException: SQLSTATE[HY000]: General error: 5 database is locked',
        'failed_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('audits.console.show', $run))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('run.status', 'failed')
            ->where('run.error', 'Queue job fallito: PDOException: SQLSTATE[HY000]: General error: 5 database is locked'));

    expect($run->refresh()->status)->toBe(AuditRunStatus::Failed)
        ->and($run->error)->toContain('database is locked');
});

test('a failed console run can be retried on the same run', function () {
    Queue::fake();
    $user = User::factory()->create();
    $run = webAuditRun($user, [
        'status' => AuditRunStatus::Failed,
        'error' => 'Queue job fallito',
        'finished_at' => now(),
    ]);
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode([
            'displayName' => ExecuteAuditRun::class,
            'data' => ['commandName' => ExecuteAuditRun::class, 'command' => 'run='.$run->id],
        ], JSON_THROW_ON_ERROR),
        'exception' => 'Queue failure',
        'failed_at' => now(),
    ]);

    $this->actingAs($user)
        ->post(route('audits.console.retry', $run))
        ->assertRedirect(route('audits.console.show', $run));

    expect($run->refresh()->status)->toBe(AuditRunStatus::Queued)
        ->and($run->error)->toBeNull()
        ->and(DB::table('failed_jobs')->where('payload', 'like', '%'.$run->id.'%')->exists())->toBeFalse();
    Queue::assertPushed(ExecuteAuditRun::class, fn (ExecuteAuditRun $job) => $job->runId === $run->id);
});

test('the event stream has no second persistent event source', function () {
    $user = User::factory()->create();
    $run = webAuditRun($user, ['status' => AuditRunStatus::Completed]);
    $response = $this->actingAs($user)->get(route('audits.events', ['audit' => $run, 'after' => 1]));
    $response->assertOk()->assertHeader('content-type', 'text/event-stream; charset=UTF-8');
    $content = $response->streamedContent();
    expect($content)->not->toContain('event: usage');
});

test('the event stream publishes the current live status without a transcript event', function () {
    $user = User::factory()->create();
    $run = webAuditRun($user, [
        'status' => AuditRunStatus::Running,
        'started_at' => now(),
    ]);

    $response = $this->actingAs($user)->get(route('audits.events', $run));

    $response->assertOk();
    expect($response->streamedContent())
        ->toContain('event: status')
        ->toContain('"status":"running"');
});

test('the console loads bounded durable log chunks by byte offset and exposes a compact snapshot', function () {
    $user = User::factory()->create();
    $run = webAuditRun($user, ['status' => AuditRunStatus::Completed, 'finished_at' => now()]);
    $storage = app(RunStorage::class);
    $storage->initializeRun($run);
    file_put_contents($storage->logPath((string) $run->run_path, $run->audit_id), "prima\nseconda\n");
    $storage->writeOutcome((string) $run->run_path, $run->audit_id, [
        'confirmed' => [['finding_id' => 'finding-1', 'title' => 'Confirmed', 'status' => 'confirmed']],
        'suspected' => [['finding_id' => 'finding-2', 'title' => 'Suspect', 'status' => 'suspected']],
    ], null);

    $this->actingAs($user)->getJson(route('audits.log-chunk', ['audit' => $run, 'after' => 6]))
        ->assertOk()
        ->assertJsonPath('start', 6)
        ->assertJsonPath('offset', 14)
        ->assertJsonPath('base64', base64_encode("seconda\n"))
        ->assertJsonPath('eof', true);

    $this->actingAs($user)->getJson(route('audits.snapshot', $run))
        ->assertOk()
        ->assertJsonPath('status', 'completed')
        ->assertJsonPath('confirmed', 1)
        ->assertJsonPath('suspected', 1)
        ->assertJsonPath('logBytes', 14);
});

test('deleting from the console removes the database row and its run directory', function () {
    $user = User::factory()->create();
    $id = 'test-delete-'.bin2hex(random_bytes(6));
    $directory = storage_path('app/runs/test/test/'.$id);
    $run = webAuditRun($user, [
        'status' => AuditRunStatus::Completed,
        'finished_at' => now(),
        'audit_id' => $id, 'run_path' => $directory,
    ]);
    $runDirectory = (string) $run->run_path;
    app(RunStorage::class)->initializeRun($run);

    $this->actingAs($user)->delete(route('audits.console.destroy', $run))
        ->assertRedirect(route('audits.console.index'));

    expect(AuditRun::query()->whereKey($run->id)->exists())->toBeFalse()
        ->and(is_dir($runDirectory))->toBeFalse();
});
