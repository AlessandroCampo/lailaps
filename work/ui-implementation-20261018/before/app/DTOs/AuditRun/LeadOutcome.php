<?php

namespace App\DTOs\AuditRun;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use JsonSerializable;

final readonly class LeadOutcome implements Arrayable, JsonSerializable
{
    public function __construct(
        public string $id,
        public ?string $passId,
        public ?string $areaKey,
        public ?string $readerOutputId,
        public string $status,
        public ?string $lifecycleStatus,
        public string $title,
        public ?string $severity,
        public ?string $confidence,
        public string $description,
        public ?string $conclusion,
        public ?string $reason,
        public ?string $recommendation,
        public ?string $category,
        public array $cweIds,
        public array $locations,
        public array $evidence,
    ) {}

    public static function fromArray(array $lead, ?string $passId = null, array $transactions = []): self
    {
        $id = $lead['lead_id'] ?? $lead['finding_id'] ?? null;
        if (! is_string($id) || $id === '') {
            throw new InvalidArgumentException('A lead outcome requires a runtime identifier.');
        }

        $passId = $lead['pass_id'] ?? $passId;
        $localId = $lead['source_finding_id'] ?? $id;
        if ($passId !== null && str_starts_with($localId, $passId.':')) {
            $localId = substr($localId, strlen($passId) + 1);
        }
        if ($passId !== null && ! str_starts_with($id, $passId.':')) {
            $id = $passId.':'.$id;
        }

        $conclusion = self::text(data_get($lead, 'investigator_output.narrative'))
            ?? self::text(data_get($lead, 'adjudication.narrative'))
            ?? self::text($lead['reason'] ?? null)
            ?? self::text(data_get($lead, 'exploit.narrative'));
        $reason = self::text(data_get($lead, 'lead_investigation.stop_reason')) ?? self::text($lead['reason'] ?? null);
        if ($reason === $conclusion) {
            $reason = null;
        }

        $locations = [];
        foreach ([$lead['primary_location'] ?? null, ...($lead['locations'] ?? [])] as $location) {
            if (! is_array($location) || empty($location['file'])) {
                continue;
            }
            $location = Arr::only($location, ['file', 'line', 'end_line']);
            $locations[json_encode($location, JSON_THROW_ON_ERROR)] = $location;
        }

        $httpRefs = [];
        foreach ($transactions as $transaction) {
            if (($transaction['lead_id'] ?? null) === $localId) {
                $httpRefs[] = Arr::only($transaction, ['request_id', 'response_id']);
            }
        }

        return new self(
            id: $id,
            passId: $passId,
            areaKey: $lead['origin_area_id'] ?? null,
            readerOutputId: $lead['origin_reader_output_id'] ?? null,
            status: $lead['status'] ?? 'suspected',
            lifecycleStatus: $lead['lifecycle_status'] ?? null,
            title: $lead['title'] ?? '',
            severity: self::text($lead['severity'] ?? null),
            confidence: self::text($lead['confidence'] ?? null),
            description: $lead['description'] ?? '',
            conclusion: $conclusion,
            reason: $reason,
            recommendation: self::text($lead['recommendation'] ?? null),
            category: self::text($lead['owasp_category'] ?? null),
            cweIds: $lead['cwe_ids'] ?? [],
            locations: array_values($locations),
            evidence: [
                'source_refs' => array_values(array_unique([...($lead['source_ref_ids'] ?? []), ...($lead['worker_source_ref_ids'] ?? [])])),
                'http_refs' => $httpRefs,
                'journal' => data_get($lead, 'tool_trace.journal') ?? data_get($lead, 'exploit.tool_trace.journal'),
            ],
        );
    }

    public static function fromDeferredOutput(array $output, ?string $passId, ?string $areaKey): self
    {
        $payload = $output['payload'];
        $id = $output['output_id'];

        return new self(
            id: $passId !== null ? $passId.':'.$id : $id,
            passId: $passId,
            areaKey: $areaKey,
            readerOutputId: $id,
            status: 'pending',
            lifecycleStatus: 'not_admitted',
            title: $payload['title'] ?? '',
            severity: null,
            confidence: null,
            description: $payload['hypothesis'] ?? '',
            conclusion: null,
            reason: $output['rejection_reason'],
            recommendation: null,
            category: $payload['owasp_category'] ?? null,
            cweIds: [],
            locations: [],
            evidence: ['source_refs' => $payload['source_ref_ids'] ?? [], 'http_refs' => [], 'journal' => null],
        );
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'pass_id' => $this->passId,
            'area_key' => $this->areaKey,
            'reader_output_id' => $this->readerOutputId,
            'status' => $this->status,
            'lifecycle_status' => $this->lifecycleStatus,
            'title' => $this->title,
            'severity' => $this->severity,
            'confidence' => $this->confidence,
            'description' => $this->description,
            'conclusion' => $this->conclusion,
            'reason' => $this->reason,
            'recommendation' => $this->recommendation,
            'category' => $this->category,
            'cwe_ids' => $this->cweIds,
            'locations' => $this->locations,
            'evidence' => $this->evidence,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
