<?php

use App\Services\Sandbox\Audit\AuditProfile;

it('loads the mutillidae setup and semantic readiness contract', function (): void {
    $profile = AuditProfile::fromProject(dirname(__DIR__, 3).'/targets/mutillidae-source');

    expect($profile->service)->toBe('www')
        ->and($profile->setup)->toHaveCount(1)
        ->and($profile->setup[0])->toMatchArray([
            'type' => 'http',
            'method' => 'GET',
            'path' => '/set-up-database.php',
        ])
        ->and($profile->readiness[0])->toMatchArray([
            'path' => '/index.php',
            'expected_status' => 200,
        ])
        ->and($profile->database)->toBe([]);
});

it('loads an explicit blocking database health contract', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'lailaps-db-profile-');
    file_put_contents($path, <<<'YAML'
database:
  type: http
  method: POST
  path: /database-health
  expected_status: 200
YAML);

    try {
        $profile = AuditProfile::fromPath($path);

        expect($profile->database)->toHaveCount(1)
            ->and($profile->database[0])->toMatchArray([
                'type' => 'http',
                'method' => 'POST',
                'path' => '/database-health',
                'expected_status' => 200,
            ])
            ->and($profile->hasPreparation())->toBeTrue();
    } finally {
        unlink($path);
    }
});

it('loads the DVWA csrf bootstrap contract', function (): void {
    $profile = AuditProfile::fromProject(dirname(__DIR__, 3).'/targets/dvwa');

    expect($profile->service)->toBe('dvwa')
        ->and($profile->setup)->toHaveCount(2)
        ->and($profile->setup[0]['capture'])->toHaveKey('user_token')
        ->and($profile->setup[1]['form'])->toMatchArray([
            'create_db' => 'Create / Reset Database',
            'user_token' => '{{ user_token }}',
        ])
        ->and($profile->readiness[0])->toMatchArray([
            'path' => '/login.php',
            'expected_status' => 200,
        ]);
});

it('loads the OWASP Benchmark application context and readiness endpoint', function (): void {
    $profile = AuditProfile::fromProject(dirname(__DIR__, 3).'/targets/owasp-benchmark-java');

    expect($profile->basePath)->toBe('/benchmark')
        ->and($profile->healthPath)->toBe('/')
        ->and($profile->readiness[0])->toMatchArray([
            'path' => '/',
            'expected_status' => 200,
        ]);
});

it('accepts a single readiness mapping and rejects malformed profile steps', function (): void {
    $project = sys_get_temp_dir().'/lailaps-audit-profile-test';
    if (! is_dir($project) && ! mkdir($project, 0777, true) && ! is_dir($project)) {
        throw new RuntimeException("Impossibile creare {$project}");
    }

    try {
        file_put_contents("{$project}/lailaps.audit.yaml", <<<'YAML'
target:
  service: app
readiness:
  path: /healthz
  expected_status: 200
YAML);

        $profile = AuditProfile::fromProject($project);
        expect($profile->service)->toBe('app')
            ->and($profile->readiness)->toHaveCount(1)
            ->and($profile->readiness[0]['path'])->toBe('/healthz');

        file_put_contents("{$project}/lailaps.audit.yaml", 'setup: broken');
        expect(fn () => AuditProfile::fromProject($project))
            ->toThrow(InvalidArgumentException::class, 'setup deve essere una lista');
    } finally {
        @unlink("{$project}/lailaps.audit.yaml");
        @rmdir($project);
    }
});
