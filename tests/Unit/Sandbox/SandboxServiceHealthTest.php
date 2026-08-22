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
