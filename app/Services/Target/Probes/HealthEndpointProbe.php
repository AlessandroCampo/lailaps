<?php

namespace App\Services\Target\Probes;

use App\Services\Target\Contracts\TargetProbe;
use App\Services\Target\DTO\ProbeResultDTO;
use App\Services\Target\DTO\RemoteTargetSpecDTO;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Verifica l'endpoint di health dichiarato dall'applicazione (es. /up).
 *
 * A differenza della raggiungibilità qui si pretende un 2xx: se l'app espone
 * un health check è lei stessa a dire che le sue dipendenze sono a posto.
 * Un solo tentativo: che il server risponda l'ha già stabilito la probe prima.
 */
final class HealthEndpointProbe implements TargetProbe
{
    public function name(): string
    {
        return 'health endpoint';
    }

    public function appliesTo(RemoteTargetSpecDTO $spec): bool
    {
        return $spec->healthPath !== null;
    }

    public function check(RemoteTargetSpecDTO $spec): ProbeResultDTO
    {
        $url = (string) $spec->healthUrl();
        $startedAt = microtime(true);

        try {
            $response = Http::timeout((int) config('pentest.target.probe_timeout'))->get($url);
        } catch (Throwable $e) {
            return ProbeResultDTO::fail($this->name(), "{$url}: {$e->getMessage()}", $this->elapsed($startedAt));
        }

        return $response->successful()
            ? ProbeResultDTO::pass($this->name(), "{$url} → HTTP {$response->status()}", $this->elapsed($startedAt))
            : ProbeResultDTO::fail($this->name(), "{$url} → HTTP {$response->status()}", $this->elapsed($startedAt));
    }

    private function elapsed(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
