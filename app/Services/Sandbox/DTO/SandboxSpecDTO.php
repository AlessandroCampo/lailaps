<?php

namespace App\Services\Sandbox\DTO;

use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Descrive *cosa* avviare, mai *come*: la scelta del driver è del service.
 *
 * L'origine del container è dichiarata in ordine di precedenza da $image e
 * $dockerfile; se entrambe sono nulle il driver ricade sulla configurazione.
 */
final class SandboxSpecDTO
{
    public function __construct(
        public string $auditId,
        public string $projectPath,
        public int $ttlSeconds = 1800,
        /** Override del servizio HTTP, scavalca l'euristica del resolver. */
        public ?string $webService = null,
        public int $healthTimeout = 60,
        /** Immagine già pronta (viene pullata se assente in locale). */
        public ?string $image = null,
        /** Dockerfile relativo a projectPath; default "Dockerfile". */
        public ?string $dockerfile = null,
        /** Se valorizzato, projectPath viene montato qui dentro al container. */
        public ?string $mountPath = null,
        /** Porta interna da pubblicare; se nulla si legge da ExposedPorts. */
        public ?int $port = null,
        /** @var array<string, string> */
        public array $buildArgs = [],
        /** @var array<string, string> */
        public array $env = [],
        /** @var array<string, mixed> */
        public array $options = [],
        /** Compose esterno alla codebase, usato dai runtime benchmark controllati. */
        public ?string $composeFile = null,
    ) {
        if (trim($auditId) === '') {
            throw new InvalidArgumentException('auditId non può essere vuoto');
        }

        if (! is_dir($projectPath)) {
            throw new InvalidArgumentException("projectPath inesistente: {$projectPath}");
        }

        if ($ttlSeconds < 1) {
            throw new InvalidArgumentException('ttlSeconds deve essere positivo');
        }

        $this->projectPath = rtrim(str_replace('\\', '/', (string) realpath($projectPath)), '/');

        if ($composeFile !== null) {
            $resolved = realpath($composeFile);
            if ($resolved === false || ! is_file($resolved)) {
                throw new InvalidArgumentException("composeFile inesistente: {$composeFile}");
            }
            $this->composeFile = str_replace('\\', '/', $resolved);
        }
    }

    public function projectName(): string
    {
        return "audit-{$this->auditId}";
    }

    public function expiresAt(): CarbonInterface
    {
        return now()->addSeconds($this->ttlSeconds);
    }

    /** Path assoluto del Dockerfile del progetto, se esiste. */
    public function dockerfilePath(): ?string
    {
        $candidate = "{$this->projectPath}/".($this->dockerfile ?? 'Dockerfile');

        return is_file($candidate) ? $candidate : null;
    }

    /** Nome del Dockerfile relativo al context, come lo vuole l'API di build. */
    public function dockerfileName(): string
    {
        return $this->dockerfile ?? 'Dockerfile';
    }
}
