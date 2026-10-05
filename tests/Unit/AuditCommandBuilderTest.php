<?php

use App\Models\AuditRun;
use App\Services\Audit\AuditCommandBuilder;
use Tests\TestCase;

uses(TestCase::class);

it('propagates global pipeline configuration through both launch routes', function (string $type): void {
    $run = new AuditRun;
    $run->audit_id = 'global-fixture';
    $run->type = $type;
    $run->parameters = [
        'global' => true, 'reader_checkpoint_strategy' => 'reader_checkpoint',
        'reader_concurrency' => 3, 'confirmer_concurrency' => 2, 'worker_concurrency' => 1,
        'reader_points' => 123000, 'worker_points' => 1000000, 'ttl' => 1800,
        'preset' => $type === 'audit' ? 'dvwa' : null,
        'benchmark_id' => $type === 'benchmark' ? 'bookstack' : null,
    ];
    expect((new AuditCommandBuilder)->build($run))->toContain(
        '--global', '--reader-checkpoint-strategy=reader_checkpoint',
        '--reader-concurrency=3', '--confirmer-concurrency=2', '--worker-concurrency=1',
        '--reader-points=123000', '--worker-points=1000000',
    );
})->with(['audit', 'benchmark']);

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
        '--depth=1',
        '--keep=false',
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

it('forwards a test-only Recon area hint to the benchmark runner', function (): void {
    $run = new AuditRun;
    $run->audit_id = 'audit-bazar-area';
    $run->parameters = [
        'benchmark_id' => 'yeswiki',
        'test_area' => 'Bazar: stored XSS in page rendering',
        'keep' => false,
        'test' => true,
    ];

    expect((new AuditCommandBuilder)->build($run))
        ->toContain('--test-area=Bazar: stored XSS in page rendering');
});

it('ignores historical Judge settings and forwards the Worker model', function (): void {
    $run = new AuditRun;
    $run->audit_id = 'audit-judge-model';
    $run->parameters = [
        'preset' => 'dvwa', 'categories' => [], 'ttl' => 1800,
        'worker_model' => 'deepseek/worker', 'judge_model' => 'google/judge',
        'skip_health' => false, 'authorized' => false, 'test' => false, 'keep' => false,
    ];

    expect((new AuditCommandBuilder)->build($run))
        ->toContain('--worker-model=deepseek/worker')
        ->not->toContain('--judge-model=google/judge');
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

it('routes the Gitea and MLflow presets through their unbiased benchmarks', function (string $preset): void {
    $run = new AuditRun;
    $run->audit_id = 'audit-'.$preset;
    $run->parameters = ['preset' => $preset, 'categories' => [], 'keep' => false, 'test' => false];

    expect((new AuditCommandBuilder)->build($run))
        ->toContain('benchmark:run')
        ->toContain($preset)
        ->not->toContain('--category=');
})->with(['gitea', 'mlflow']);

it('propagates the exploration reviewer model override', function (): void {
    $run = new AuditRun;
    $run->audit_id = 'audit-reviewer';
    $run->type = 'audit';
    $run->parameters = [
        'preset' => 'dvwa',
        'categories' => [],
        'reviewer_model' => 'reviewer/test',
        'reader_checkpoint_strategy' => 'reader_checkpoint',
        'ttl' => 1800,
        'skip_health' => false,
        'authorized' => false,
        'test' => false,
        'keep' => false,
    ];

    expect((new AuditCommandBuilder)->build($run))
        ->toContain('--reviewer-model=reviewer/test')
        ->toContain('--reader-checkpoint-strategy=reader_checkpoint');
});

it('propagates global surface mode and depth to audit commands', function (): void {
    $run = new AuditRun;
    $run->audit_id = 'audit-depth';
    $run->type = 'audit';
    $run->parameters = [
        'preset' => 'dvwa',
        'categories' => ['A01:2025 Broken Access Control'],
        'global' => true,
        'depth' => 3,
        'ttl' => 1800,
    ];

    expect((new AuditCommandBuilder)->build($run))
        ->toContain('--global')
        ->toContain('--depth=3')
        ->not->toContain('--category=A01:2025 Broken Access Control');
});

it('propagates global surface mode and depth to benchmark commands', function (): void {
    $run = new AuditRun;
    $run->audit_id = 'benchmark-depth';
    $run->type = 'benchmark';
    $run->parameters = [
        'benchmark_id' => 'yeswiki',
        'categories' => ['xss'],
        'global' => true,
        'depth' => 2,
        'keep' => false,
        'test' => false,
    ];

    expect((new AuditCommandBuilder)->build($run))
        ->toContain('benchmark:run')
        ->toContain('--global')
        ->toContain('--depth=2')
        ->not->toContain('--category=xss');
});
