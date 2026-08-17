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
        'worker_model' => null,
        'ttl' => 1800,
        'dual_agent' => false,
        'skip_health' => false,
        'authorized' => false,
        'test' => false,
        'keep' => false,
    ];

    expect((new AuditCommandBuilder)->build($run))
        ->toContain('pentest:run')
        ->not->toContain('benchmark:run');
});
