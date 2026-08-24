<?php

use App\Models\AuditRun;
use App\Services\Audit\AuditCommandBuilder;
use Tests\TestCase;

uses(TestCase::class);

it('routes the OWASP preset through the unbiased benchmark command regardless of run type', function (): void {
    $run = new AuditRun;
    $run->audit_id = 'audit-123';
    $run->type = 'audit';
    $run->parameters = [
        'preset' => 'owasp-benchmark-java',
        'benchmark_id' => null,
        'keep' => false,
        'test' => true,
    ];

    expect((new AuditCommandBuilder)->build($run))->toBe([
        PHP_BINARY,
        base_path('artisan'),
        'benchmark:run',
        'owasp-benchmark-java',
        '--audit-id=audit-123',
        '--test',
        '--tool-output',
    ]);
});

it('propagates remote health and database checks through the benchmark runner', function (): void {
    $run = new AuditRun;
    $run->audit_id = 'audit-remote';
    $run->type = 'benchmark';
    $run->parameters = [
        'preset' => null,
        'benchmark_id' => 'owasp-benchmark-java',
        'url' => 'http://localhost:8080/benchmark',
        'db' => 'mysql://audit:secret@127.0.0.1:3306/app',
        'health_path' => '/healthz',
        'skip_health' => false,
        'authorized' => true,
        'keep' => false,
        'test' => false,
    ];

    expect((new AuditCommandBuilder)->build($run))
        ->toContain('--url=http://localhost:8080/benchmark')
        ->toContain('--db=mysql://audit:secret@127.0.0.1:3306/app')
        ->toContain('--health-path=/healthz')
        ->toContain('--assume-authorized')
        ->not->toContain('--skip-health');
});

it('forwards an optional benchmark subcategory', function (): void {
    $run = new AuditRun;
    $run->audit_id = 'audit-sqli';
    $run->parameters = [
        'benchmark_id' => 'owasp-benchmark-java',
        'categories' => ['sqli'],
        'keep' => false,
        'test' => false,
    ];

    expect((new AuditCommandBuilder)->build($run))->toContain('--category=sqli');
});

it('forwards a dedicated Dynamic Judge model without changing the Worker', function (): void {
    $run = new AuditRun;
    $run->audit_id = 'audit-judge-model';
    $run->parameters = [
        'preset' => 'dvwa', 'categories' => [], 'ttl' => 1800,
        'worker_model' => 'deepseek/worker', 'judge_model' => 'google/judge',
        'skip_health' => false, 'authorized' => false, 'test' => false, 'keep' => false,
    ];

    expect((new AuditCommandBuilder)->build($run))
        ->toContain('--worker-model=deepseek/worker')
        ->toContain('--judge-model=google/judge');
});

it('keeps ordinary presets on the generic pentest command', function (): void {
    $run = new AuditRun;
    $run->audit_id = 'audit-456';
    $run->type = 'audit';
    $run->parameters = [
        'preset' => 'dvwa',
        'benchmark_id' => null,
        'categories' => [],
        'path' => null,
        'url' => null,
        'db' => null,
        'health_path' => null,
        'dockerfile' => null,
        'mount' => null,
        'port' => null,
        'service' => null,
        'compose' => null,
        'reader_model' => null,
        'reviewer_model' => null,
        'confirmer_model' => null,
        'worker_model' => null,
        'ttl' => 1800,
        'skip_health' => false,
        'authorized' => false,
        'test' => false,
        'keep' => false,
    ];

    expect((new AuditCommandBuilder)->build($run))
        ->toContain('pentest:run')
        ->not->toContain('benchmark:run')
        ->toContain('--tool-output');
});

it('retains compact tool result previews for every web transcript', function (): void {
    $run = new AuditRun;
    $run->audit_id = 'audit-tool-output';
    $run->parameters = [
        'preset' => 'dvwa', 'categories' => [], 'ttl' => 1800,
        'skip_health' => false, 'authorized' => false, 'test' => false,
        'keep' => false, 'tool_output' => true,
    ];

    expect((new AuditCommandBuilder)->build($run))->toContain('--tool-output');
});

it('provides Kanboard as a generic audit preset with its HTTP port', function (): void {
    $run = new AuditRun;
    $run->audit_id = 'audit-kanboard';
    $run->parameters = [
        'preset' => 'kanboard',
        'categories' => [],
        'ttl' => 1800,
        'skip_health' => false,
        'authorized' => false,
        'test' => false,
        'keep' => false,
    ];

    expect((new AuditCommandBuilder)->build($run))
        ->toContain('pentest:run')
        ->toContain('--path='.base_path('targets/kanboard'))
        ->toContain('--port=80')
        ->toContain('--category=A01:2025 Broken Access Control');
});

it('routes the YesWiki preset through benchmark:run and leaves all categories enabled', function (): void {
    $run = new AuditRun;
    $run->audit_id = 'audit-yeswiki';
    $run->type = 'audit';
    $run->parameters = [
        'preset' => 'yeswiki',
        'categories' => [],
        'keep' => false,
        'test' => false,
    ];

    expect((new AuditCommandBuilder)->build($run))
        ->toContain('benchmark:run')
        ->toContain('yeswiki')
        ->not->toContain('pentest:run')
        ->not->toContain('--category=');
});

it('propagates the exploration reviewer model override', function (): void {
    $run = new AuditRun;
    $run->audit_id = 'audit-reviewer';
    $run->type = 'audit';
    $run->parameters = [
        'preset' => 'dvwa',
        'categories' => [],
        'reviewer_model' => 'reviewer/test',
        'ttl' => 1800,
        'skip_health' => false,
        'authorized' => false,
        'test' => false,
        'keep' => false,
    ];

    expect((new AuditCommandBuilder)->build($run))
        ->toContain('--reviewer-model=reviewer/test');
});
