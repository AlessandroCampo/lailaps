<?php

namespace App\Services\Target\DTO;

use App\Services\Target\Enums\ProbeStatus;

final class ProbeResultDTO
{
    public function __construct(
        public string $name,
        public ProbeStatus $status,
        public string $detail,
        public int $elapsedMs = 0,
    ) {}

    public static function pass(string $name, string $detail, int $elapsedMs = 0): self
    {
        return new self($name, ProbeStatus::PASSED, $detail, $elapsedMs);
    }

    public static function warn(string $name, string $detail, int $elapsedMs = 0): self
    {
        return new self($name, ProbeStatus::WARNING, $detail, $elapsedMs);
    }

    public static function fail(string $name, string $detail, int $elapsedMs = 0): self
    {
        return new self($name, ProbeStatus::FAILED, $detail, $elapsedMs);
    }

    public function hasFailed(): bool
    {
        return $this->status === ProbeStatus::FAILED;
    }

    /** Riga pronta per il report: "ok — HTTP 200 (142ms)". */
    public function line(): string
    {
        return "{$this->status->mark()} — {$this->detail} ({$this->elapsedMs}ms)";
    }
}
