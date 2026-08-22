<?php

use App\Services\Sandbox\DockerClient;
use App\Services\Sandbox\Drivers\ImageDriver;
use App\Services\Sandbox\DTO\SandboxSpecDTO;
use Tests\TestCase;

uses(TestCase::class);

it('prefers an HTTP port when an image exposes both HTTP and HTTPS', function (): void {
    $docker = new class extends DockerClient
    {
        public function inspectImage(string $image): array
        {
            return ['Config' => ['ExposedPorts' => ['443/tcp' => new stdClass, '80/tcp' => new stdClass]]];
        }
    };
    $method = new ReflectionMethod(ImageDriver::class, 'resolvePort');
    $port = $method->invoke(
        new ImageDriver($docker),
        new SandboxSpecDTO('port-selection', base_path()),
        ['image' => 'fixture', 'mount' => null, 'port' => null],
    );

    expect($port)->toBe(80);
});
