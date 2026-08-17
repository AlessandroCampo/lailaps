<?php

namespace App\Services\Sandbox;

use App\Services\Sandbox\Contracts\SandboxDriver;
use App\Services\Sandbox\DTO\SandboxDTO;
use App\Services\Sandbox\DTO\SandboxSpecDTO;
use App\Services\Sandbox\Support\SandboxLabels;
use App\Services\Sandbox\Support\WebServiceResolver;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Punto d'ingresso unico: chi chiama descrive il progetto con uno
 * SandboxSpecDTO e riceve una sandbox raggiungibile via HTTP, senza sapere se
 * dietro c'è Compose, un'immagine o un Dockerfile.
 */
class SandboxService
{
    /** @param  array<int, SandboxDriver>  $drivers  in ordine di priorità, catch-all per ultimo */
    public function __construct(
        protected WebServiceResolver $resolver,
        protected DockerClient $docker,
        protected array $drivers,
    ) {}

    public function spawn(SandboxSpecDTO $spec): SandboxDTO
    {
        $driver = $this->driverFor($spec);

        try {
            // boot dentro il try: se fallisce a metà lascia comunque residui
            $containers = $driver->boot($spec);

            $web = $this->resolver->resolve($containers, $spec->webService);
            $port = $this->resolver->publishedPort($web)
                ?? throw new RuntimeException('Il container selezionato non pubblica porte');

            $projectName = $spec->projectName();
            $origin = "http://{$port['ip']}:{$port['public']}";
            $url = $this->appendPath($origin, $spec->basePath);
            $healthUrl = $this->appendPath($url, $spec->healthPath);

            $this->waitUntilHealthy($healthUrl, $spec->healthTimeout);

            return new SandboxDTO(
                auditId: $spec->auditId,
                driver: $driver->name(),
                projectName: $projectName,
                containerId: $web['Id'],
                containerName: ltrim($web['Names'][0] ?? $projectName, '/'),
                serviceName: $this->resolver->serviceName($web),
                networkName: $this->resolver->networkName($web, "{$projectName}_default"),
                url: $url,
                hostPort: $port['public'],
                expiresAt: $spec->expiresAt(),
            );
        } catch (Throwable $e) {
            rescue(fn () => $driver->destroy($spec->auditId), report: false);

            throw $e;
        }
    }

    public function teardown(SandboxDTO $sandbox): void
    {
        if (! $sandbox->isRunning()) {
            return;
        }

        $this->driverByName($sandbox->driver)->destroy($sandbox->auditId);

        $sandbox->markDead();
    }

    /**
     * Smonta tutte le sandbox il cui TTL è scaduto, qualunque driver le abbia
     * avviate: è quello che rende ttlSeconds una garanzia e non un'annotazione.
     *
     * @return array<int, string> auditId smontati
     */
    public function reapExpired(): array
    {
        $now = now()->getTimestamp();
        $expired = [];

        foreach ($this->docker->listContainersByLabel(SandboxLabels::MANAGED_BY, SandboxLabels::OWNER) as $container) {
            $labels = $container['Labels'] ?? [];

            $auditId = $labels[SandboxLabels::AUDIT_ID] ?? null;
            $expiresAt = $labels[SandboxLabels::EXPIRES_AT] ?? null;

            if ($auditId === null || $expiresAt === null || (int) $expiresAt > $now) {
                continue;
            }

            $expired[$auditId] = $labels[SandboxLabels::DRIVER] ?? null;
        }

        foreach ($expired as $auditId => $driverName) {
            rescue(function () use ($auditId, $driverName): void {
                $driver = $driverName !== null
                    ? $this->driverByName($driverName)
                    : throw new RuntimeException("Driver non registrato sulle label di {$auditId}");

                $driver->destroy((string) $auditId);
            }, report: false);
        }

        return array_map(strval(...), array_keys($expired));
    }

    protected function driverFor(SandboxSpecDTO $spec): SandboxDriver
    {
        foreach ($this->drivers as $driver) {
            if ($driver->supports($spec)) {
                return $driver;
            }
        }

        throw new RuntimeException("Nessun driver sa avviare il progetto in {$spec->projectPath}");
    }

    protected function driverByName(string $name): SandboxDriver
    {
        foreach ($this->drivers as $driver) {
            if ($driver->name() === $name) {
                return $driver;
            }
        }

        throw new RuntimeException("Driver sconosciuto: {$name}");
    }

    protected function waitUntilHealthy(string $url, int $timeoutSeconds): void
    {
        $deadline = now()->addSeconds($timeoutSeconds);
        $lastError = 'nessuna risposta';

        while (now()->lessThan($deadline)) {
            try {
                $response = Http::connectTimeout(2)
                    ->timeout(min(5, $timeoutSeconds))
                    ->withoutRedirecting()
                    ->get($url);
                // Readiness applicativa: un semplice listener HTTP (404/5xx) non basta.
                $status = $response->status();
                if ($status >= 200 && $status < 400) {
                    return;
                }

                $excerpt = trim(preg_replace('/\s+/', ' ', strip_tags($response->body())) ?? '');
                $lastError = "HTTP {$status}".($excerpt !== '' ? ': '.mb_substr($excerpt, 0, 200) : '');
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
            }

            usleep(500_000);
        }

        throw new RuntimeException("Healthcheck fallito su {$url}: {$lastError}");
    }

    protected function appendPath(string $url, ?string $path): string
    {
        if ($path === null || $path === '/') {
            return rtrim($url, '/').'/';
        }

        return rtrim($url, '/').'/'.ltrim($path, '/');
    }
}
