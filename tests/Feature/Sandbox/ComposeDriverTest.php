<?php

use App\Services\Sandbox\DockerClient;
use App\Services\Sandbox\Drivers\ComposeDriver;
use App\Services\Sandbox\DTO\SandboxSpecDTO;
use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;

it('isolates explicit container names and published ports per audit', function (): void {
    $auditId = 'compose-isolation-test';
    $projectPath = storage_path("framework/testing/{$auditId}");

    File::ensureDirectoryExists($projectPath);
    File::put("{$projectPath}/compose.yml", <<<'YAML'
services:
  web:
    image: nginx:alpine
    container_name: global-web
    ports:
      - 127.0.0.1:8080:80
      - target: 443
        published: 8443
        protocol: tcp
  worker:
    image: alpine
YAML);

    try {
        $driver = new ComposeDriver(new DockerClient);
        $method = new ReflectionMethod($driver, 'writeOverride');
        $overridePath = $method->invoke(
            $driver,
            "{$projectPath}/compose.yml",
            new SandboxSpecDTO($auditId, $projectPath),
        );

        $override = Yaml::parseFile($overridePath, Yaml::PARSE_CUSTOM_TAGS);
        $web = $override['services']['web'];

        expect($web['container_name'])->toBe('audit-compose-isolation-test-web')
            ->and($web['ports'])->toBeInstanceOf(TaggedValue::class)
            ->and($web['ports']->getTag())->toBe('override')
            ->and($web['ports']->getValue()[0])->toBe('127.0.0.1::80')
            ->and($web['ports']->getValue()[1])->toMatchArray([
                'target' => 443,
                'protocol' => 'tcp',
                'host_ip' => '127.0.0.1',
            ])
            ->and($web['ports']->getValue()[1])->not->toHaveKey('published')
            ->and($override['services']['worker'])->not->toHaveKey('container_name')
            ->and($override['services']['worker'])->not->toHaveKey('ports');
    } finally {
        File::deleteDirectory($projectPath);
        File::deleteDirectory(storage_path("framework/lailaps-sandbox/{$auditId}"));
    }
});

it('passes variables added at runtime to docker compose', function (): void {
    $name = 'LAILAPS_BENCHMARK_RUNTIME_ROOT';
    $previous = getenv($name);
    putenv("{$name}=C:/benchmark/runtime");

    try {
        $driver = new ComposeDriver(new DockerClient);
        $method = new ReflectionMethod($driver, 'compose');
        $process = $method->invoke(
            $driver,
            storage_path('framework'),
            'audit-environment-test',
            [],
            ['config'],
            10,
        );

        expect($process->getEnv()[$name] ?? null)->toBe('C:/benchmark/runtime');
    } finally {
        $previous === false ? putenv($name) : putenv("{$name}={$previous}");
    }
});
