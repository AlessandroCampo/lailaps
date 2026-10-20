<?php

namespace App\DTOs\AuditRun;

use App\Enums\AuditRunAreaStatus;
use App\Enums\AuditRunStatus;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use JsonSerializable;

final readonly class RunOutcome implements Arrayable, JsonSerializable
{
    public function __construct(
        public string $runId,
        public ?string $projectSourceId,
        public ?string $sourceHash,
        public AuditRunStatus $status,
        public ?string $reason,
        public ?string $terminationReason,
        public ?bool $coverageComplete,
        public array $inputArtifacts,
        public array $areas,
        public array $leads,
        public array $deferredLeads,
        public int $rejectedLeadsCount,
        public array $usage,
        public array $diagnostics,
    ) {}

    public static function fromArray(array $outcome): self
    {
        if (($outcome['schema_version'] ?? null) !== 1
            || ! is_string($outcome['run_id'] ?? null) || $outcome['run_id'] === ''
            || ! is_string($outcome['run_status'] ?? null)
            || (isset($outcome['report']) && ! is_array($outcome['report']))) {
            throw new InvalidArgumentException('Invalid prepared run outcome identity or schema version.');
        }

        $status = AuditRunStatus::from($outcome['run_status']);
        $finished = ! in_array($status, [AuditRunStatus::PENDING, AuditRunStatus::RUNNING], true);
        $report = $outcome['report'] ?? [];
        $contexts = [];
        foreach ($report['passes'] ?? [] as $pass) {
            if (is_array($pass['report'] ?? null)) {
                $contexts[] = ['pass_id' => $pass['pass_id'], 'report' => $pass['report'], 'finished' => ($pass['status'] ?? null) === 'finished' || $finished];
            }
        }
        if ($contexts === []) {
            $contexts[] = ['pass_id' => null, 'report' => $report, 'finished' => $finished];
        }

        $areas = [];
        $deferred = [];
        $discardedReaderOutputs = 0;
        foreach ($contexts as $context) {
            $current = $context['report'];
            $coverage = $current['coverage'] ?? [];
            $readers = [];
            foreach (data_get($current, 'global_pipeline.agents', []) as $actor) {
                if (($actor['role'] ?? null) === 'reader' && isset($actor['area_id'])) {
                    $readers[$actor['area_id']] = $actor;
                }
            }
            $areaRows = $coverage['area_ledger'] ?? [];
            foreach ($areaRows as $area) {
                $areaKey = $area['area_id'];
                $areas[] = AreaOutcome::fromArray(
                    $area,
                    $context['pass_id'],
                    $readers[$areaKey] ?? [],
                    $coverage['reader_area_checkpoints'][$areaKey] ?? [],
                    $context['finished'],
                );
            }

            foreach ($current['structured_outputs'] ?? [] as $output) {
                if (($output['output_type'] ?? null) !== 'ReaderLead' || ($output['accepted'] ?? false)) {
                    continue;
                }
                if (! str_starts_with($output['rejection_reason'] ?? '', 'not_admitted_')) {
                    $discardedReaderOutputs++;

                    continue;
                }
                $areaKey = null;
                foreach ($areaRows as $area) {
                    if (str_starts_with($output['output_id'], 'reader-'.$area['area_id'].':')) {
                        $areaKey = $area['area_id'];
                        break;
                    }
                }
                $proposal = LeadOutcome::fromDeferredOutput($output, $context['pass_id'], $areaKey);
                $deferred[$proposal->id] = $proposal;
            }
        }

        $hasAggregatedLeads = array_key_exists('confirmed', $report) || array_key_exists('suspected', $report) || array_key_exists('rejected_inconclusive', $report);
        $leadContexts = $hasAggregatedLeads ? [['pass_id' => null, 'report' => $report]] : $contexts;
        $leads = [];
        $rejectedIds = [];
        foreach ($leadContexts as $leadContext) {
            foreach (['confirmed', 'suspected', 'rejected_inconclusive'] as $bucket) {
                foreach ($leadContext['report'][$bucket] ?? [] as $row) {
                    $row['status'] ??= match ($bucket) {
                        'confirmed' => 'confirmed',
                        'suspected' => 'suspected',
                        default => 'inconclusive',
                    };
                    $passId = $row['pass_id'] ?? $leadContext['pass_id'];
                    $transactions = [];
                    foreach ($contexts as $context) {
                        if ($context['pass_id'] === $passId) {
                            $transactions = data_get($context['report'], 'evidence_ledger.http_transactions', []);
                            break;
                        }
                    }
                    $lead = LeadOutcome::fromArray($row, $passId, $transactions);
                    if (in_array($lead->status, ['rejected', 'closed', 'duplicate', 'superseded'], true)) {
                        $rejectedIds[$lead->id] = true;
                    } else {
                        $leads[$lead->id] = $lead;
                    }
                }
            }
        }

        $telemetry = $report['telemetry'] ?? [];
        $usage = [];
        foreach ([
            'model_requests' => 'model_requests',
            'input_tokens' => 'total_model_input_tokens',
            'output_tokens' => 'total_model_output_tokens',
            'points_used' => 'economic_points_used',
            'point_limit' => 'economic_point_limit',
            'estimated_cost_usd' => 'estimated_cost_usd',
            'provider_cost_usd' => 'provider_cost_usd',
            'cost_accounting_complete' => 'cost_accounting_complete',
        ] as $name => $key) {
            if (array_key_exists($key, $telemetry)) {
                $usage[$name] = $telemetry[$key];
            }
        }

        return new self(
            runId: $outcome['run_id'],
            projectSourceId: $outcome['project_source_id'] ?? null,
            sourceHash: $outcome['source_hash'] ?? null,
            status: $status,
            reason: $outcome['run_reason'] ?? null,
            terminationReason: $report['termination_reason'] ?? null,
            coverageComplete: $report['coverage']['complete'] ?? null,
            inputArtifacts: Arr::only($outcome['input_artifacts'] ?? [], ['static_analysis', 'code_index', 'dependency_analysis', 'recon']),
            areas: $areas,
            leads: array_values($leads),
            deferredLeads: array_values($deferred),
            rejectedLeadsCount: count($rejectedIds),
            usage: $usage,
            diagnostics: [
                'blockers' => self::diagnostics($report['blockers'] ?? []),
                'errors' => self::diagnostics($report['fatal_errors'] ?? []),
                'discarded_reader_outputs' => $discardedReaderOutputs,
            ],
        );
    }

    private static function diagnostics(array $items): array
    {
        return array_map(fn (mixed $item): mixed => is_array($item)
            ? Arr::only($item, ['reference', 'scope', 'exception_type', 'active_role', 'active_lead_id', 'type', 'code', 'message', 'error', 'reason'])
            : $item, $items);
    }

    public function toArray(): array
    {
        $areaCounts = array_fill_keys(array_column(AuditRunAreaStatus::cases(), 'value'), 0);
        foreach ($this->areas as $area) {
            $areaCounts[$area->status->value]++;
        }

        return [
            'run_id' => $this->runId,
            'project_source_id' => $this->projectSourceId,
            'source_hash' => $this->sourceHash,
            'status' => $this->status->value,
            'reason' => $this->reason,
            'termination_reason' => $this->terminationReason,
            'coverage_complete' => $this->coverageComplete,
            'input_artifacts' => $this->inputArtifacts,
            'summary' => [
                'areas' => $areaCounts,
                'leads' => array_count_values(array_map(fn (LeadOutcome $lead): string => $lead->status, $this->leads)),
                'deferred_leads' => count($this->deferredLeads),
                'rejected_leads' => $this->rejectedLeadsCount,
            ],
            'usage' => $this->usage,
            'areas' => array_map(fn (AreaOutcome $area): array => $area->toArray(), $this->areas),
            'leads' => array_map(fn (LeadOutcome $lead): array => $lead->toArray(), $this->leads),
            'deferred_leads' => array_map(fn (LeadOutcome $lead): array => $lead->toArray(), $this->deferredLeads),
            'diagnostics' => $this->diagnostics,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
