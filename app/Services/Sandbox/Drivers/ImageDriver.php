<?php

namespace App\Services\Sandbox\Drivers;

use App\Services\Sandbox\Contracts\SandboxDriver;
use App\Services\Sandbox\DockerClient;
use App\Services\Sandbox\DTO\SandboxSpecDTO;
use App\Services\Sandbox\Support\SandboxLabels;
use RuntimeException;

/**
 * Avvia un singolo container, ricavando l'immagine in quest'ordine:
 *
 *   1. immagine dichiarata nello spec (pullata se non presente in locale)
 *   2. Dockerfile trovato nel projectPath (build usa il progetto come context)
 *   3. immagine di fallback configurata in config/sandbox.php
 *
 * Nulla qui è legato a uno stack specifico: porta e mount vengono dallo spec
 * o, in mancanza, letti dall'immagine stessa.
 */
class ImageDriver implements SandboxDriver
{
    public function __construct(protected DockerClient $docker) {}

    public function name(): string
    {
        return 'image';
    }

    /** Catch-all: va registrato per ultimo nella lista dei driver. */
    public function supports(SandboxSpecDTO $spec): bool
    {
        return true;
    }

    public function boot(SandboxSpecDTO $spec): array
    {
        $source = $this->resolveSource($spec);
        $port = $this->resolvePort($spec, $source);

        $projectName = $spec->projectName();
        $networkName = $this->networkName($spec->auditId);
        $labels = SandboxLabels::for($spec, $this->name(), service: 'app');

        $this->docker->createNetwork($networkName, $labels);

        $limits = config('sandbox.limits');

        $containerId = $this->docker->createContainer($projectName, [
            'Image' => $source['image'],
            'Labels' => $labels,
            'Env' => $this->formatEnv($spec->env),
            'ExposedPorts' => ["{$port}/tcp" => new \stdClass],
            'HostConfig' => [
                'Binds' => $this->binds($spec, $source),
                'NetworkMode' => $networkName,
                'PortBindings' => ["{$port}/tcp" => [['HostPort' => '']]],
                'Memory' => $limits['memory'],
                'NanoCpus' => $limits['nano_cpus'],
                'PidsLimit' => $limits['pids'],
                'CapDrop' => ['ALL'],
                'SecurityOpt' => ['no-new-privileges'],
                'RestartPolicy' => ['Name' => 'no'],
            ],
        ]);

        $this->docker->startContainer($containerId);

        return $this->docker->listContainersByLabel(SandboxLabels::AUDIT_ID, $spec->auditId);
    }

    public function destroy(string $auditId): void
    {
        foreach ($this->docker->listContainersByLabel(SandboxLabels::AUDIT_ID, $auditId) as $container) {
            rescue(fn () => $this->docker->removeContainer($container['Id']), report: false);
        }

        rescue(fn () => $this->docker->removeNetwork($this->networkName($auditId)), report: false);

        // l'immagine buildata dal Dockerfile del progetto è usa e getta
        rescue(fn () => $this->docker->removeImage($this->disposableTag($auditId)), report: false);
    }

    // ------------------------------------------------------------------ interni

    /**
     * @return array{image: string, mount: ?string, port: ?int}
     */
    private function resolveSource(SandboxSpecDTO $spec): array
    {
        if ($spec->image !== null) {
            $this->docker->ensureImagePresent($spec->image);

            return ['image' => $spec->image, 'mount' => null, 'port' => null];
        }

        if ($spec->dockerfilePath() !== null) {
            $tag = $this->disposableTag($spec->auditId);

            $this->docker->buildImage(
                contextPath: $spec->projectPath,
                tag: $tag,
                buildArgs: $spec->buildArgs,
                dockerfile: $spec->dockerfileName(),
            );

            return ['image' => $tag, 'mount' => null, 'port' => null];
        }

        return $this->fallbackSource($spec);
    }

    /** @return array{image: string, mount: ?string, port: ?int} */
    private function fallbackSource(SandboxSpecDTO $spec): array
    {
        $fallback = config('sandbox.fallback');

        if (! is_array($fallback) || empty($fallback['tag'])) {
            throw new RuntimeException(
                "Nessuna immagine per {$spec->projectPath}: indica 'image' nello spec, "
                .'aggiungi un Dockerfile al progetto oppure configura sandbox.fallback'
            );
        }

        if (! $this->docker->imageExists($fallback['tag'])) {
            $this->docker->buildImage(
                contextPath: $fallback['context'],
                tag: $fallback['tag'],
                buildArgs: $fallback['build_args'] ?? [],
            );
        }

        return [
            'image' => $fallback['tag'],
            'mount' => $fallback['mount_path'] ?? null,
            'port' => isset($fallback['port']) ? (int) $fallback['port'] : null,
        ];
    }

    /**
     * Lo spec vince; poi il default della sorgente; infine la prima porta TCP
     * esposta dall'immagine.
     *
     * @param  array{image: string, mount: ?string, port: ?int}  $source
     */
    private function resolvePort(SandboxSpecDTO $spec, array $source): int
    {
        if ($spec->port !== null) {
            return $spec->port;
        }

        if ($source['port'] !== null) {
            return $source['port'];
        }

        $exposed = array_keys($this->docker->inspectImage($source['image'])['Config']['ExposedPorts'] ?? []);

        foreach ($exposed as $definition) {
            [$number, $protocol] = array_pad(explode('/', (string) $definition, 2), 2, 'tcp');

            if ($protocol === 'tcp') {
                return (int) $number;
            }
        }

        throw new RuntimeException(
            "L'immagine '{$source['image']}' non espone porte TCP: indica 'port' nello spec"
        );
    }

    /**
     * Il mount serve solo alle immagini che si aspettano il codice dall'esterno.
     * Se il container è stato buildato dal Dockerfile del progetto, il codice
     * è già dentro e montarlo sopra lo sovrascriverebbe.
     *
     * @param  array{image: string, mount: ?string, port: ?int}  $source
     * @return array<int, string>
     */
    private function binds(SandboxSpecDTO $spec, array $source): array
    {
        $mount = $spec->mountPath ?? $source['mount'];

        return $mount !== null ? ["{$spec->projectPath}:{$mount}"] : [];
    }

    /**
     * @param  array<string, string>  $env
     * @return array<int, string>
     */
    private function formatEnv(array $env): array
    {
        $formatted = [];

        foreach ($env as $key => $value) {
            $formatted[] = "{$key}={$value}";
        }

        return $formatted;
    }

    private function networkName(string $auditId): string
    {
        return "audit-{$auditId}-net";
    }

    private function disposableTag(string $auditId): string
    {
        return SandboxLabels::OWNER."-sandbox-audit-{$auditId}";
    }
}
