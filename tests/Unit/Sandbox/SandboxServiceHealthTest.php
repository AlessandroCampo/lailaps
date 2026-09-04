<?php

use App\Services\Sandbox\DockerClient;
use App\Services\Sandbox\DTO\SandboxSpecDTO;
use App\Services\Sandbox\SandboxService;
use App\Services\Sandbox\Support\WebServiceResolver;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

function exposedSandboxHealthService(): SandboxService
{
    return new class(app(WebServiceResolver::class), app(DockerClient::class), []) extends SandboxService
    {
        public function waitFor(string $url, int $timeout): void
        {
            $this->waitUntilHealthy($url, $timeout);
        }

        public function urlWithPath(string $url, ?string $path): string
        {
            return $this->appendPath($url, $path);
        }
    };
}

it('accepts only an application success or redirect response', function (): void {
    Http::fake(['*' => Http::response('ready', 302)]);

    exposedSandboxHealthService()->waitFor('http://127.0.0.1:8080/benchmark/', 1);

    Http::assertSentCount(1);
});

it('rejects a responding web server whose application path is missing', function (): void {
    Http::fake(['*' => Http::response('<h1>Not Found</h1>', 404)]);

    expect(fn () => exposedSandboxHealthService()->waitFor('http://127.0.0.1:8080/missing', 1))
        ->toThrow(RuntimeException::class, 'HTTP 404: Not Found');
});

it('builds the target and health URLs without losing the application context', function (): void {
    $service = exposedSandboxHealthService();
    $target = $service->urlWithPath('http://127.0.0.1:8080', '/benchmark');

    expect($target)->toBe('http://127.0.0.1:8080/benchmark')
        ->and($service->urlWithPath($target, '/'))->toBe('http://127.0.0.1:8080/benchmark/');
});

it('can request diagnostic resource retention on a failed bootstrap', function (): void {
    $spec = new SandboxSpecDTO('diagnostic-test', base_path(), keepOnFailure: true);

    expect($spec->keepOnFailure)->toBeTrue();
});

it('reuses a live Lailaps sandbox while retaining its target container identity', function (): void {
    Http::fake(['*' => Http::response('ready', 200)]);
    $expiresAt = now()->addHour()->getTimestamp();
    $containers = [[
        'Id' => 'target-container-id',
        'Names' => ['/audit-warm-target'],
        'State' => 'running',
        'Labels' => [
            'sandbox.managed_by' => 'lailaps',
            'sandbox.audit_id' => 'warm-target',
            'sandbox.driver' => 'image',
            'sandbox.expires_at' => (string) $expiresAt,
        ],
        'Ports' => [[
            'Type' => 'tcp',
            'PrivatePort' => 8080,
            'PublicPort' => 49152,
            'IP' => '127.0.0.1',
        ]],
        'NetworkSettings' => ['Networks' => ['audit-warm-target-net' => []]],
    ]];
    $docker = Mockery::mock(DockerClient::class);
    $docker->shouldReceive('listContainersByLabel')
        ->once()
        ->with('sandbox.audit_id', 'warm-target')
        ->andReturn($containers);
    $service = new SandboxService(new WebServiceResolver, $docker, []);

    $target = $service->reuse('warm-target', new SandboxSpecDTO('new-run', base_path()));

    expect($target->auditId)->toBe('warm-target')
        ->and($target->containerId)->toBe('target-container-id')
        ->and($target->url())->toBe('http://127.0.0.1:49152/')
        ->and($target->isRunning())->toBeTrue();
});
