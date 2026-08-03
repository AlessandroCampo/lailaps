<?php

namespace App\Services\Target\DTO;

use App\Services\Target\Support\DatabaseDsn;
use InvalidArgumentException;

/**
 * Descrive un ambiente che l'utente ha già in piedi: noi non lo avviamo e non
 * lo smontiamo, ci limitiamo a verificare che sia davvero pronto.
 *
 * È il gemello di SandboxSpecDTO sull'altro ramo: là si dichiara cosa buildare,
 * qui cosa controllare prima di dare il via all'agente.
 */
final class RemoteTargetSpecDTO
{
    public readonly string $url;

    public function __construct(
        string $url,
        /** Se valorizzato, si tenta una connessione di prova al database del target. */
        public ?DatabaseDsn $database = null,
        /** Endpoint applicativo che deve rispondere 2xx (es. "/up" su Laravel). */
        public ?string $healthPath = null,
        /** Secondi entro cui l'URL deve rispondere: un tunnel appena aperto ci mette un attimo. */
        public int $healthTimeout = 20,
    ) {
        $this->url = $this->normalize($url);

        if ($healthTimeout < 1) {
            throw new InvalidArgumentException('healthTimeout deve essere positivo');
        }
    }

    public function host(): string
    {
        return (string) parse_url($this->url, PHP_URL_HOST);
    }

    /** URL completo dell'endpoint di health, se ne è stato dichiarato uno. */
    public function healthUrl(): ?string
    {
        return $this->healthPath !== null
            ? $this->url.'/'.ltrim($this->healthPath, '/')
            : null;
    }

    /**
     * Distingue "sto attaccando la mia macchina" da "sto attaccando qualcosa
     * là fuori": sul secondo caso la CLI chiede conferma esplicita.
     */
    public function isLocal(): bool
    {
        $host = $this->host();

        if (in_array($host, ['localhost', '127.0.0.1', '::1', 'host.docker.internal'], strict: true)) {
            return true;
        }

        // un IP privato è già una risposta; un hostname non è mai locale per certo
        return filter_var($host, FILTER_VALIDATE_IP) !== false
            && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    private function normalize(string $url): string
    {
        $url = rtrim(trim($url), '/');
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException(
                "URL del target non valido: '{$url}'. Serve uno schema esplicito, es. https://xyz.ngrok-free.app"
            );
        }

        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], strict: true)) {
            throw new InvalidArgumentException("Schema non supportato per il target: {$parts['scheme']}");
        }

        return $url;
    }
}
