<?php

namespace App\Services\Sandbox\Support;

use RuntimeException;

/**
 * Decide quale container è l'entrypoint HTTP dello stack.
 * Ordine: override esplicito → unico container con porta pubblicata → scoring.
 */
class WebServiceResolver
{
    private const NON_WEB_IMAGE = [
        'mariadb', 'mysql', 'postgres', 'mongo', 'redis', 'memcached', 'rabbitmq',
        'kafka', 'zookeeper', 'elasticsearch', 'opensearch', 'meilisearch', 'typesense',
        'minio', 'mailhog', 'mailpit', 'clickhouse', 'influxdb', 'mssql', 'nats',
        'etcd', 'vault', 'selenium', 'soketi',
    ];

    private const PORT_SCORE = [
        80 => 100, 443 => 90, 8080 => 80, 8000 => 80,
        3000 => 70, 4200 => 60, 5000 => 60,
        9000 => -80, // php-fpm: mai l'entrypoint
    ];

    /**
     * @param  array<int, array<string, mixed>>  $containers
     * @return array<string, mixed>
     */
    public function resolve(array $containers, ?string $service = null): array
    {
        $running = array_values(array_filter(
            $containers,
            fn (array $c): bool => ($c['State'] ?? null) === 'running'
        ));

        if (! $running) {
            throw new RuntimeException('Nessun container running dopo il boot');
        }

        if ($service !== null) {
            foreach ($running as $c) {
                if ($this->serviceName($c) === $service) {
                    return $c;
                }
            }

            $found = implode(', ', array_map($this->serviceName(...), $running));

            throw new RuntimeException("Servizio '{$service}' non trovato. Disponibili: {$found}");
        }

        $candidates = array_values(array_filter(
            $running,
            fn ($c) => $this->publishedPort($c) !== null
        ));

        if (! $candidates) {
            throw new RuntimeException(
                'Nessun container pubblica porte: lo stack non espone un entrypoint HTTP'
            );
        }

        if (count($candidates) === 1) {
            return $candidates[0];
        }

        return $this->highestScoring($candidates);
    }

    /** Docker riporta l'indirizzo di bind: come destinazione va tradotto in loopback. */
    private const WILDCARD_IP = ['', '0.0.0.0', '::', '[::]', '*'];

    /**
     * @param  array<string, mixed>  $c
     * @return array{private: int, public: int, ip: string}|null
     */
    public function publishedPort(array $c): ?array
    {
        $best = null;
        $bestScore = PHP_INT_MIN;

        foreach ($c['Ports'] ?? [] as $p) {
            if (($p['Type'] ?? 'tcp') !== 'tcp' || empty($p['PublicPort'])) {
                continue;
            }

            $privatePort = (int) $p['PrivatePort'];
            $score = self::PORT_SCORE[$privatePort] ?? 20;
            if ($score <= $bestScore) {
                continue;
            }

            $ip = (string) ($p['IP'] ?? '');
            $best = [
                'private' => $privatePort,
                'public' => (int) $p['PublicPort'],
                'ip' => in_array($ip, self::WILDCARD_IP, true) ? '127.0.0.1' : $ip,
            ];
            $bestScore = $score;
        }

        return $best;
    }

    /**
     * Compose nomina la rete come dichiarata in `networks:`, non sempre "<project>_default".
     * La leggiamo dal container invece di ricostruirla.
     *
     * @param  array<string, mixed>  $c
     */
    public function networkName(array $c, string $fallback): string
    {
        return array_key_first($c['NetworkSettings']['Networks'] ?? [])
            ?? ($c['HostConfig']['NetworkMode'] ?? $fallback);
    }

    /**
     * La label propria viene prima di quella di Compose: così un driver non
     * deve fingersi Compose per farsi riconoscere dal resolver.
     *
     * @param  array<string, mixed>  $c
     */
    public function serviceName(array $c): string
    {
        $labels = $c['Labels'] ?? [];

        return $labels[SandboxLabels::SERVICE]
            ?? $labels['com.docker.compose.service']
            ?? ltrim($c['Names'][0] ?? 'unknown', '/');
    }

    /**
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array<string, mixed>
     */
    private function highestScoring(array $candidates): array
    {
        $best = null;
        $bestScore = PHP_INT_MIN;

        foreach ($candidates as $c) {
            $image = strtolower($c['Image'] ?? '');

            foreach (self::NON_WEB_IMAGE as $needle) {
                if (str_contains($image, $needle)) {
                    continue 2;
                }
            }

            $port = $this->publishedPort($c);
            $score = self::PORT_SCORE[$port['private']] ?? 20;

            // chi dipende da altri è l'app, non l'infrastruttura
            if (! empty(($c['Labels'] ?? [])['com.docker.compose.depends_on'])) {
                $score += 30;
            }

            if (preg_match('/^(web|app|nginx|apache|caddy|frontend|http|server)$/i', $this->serviceName($c))) {
                $score += 15;
            }

            if (($c['Health']['Status'] ?? 'none') === 'healthy') {
                $score += 10;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $c;
            }
        }

        return $best ?? $candidates[0];
    }
}
