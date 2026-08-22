<?php

namespace App\Services\Sandbox;

use FilesystemIterator;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use PharData;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;

class DockerClient
{
    protected Client $http;

    /** @param  array<int, string>  $buildExclude */
    public function __construct(
        string $baseUri = 'http://localhost:2375',
        protected int $timeout = 30,
        protected int $buildTimeout = 600,
        protected array $buildExclude = ['.git', 'node_modules'],
    ) {
        $this->http = new Client([
            'base_uri' => rtrim($baseUri, '/').'/',
            'http_errors' => false,
            'timeout' => $this->timeout,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $json
     * @return array<mixed>
     */
    protected function request(string $method, string $uri, ?array $json = null): array
    {
        try {
            $options = $json !== null ? ['json' => $json] : [];
            $response = $this->http->request($method, ltrim($uri, '/'), $options);
        } catch (GuzzleException $e) {
            throw new RuntimeException("Docker API irraggiungibile: {$e->getMessage()}", previous: $e);
        }

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        $decoded = $body !== '' ? json_decode($body, true) : [];

        if ($status >= 400) {
            $message = is_array($decoded) ? ($decoded['message'] ?? $body) : $body;

            throw new RuntimeException("Docker API [{$status}] {$method} {$uri}: {$message}");
        }

        return is_array($decoded) ? $decoded : [];
    }

    /** Verifica che l'engine risponda, per fallire con un messaggio comprensibile. */
    public function ping(): void
    {
        $this->request('GET', '/_ping');
    }

    // ---------------------------------------------------------------- immagini

    public function imageExists(string $image): bool
    {
        try {
            $this->inspectImage($image);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    public function inspectImage(string $image): array
    {
        return $this->request('GET', '/images/'.rawurlencode($image).'/json');
    }

    public function pullImage(string $image): void
    {
        [$name, $tag] = $this->splitImageTag($image);

        $response = $this->http->post('images/create?'.http_build_query([
            'fromImage' => $name,
            'tag' => $tag,
        ]), ['timeout' => $this->buildTimeout]);

        $body = (string) $response->getBody();

        if ($response->getStatusCode() >= 400) {
            throw new RuntimeException("Pull di '{$image}' fallito [{$response->getStatusCode()}]: {$body}");
        }

        $this->assertNoStreamError($body, "Pull di '{$image}' fallito");
    }

    /** Presente in locale o pullata. Non ricontrolla dopo il pull: ci pensa Docker. */
    public function ensureImagePresent(string $image): void
    {
        if (! $this->imageExists($image)) {
            $this->pullImage($image);
        }
    }

    public function removeImage(string $image, bool $force = true): void
    {
        $this->request('DELETE', '/images/'.rawurlencode($image).'?force='.($force ? 'true' : 'false'));
    }

    /**
     * Build da un context su disco. Il tar rispetta .dockerignore più gli
     * esclusi di configurazione, altrimenti su progetti reali si spediscono
     * centinaia di MB all'engine.
     *
     * @param  array<string, string>  $buildArgs
     */
    public function buildImage(
        string $contextPath,
        string $tag,
        array $buildArgs = [],
        string $dockerfile = 'Dockerfile',
    ): void {
        if (! is_dir($contextPath)) {
            throw new RuntimeException("Build context inesistente: {$contextPath}");
        }

        $tarPath = $this->packContext($contextPath, $dockerfile);
        $stream = null;

        try {
            $stream = fopen($tarPath, 'r');

            if ($stream === false) {
                throw new RuntimeException("Impossibile leggere il context tar: {$tarPath}");
            }

            $query = http_build_query([
                't' => $tag,
                'dockerfile' => $dockerfile,
                'buildargs' => json_encode((object) $buildArgs),
                'rm' => 'true',
                'forcerm' => 'true',
            ]);

            $response = $this->http->post("build?{$query}", [
                'headers' => ['Content-Type' => 'application/x-tar'],
                'body' => $stream,
                'timeout' => $this->buildTimeout,
            ]);

            $body = (string) $response->getBody();

            if ($response->getStatusCode() >= 400) {
                throw new RuntimeException("Docker build fallita [{$response->getStatusCode()}]: {$body}");
            }

            $this->assertNoStreamError($body, 'Docker build fallita');
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }

            @unlink($tarPath);
        }
    }

    // --------------------------------------------------------------- container

    /** @param  array<string, mixed>  $config */
    public function createContainer(string $name, array $config): string
    {
        $result = $this->request('POST', '/containers/create?name='.rawurlencode($name), $config);

        return $result['Id'];
    }

    public function startContainer(string $id): void
    {
        $this->request('POST', "/containers/{$id}/start");
    }

    /** @return array<string, mixed> */
    public function inspectContainer(string $id): array
    {
        return $this->request('GET', "/containers/{$id}/json");
    }

    public function removeContainer(string $id, bool $force = true): void
    {
        $this->request('DELETE', "/containers/{$id}?force=".($force ? 'true' : 'false').'&v=true');
    }

    /**
     * Esegue un argv dichiarato dal profilo di audit nel solo container target.
     * Non e' esposto al modello: serve al bootstrap ripetibile della sandbox.
     *
     * @param  array<int, string>  $argv
     */
    public function execContainer(string $id, array $argv): int
    {
        $created = $this->request('POST', "/containers/{$id}/exec", [
            'AttachStdout' => false,
            'AttachStderr' => false,
            'Cmd' => $argv,
        ]);
        $execId = $created['Id'] ?? null;
        if (! is_string($execId) || $execId === '') {
            throw new RuntimeException('Docker non ha restituito l\'id dell\'exec di bootstrap.');
        }

        $this->request('POST', "/exec/{$execId}/start", ['Detach' => false, 'Tty' => false]);
        $result = $this->request('GET', "/exec/{$execId}/json");

        return (int) ($result['ExitCode'] ?? -1);
    }

    /** @return array<int, array<string, mixed>> */
    public function listContainersByLabel(string $labelKey, ?string $labelValue = null): array
    {
        $filterValue = $labelValue !== null ? "{$labelKey}={$labelValue}" : $labelKey;
        $filters = json_encode(['label' => [$filterValue]]);

        /** @var array<int, array<string, mixed>> $result */
        $result = $this->request('GET', '/containers/json?all=true&filters='.rawurlencode((string) $filters));

        return $result;
    }

    // ------------------------------------------------------------ rete/volumi

    /** @param  array<string, string>  $labels */
    public function createNetwork(string $name, array $labels = []): string
    {
        $result = $this->request('POST', '/networks/create', [
            'Name' => $name,
            'Driver' => 'bridge',
            'Labels' => $labels,
        ]);

        return $result['Id'];
    }

    public function removeNetwork(string $nameOrId): void
    {
        $this->request('DELETE', '/networks/'.rawurlencode($nameOrId));
    }

    public function pruneVolumesByLabel(string $labelKey, ?string $labelValue = null): void
    {
        $filterValue = $labelValue !== null ? "{$labelKey}={$labelValue}" : $labelKey;
        $filters = json_encode(['label' => [$filterValue]]);

        $this->request('POST', '/volumes/prune?filters='.rawurlencode((string) $filters));
    }

    // ------------------------------------------------------------------ interni

    /** @return array{0: string, 1: string} */
    private function splitImageTag(string $image): array
    {
        // attenzione ai registry con porta (localhost:5000/foo): il tag sta
        // dopo l'ultimo "/", quindi cerchiamo i ":" solo da lì in avanti
        $lastSlash = strrpos($image, '/');
        $offset = $lastSlash === false ? 0 : $lastSlash + 1;

        $position = strrpos($image, ':', $offset);

        if ($position === false) {
            return [$image, 'latest'];
        }

        return [substr($image, 0, $position), substr($image, $position + 1)];
    }

    /**
     * L'API di build e di pull rispondono 200 anche quando falliscono: l'errore
     * arriva dentro lo stream JSON line-delimited.
     */
    private function assertNoStreamError(string $body, string $prefix): void
    {
        foreach (explode("\n", trim($body)) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $data = json_decode($line, true);

            if (is_array($data) && isset($data['error'])) {
                throw new RuntimeException("{$prefix}: {$data['error']}");
            }
        }
    }

    /** Impacchetta il context in un tar temporaneo e ne restituisce il path. */
    private function packContext(string $contextPath, string $dockerfile): string
    {
        $base = rtrim(str_replace('\\', '/', (string) realpath($contextPath)), '/');
        $patterns = array_merge($this->buildExclude, $this->dockerIgnorePatterns($base));
        $dockerfile = ltrim(str_replace('\\', '/', $dockerfile), '/');

        $tarPath = sys_get_temp_dir().'/lailaps-context-'.bin2hex(random_bytes(8)).'.tar';

        try {
            $phar = new PharData($tarPath);

            $files = new RecursiveIteratorIterator(
                new RecursiveCallbackFilterIterator(
                    new RecursiveDirectoryIterator(
                        $base,
                        FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS
                    ),
                    function (SplFileInfo $file) use ($base, $patterns, $dockerfile): bool {
                        $relative = ltrim(substr($file->getPathname(), strlen($base)), '/');

                        // Docker deve sempre ricevere il Dockerfile scelto, anche se
                        // il .dockerignore del progetto lo elenca (come Juice Shop).
                        // Manteniamo anche le directory antenate per non interrompere
                        // l'iteratore prima di arrivare a un Dockerfile annidato.
                        if ($relative === $dockerfile || str_starts_with($dockerfile, "{$relative}/")) {
                            return true;
                        }

                        return ! $this->isIgnored($relative, $patterns);
                    }
                )
            );

            $phar->buildFromIterator($files, $base);

            // su Windows PharData tiene il file aperto: senza unset l'unlink fallisce
            unset($phar);
        } catch (Throwable $e) {
            @unlink($tarPath);

            throw new RuntimeException("Impossibile creare il context tar: {$e->getMessage()}", previous: $e);
        }

        return $tarPath;
    }

    /** @return array<int, string> */
    private function dockerIgnorePatterns(string $base): array
    {
        $file = "{$base}/.dockerignore";

        if (! is_file($file)) {
            return [];
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        return array_values(array_filter(array_map(
            static fn (string $line): string => trim($line),
            $lines
        ), static fn (string $line): bool => $line !== ''
            && ! str_starts_with($line, '#')));
    }

    /** @param  array<int, string>  $patterns */
    private function isIgnored(string $relative, array $patterns): bool
    {
        $ignored = false;
        foreach ($patterns as $pattern) {
            $negated = str_starts_with($pattern, '!');
            $pattern = trim(str_replace('\\', '/', $negated ? substr($pattern, 1) : $pattern), '/');

            if ($pattern === '') {
                continue;
            }

            if ($relative === $pattern || str_starts_with($relative, "{$pattern}/")) {
                $ignored = ! $negated;

                continue;
            }

            if (fnmatch($pattern, $relative) || fnmatch("{$pattern}/*", $relative)) {
                // Docker applica le regole nell'ordine del file: una negazione
                // successiva reinclude un path escluso in precedenza.
                $ignored = ! $negated;
            }
        }

        return $ignored;
    }
}
