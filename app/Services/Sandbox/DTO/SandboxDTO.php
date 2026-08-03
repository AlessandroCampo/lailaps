<?php

namespace App\Services\Sandbox\DTO;

use App\Services\Sandbox\Enums\SandboxStatus;
use App\Services\Target\Contracts\TestTarget;
use Carbon\CarbonInterface;

final class SandboxDTO implements TestTarget
{
    public function __construct(
        public string $auditId,
        public string $driver,
        public string $projectName,
        public string $containerId,
        public string $containerName,
        public string $serviceName,
        public string $networkName,
        public string $url,
        public int $hostPort,
        public CarbonInterface $expiresAt,
        public SandboxStatus $status = SandboxStatus::RUNNING,
    ) {}

    public function url(): string
    {
        return $this->url;
    }

    public function isExpired(): bool
    {
        return now()->greaterThanOrEqualTo($this->expiresAt);
    }

    public function isRunning(): bool
    {
        return $this->status === SandboxStatus::RUNNING;
    }

    public function summary(): array
    {
        // internamente le scadenze restano UTC: qui si converte solo per l'output
        $expiresAt = $this->expiresAt->setTimezone(config('sandbox.display_timezone'));
        $countdown = $this->expiresAt->diffForHumans(short: true, syntax: CarbonInterface::DIFF_ABSOLUTE);

        return [
            'driver' => $this->driver,
            'servizio' => $this->serviceName,
            'network' => $this->networkName,
            'scade' => "{$expiresAt->toTimeString()} {$expiresAt->format('T')} (tra {$countdown})",
        ];
    }

    public function markDead(): void
    {
        $this->status = SandboxStatus::DEAD;
    }
}
