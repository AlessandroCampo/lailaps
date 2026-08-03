<?php

use App\Services\Target\DTO\RemoteTargetSpecDTO;
use App\Services\Target\Enums\ProbeStatus;
use App\Services\Target\RemoteTargetService;
use App\Services\Target\Support\DatabaseDsn;
use Illuminate\Support\Facades\Http;

function spec(array $overrides = []): RemoteTargetSpecDTO
{
    return new RemoteTargetSpecDTO(
        url: $overrides['url'] ?? 'https://demo.ngrok-free.app',
        database: $overrides['database'] ?? null,
        healthPath: $overrides['healthPath'] ?? null,
        // il probe HTTP riprova fino allo scadere: nei test basta un giro
        healthTimeout: $overrides['healthTimeout'] ?? 1,
    );
}

it('accepts a target that answers, whatever status it returns', function (): void {
    Http::fake(['*' => Http::response('login', 302)]);

    $target = app(RemoteTargetService::class)->connect(spec());

    expect($target->url())->toBe('https://demo.ngrok-free.app')
        ->and($target->isRunning())->toBeTrue()
        ->and($target->checks)->toHaveCount(1)
        ->and($target->checks[0]->status)->toBe(ProbeStatus::PASSED);
});

it('refuses a target that only serves errors', function (): void {
    Http::fake(['*' => Http::response('bad gateway', 502)]);

    expect(fn () => app(RemoteTargetService::class)->connect(spec()))
        ->toThrow(RuntimeException::class, 'non è pronto');
});

it('warns instead of failing when ngrok answers with its interstitial', function (): void {
    Http::fake(['*' => Http::response('<html>You are about to visit ngrok-skip-browser-warning</html>')]);

    $target = app(RemoteTargetService::class)->connect(spec());

    expect($target->checks[0]->status)->toBe(ProbeStatus::WARNING)
        ->and($target->isRunning())->toBeTrue();
});

it('requires a 2xx from the declared health endpoint', function (): void {
    Http::fake([
        'demo.ngrok-free.app/up' => Http::response('down', 503),
        '*' => Http::response('ok'),
    ]);

    expect(fn () => app(RemoteTargetService::class)->connect(spec(['healthPath' => '/up'])))
        ->toThrow(RuntimeException::class, 'health endpoint');
});

it('reports every failure at once instead of stopping at the first', function (): void {
    Http::fake(['*' => Http::response('boom', 500)]);

    $message = '';

    try {
        app(RemoteTargetService::class)->connect(spec([
            'healthPath' => '/up',
            'database' => new DatabaseDsn('sqlite', '', 0, 'nowhere/database.sqlite'),
        ]));
    } catch (RuntimeException $e) {
        $message = $e->getMessage();
    }

    expect($message)->toContain('raggiungibilità')
        ->toContain('health endpoint')
        ->toContain('database');
});

it('does not let pdo create the sqlite file it was asked to find', function (): void {
    Http::fake(['*' => Http::response('ok')]);

    $missing = storage_path('framework/testing/does-not-exist.sqlite');

    expect(fn () => app(RemoteTargetService::class)->connect(spec([
        'database' => new DatabaseDsn('sqlite', '', 0, $missing),
    ])))->toThrow(RuntimeException::class, 'file sqlite inesistente')
        ->and(file_exists($missing))->toBeFalse();
});

it('connects to a real sqlite database', function (): void {
    Http::fake(['*' => Http::response('ok')]);

    $path = storage_path('framework/testing/probe.sqlite');
    touch($path);

    $target = app(RemoteTargetService::class)->connect(spec([
        'database' => new DatabaseDsn('sqlite', '', 0, $path),
    ]));

    expect($target->isRunning())->toBeTrue()
        ->and($target->summary())->toHaveKey('database');

    unlink($path);
});

it('tells local targets from remote ones', function (): void {
    expect(spec(['url' => 'http://localhost:8080'])->isLocal())->toBeTrue()
        ->and(spec(['url' => 'http://192.168.1.40'])->isLocal())->toBeTrue()
        ->and(spec(['url' => 'https://demo.ngrok-free.app'])->isLocal())->toBeFalse()
        ->and(spec(['url' => 'https://203.0.113.10'])->isLocal())->toBeFalse();
});

it('rejects urls it could never attack', function (string $url): void {
    expect(fn () => spec(['url' => $url]))->toThrow(InvalidArgumentException::class);
})->with([
    'senza schema' => ['demo.ngrok-free.app'],
    'schema sbagliato' => ['ftp://demo.ngrok-free.app'],
]);
