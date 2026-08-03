<?php

use App\Enums\AuditRunStatus;
use App\Models\AuditRun;
use App\Models\User;
use App\Services\Audit\AuditEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(Tests\TestCase::class, RefreshDatabase::class);

test('audit events receive monotonic sequences and large payloads become artifacts', function () {
    config()->set('audits.event_payload_bytes', 100);
    $user = User::factory()->create();
    $id = (string) Str::ulid();
    $directory = storage_path('framework/testing/audit-events-'.$id.'/artifacts');
    $run = AuditRun::query()->create([
        'id' => $id, 'user_id' => $user->id, 'audit_id' => strtolower($id),
        'type' => 'audit', 'status' => AuditRunStatus::Running, 'parameters' => [],
        'artifact_path' => $directory,
    ]);
    $recorder = app(AuditEventRecorder::class);

    $first = $recorder->record($run, 'status', ['status' => 'running']);
    $second = $recorder->record($run, 'tool_result', ['output' => str_repeat('x', 500)]);

    expect($first->sequence)->toBe(1)
        ->and($second->sequence)->toBe(2)
        ->and($second->artifact_ref)->toBe('ui-events/2.json')
        ->and(is_file($directory.'/ui-events/2.json'))->toBeTrue();
});
