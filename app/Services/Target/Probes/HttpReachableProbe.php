<?php

namespace App\Services\Target\Probes;

use App\Services\Target\Contracts\TargetProbe;
use App\Services\Target\DTO\ProbeResultDTO;
use App\Services\Target\DTO\RemoteTargetSpecDTO;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Il controllo di base: c'è un web server che risponde all'URL?
 *
 * Riprova fino al timeout perché un tunnel appena aperto o un container in boot
 * possono rifiutare le prime connessioni. Un 4xx va benissimo — significa che
 * qualcuno sta rispondendo — mentre un 5xx no: su ngrok un 502 vuol dire tunnel
 * su ma applicazione locale giù, esattamente il caso che vogliamo intercettare.
 */
final class HttpReachableProbe implements TargetProbe
{
    public function name(): string
    {
        return 'raggiungibilità';
    }

    public function appliesTo(RemoteTargetSpecDTO $spec): bool
    {
        return true;
    }

    public function check(RemoteTargetSpecDTO $spec): ProbeResultDTO
    {
        $deadline = now()->addSeconds($spec->healthTimeout);
        $startedAt = microtime(true);
        $lastError = 'nessuna risposta';

        do {
            try {
                $response = Http::timeout((int) config('pentest.target.probe_timeout'))
                    ->withoutRedirecting()
                    ->get($spec->url);

                if ($response->status() < 500) {
                    return $this->isInterstitial($response->body())
                        ? ProbeResultDTO::warn(
                            $this->name(),
                            "HTTP {$response->status()}, ma risponde l'interstitial di ngrok: l'agente analizzerà quella pagina, non l'app (usa un dominio custom o l'header ngrok-skip-browser-warning)",
                            $this->elapsed($startedAt),
                        )
                        : ProbeResultDTO::pass($this->name(), "HTTP {$response->status()}", $this->elapsed($startedAt));
                }

                $lastError = "HTTP {$response->status()}";
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
            }

            usleep(500_000);
        } while (now()->lessThan($deadline));

        return ProbeResultDTO::fail(
            $this->name(),
            "{$spec->url} non risponde dopo {$spec->healthTimeout}s: {$lastError}",
            $this->elapsed($startedAt),
        );
    }

    /**
     * La pagina "You are about to visit..." che ngrok interpone sui domini
     * gratuiti: risponde 200 e passerebbe qualunque check ingenuo.
     */
    private function isInterstitial(string $body): bool
    {
        return str_contains($body, 'ngrok-skip-browser-warning')
            || (str_contains($body, 'ngrok') && str_contains($body, 'You are about to visit'));
    }

    private function elapsed(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
