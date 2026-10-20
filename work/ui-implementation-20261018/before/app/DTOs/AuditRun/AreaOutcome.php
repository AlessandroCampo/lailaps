<?php

namespace App\DTOs\AuditRun;

use App\Enums\AuditRunAreaStatus;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Arr;
use JsonSerializable;

final readonly class AreaOutcome implements Arrayable, JsonSerializable
{
    public function __construct(
        public ?string $passId,
        public string $areaKey,
        public string $title,
        public string $origin,
        public AuditRunAreaStatus $status,
        public ?string $reason,
        public array $paths,
        public string $nextCheck,
        public array $checkpoint,
    ) {}

    public static function fromArray(array $area, ?string $passId, array $reader, array $checkpoint, bool $finished): self
    {
        $reason = $reader['termination_reason'] ?? null;
        $status = match (true) {
            ($area['status'] ?? null) === 'closed' => AuditRunAreaStatus::COMPLETED,
            ($reader['status'] ?? null) === 'failed',
            in_array($reason, ['fatal_error', 'model_unavailable', 'partial_failure'], true) => AuditRunAreaStatus::FAILED,
            ($reader['status'] ?? null) === 'not_started_budget' => AuditRunAreaStatus::PENDING,
            ($area['status'] ?? null) === 'queued' => AuditRunAreaStatus::PENDING,
            $finished => AuditRunAreaStatus::PARTIAL,
            default => AuditRunAreaStatus::RUNNING,
        };
        if (($reader['status'] ?? null) === 'not_started_budget') {
            $reason ??= 'not_started_budget';
        }

        return new self(
            passId: $passId,
            areaKey: $area['area_id'],
            title: $area['title'],
            origin: $area['origin'] ?? 'category_recon',
            status: $status,
            reason: $reason,
            paths: $area['paths'] ?? [],
            nextCheck: ! empty($checkpoint['next_action']) ? $checkpoint['next_action'] : ($area['next_check'] ?? ''),
            checkpoint: Arr::only($checkpoint, ['summary', 'checked_surfaces', 'open_branches', 'source_ref_ids']),
        );
    }

    public function toArray(): array
    {
        return [
            'pass_id' => $this->passId,
            'area_key' => $this->areaKey,
            'title' => $this->title,
            'origin' => $this->origin,
            'status' => $this->status->value,
            'reason' => $this->reason,
            'paths' => $this->paths,
            'next_check' => $this->nextCheck,
            'checkpoint' => $this->checkpoint,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
