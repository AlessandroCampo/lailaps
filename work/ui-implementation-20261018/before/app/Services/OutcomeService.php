<?php

namespace App\Services;

use App\Actions\CreateAuditRunAreaAction;
use App\Actions\CreateAuditRunLeadAction;
use App\DTOs\AuditRun\LeadOutcome;
use App\DTOs\AuditRun\RunOutcome;
use App\Enums\AuditRunStatus;
use App\Models\AuditRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use JsonException;
use RuntimeException;
use SplFileObject;
use Throwable;
use TypeError;

final class OutcomeService
{
    public function __construct(
        private CreateAuditRunAreaAction $createArea,
        private CreateAuditRunLeadAction $createLead,
        private FileService $files,
    ) {}

    public function progress(AuditRun $run): array
    {
        $active = in_array($run->status, [AuditRunStatus::PENDING, AuditRunStatus::RUNNING], true);
        $response = [
            'run_id' => $run->id,
            'status' => $run->status->value,
            'started_at' => $run->started_at?->toISOString(),
            'finished_at' => $run->finished_at?->toISOString(),
            'duration_seconds' => $run->started_at === null ? null : max(0, (int) $run->started_at->diffInSeconds($run->finished_at ?? now())),
            'poll_after_seconds' => $active ? 30 : null,
            'snapshot_state' => $active ? 'waiting' : 'unavailable',
            'snapshot_source' => null,
            'snapshot_updated_at' => null,
            'snapshot' => null,
        ];

        if (! $active && $run->outcome !== null) {
            $payload = $run->outcome;
            $response['snapshot_source'] = 'database';
            $response['snapshot_updated_at'] = $run->updated_at?->toISOString();
        } else {
            $storage = Storage::disk('projects');
            $path = $run->path().'/output/outcome.json';
            try {
                if (! $storage->exists($path)) {
                    return $response;
                }
                $response['snapshot_source'] = 'filesystem';
                $raw = $run->getOutcome();
                $response['snapshot_updated_at'] = CarbonImmutable::createFromTimestamp($storage->lastModified($path))->toISOString();
            } catch (JsonException|TypeError) {
                $response['snapshot_state'] = 'invalid';

                return $response;
            } catch (Throwable) {
                $response['snapshot_state'] = 'unavailable';

                return $response;
            }
            if (($raw['schema_version'] ?? null) === 1 && ($raw['report'] ?? null) === null && ($raw['run_status'] ?? null) === 'running') {
                return $response;
            }
            try {
                $payload = RunOutcome::fromArray($raw)->toArray();
            } catch (Throwable) {
                $response['snapshot_state'] = 'invalid';

                return $response;
            }
        }

        try {
            if (($payload['run_id'] ?? null) !== (string) $run->id
                || ($payload['project_source_id'] ?? null) !== $run->project_source_id
                || ($payload['source_hash'] ?? null) !== $run->source?->content_hash) {
                throw new RuntimeException('The snapshot does not belong to this run and source.');
            }
            $snapshotStatus = AuditRunStatus::from($payload['status']);
            $response['snapshot'] = [
                'status' => $snapshotStatus->value,
                'provisional' => $active || in_array($snapshotStatus, [AuditRunStatus::PENDING, AuditRunStatus::RUNNING], true),
                ...Arr::only($payload, ['termination_reason', 'coverage_complete', 'summary', 'usage']),
                'areas' => array_map(fn (array $area): array => Arr::only($area, ['pass_id', 'area_key', 'title', 'status', 'reason']), $payload['areas']),
                'leads' => array_map(fn (array $lead): array => Arr::only($lead, ['id', 'pass_id', 'area_key', 'title', 'status', 'severity']), $payload['leads']),
            ];
            $response['snapshot_state'] = 'available';
        } catch (Throwable) {
            $response['snapshot_state'] = 'invalid';
        }

        return $response;
    }

    public function persist(AuditRun $run, RunOutcome $outcome): AuditRun
    {
        return DB::transaction(function () use ($run, $outcome): AuditRun {
            $run = AuditRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            $run->loadMissing(['source', 'artifacts.reconAreas']);
            $this->assertIdentity($run, $outcome);
            if (! in_array($outcome->status, [AuditRunStatus::COMPLETED, AuditRunStatus::PARTIAL, AuditRunStatus::FAILED], true)) {
                throw new RuntimeException('Only terminal audit outcomes can be imported.');
            }

            foreach ($outcome->inputArtifacts as $type => $id) {
                if ($id !== null && ! $run->artifacts->contains(fn ($artifact): bool => $artifact->type->value === $type && $artifact->id === $id)) {
                    throw new RuntimeException('The outcome references an artifact not attached to this run.');
                }
            }
            foreach ($outcome->areas as $area) {
                $this->createArea->handle($run, $area);
            }
            foreach ([...$outcome->leads, ...$outcome->deferredLeads] as $lead) {
                $this->createLead->handle($run, $lead);
            }
            $run->update(['outcome' => $outcome->toArray()]);

            return $run;
        });
    }

    private function assertIdentity(AuditRun $run, RunOutcome $outcome): void
    {
        if ($outcome->runId !== (string) $run->id
            || $outcome->projectSourceId !== $run->project_source_id
            || $outcome->sourceHash !== $run->source?->content_hash) {
            throw new RuntimeException('The outcome does not belong to this run and source.');
        }
    }

    public function getEnrichedPayloadWithSnippets(AuditRun $run, RunOutcome $outcome): array
    {
        $this->assertIdentity($run, $outcome);
        $raw = $this->readRawOutcome($run);
        $payload = $outcome->toArray();
        $payload['leads'] = array_map(fn (LeadOutcome $lead): array => $this->enrichLead($run, $lead, $raw), $outcome->leads);
        $payload['deferred_leads'] = array_map(fn (LeadOutcome $lead): array => $this->enrichLead($run, $lead, $raw), $outcome->deferredLeads);

        return $payload;
    }

    public function presentLead(AuditRun $run, LeadOutcome $lead, bool $includeToolCalls = false): array
    {
        $payload = $this->enrichLead($run, $lead, $this->readRawOutcome($run));
        if ($includeToolCalls) {
            $payload['evidence']['tool_calls'] = $this->readToolCalls($run, $lead);
        }

        return $payload;
    }

    private function enrichLead(AuditRun $run, LeadOutcome $lead, ?array $raw): array
    {
        $payload = $lead->toArray();
        $contents = [];
        $sourceRoot = $run->source === null ? null : Storage::disk('projects')->path($run->source->sourcePath());
        foreach ($payload['locations'] as &$location) {
            $location['snippet'] = $this->getCodeSnippet($sourceRoot, $location, $contents);
        }
        unset($location);

        $report = $raw['report'] ?? [];
        if ($lead->passId !== null) {
            $pass = collect($report['passes'] ?? [])->firstWhere('pass_id', $lead->passId);
            $report = $pass['report'] ?? [];
        }
        $transactions = data_get($report, 'evidence_ledger.http_transactions', []);
        $payload['evidence']['http_transactions'] = [];
        foreach ($lead->evidence['http_refs'] ?? [] as $reference) {
            $transaction = collect($transactions)->first(fn (array $row): bool => ($row['request_id'] ?? null) === ($reference['request_id'] ?? null)
                && ($row['response_id'] ?? null) === ($reference['response_id'] ?? null));
            if ($transaction === null) {
                $payload['evidence']['http_transactions'][] = [...$reference, 'available' => false, 'reason' => 'HTTP evidence is not available.'];

                continue;
            }
            $request = $this->excerpt($transaction['request'] ?? []);
            $payload['evidence']['http_transactions'][] = [
                ...Arr::only($transaction, ['request_id', 'response_id', 'method', 'path', 'status', 'actor', 'content_type', 'location', 'body_excerpt', 'body_truncated', 'grep_excerpt', 'grep_truncated']),
                'available' => true,
                'request_excerpt' => $request['content'],
                'request_truncated' => $request['truncated'],
            ];
        }

        return $payload;
    }

    private function getCodeSnippet(?string $root, array $location, array &$contents): array
    {
        $path = $this->within($root, $location['file'] ?? '');
        $line = (int) ($location['line'] ?? 0);
        if ($path === null || $line < 1) {
            return ['available' => false, 'reason' => 'The source location is not available.'];
        }
        try {
            $contents[$path] ??= File::get($path);
            $lineCount = count(preg_split('/\r\n|\r|\n/', $contents[$path]));
            if ($line > $lineCount) {
                return ['available' => false, 'reason' => 'The source line is outside the file.'];
            }
            $start = max(1, $line - 2);
            $requestedEnd = max($line, (int) ($location['end_line'] ?? $line)) + 2;
            $end = min($lineCount, $requestedEnd, $start + 39);

            return [
                'available' => true,
                'start_line' => $start,
                'end_line' => $end,
                'content' => $this->files->getLines($contents[$path], $start, $end),
                'truncated' => $end < min($lineCount, $requestedEnd),
            ];
        } catch (Throwable) {
            return ['available' => false, 'reason' => 'The source file could not be read.'];
        }
    }

    private function within(?string $root, string $relative): ?string
    {
        if ($root === null || $relative === '' || str_contains($relative, "\0")
            || str_starts_with($relative, '/') || str_starts_with($relative, '\\')
            || preg_match('/^[A-Za-z]:/', $relative)) {
            return null;
        }
        $root = realpath($root);
        $path = $root === false ? false : realpath($root.'/'.$relative);

        return $path !== false && str_starts_with($path, $root.DIRECTORY_SEPARATOR) && is_file($path) && is_readable($path) ? $path : null;
    }

    private function readRawOutcome(AuditRun $run): ?array
    {
        try {
            return $run->getOutcome();
        } catch (Throwable) {
            return null;
        }
    }

    private function readToolCalls(AuditRun $run, LeadOutcome $lead): array
    {
        $root = Storage::disk('projects')->path($run->path().'/output');
        $path = $this->within($root, $lead->evidence['journal'] ?? '');
        if ($path === null) {
            return ['available' => false, 'items' => [], 'reason' => 'The investigator journal is not available.'];
        }
        $calls = [];
        $seen = [];
        $incomplete = false;
        try {
            foreach (new SplFileObject($path) as $line) {
                if (! is_string($line) || trim($line) === '') {
                    continue;
                }
                $event = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                $id = $event['call_id'] ?? null;
                if ($id === null || ! in_array($event['type'] ?? null, ['started', 'output', 'result', 'cancelled'], true)) {
                    continue;
                }
                $seen[$id] = true;
                if (! isset($calls[$id]) && count($calls) >= 20) {
                    continue;
                }
                $calls[$id] ??= ['call_id' => $id, 'tool' => $event['tool'] ?? null, 'output_ids' => []];
                if (($event['type'] ?? null) === 'started') {
                    $calls[$id]['arguments'] = $this->excerpt($event['arguments'] ?? []);
                } elseif (($event['type'] ?? null) === 'output') {
                    $calls[$id]['output_ids'][] = $event['output_id'];
                    $calls[$id]['output'] = $this->excerpt($event['result'] ?? '');
                } else {
                    $calls[$id]['status'] = $event['status'] ?? null;
                    $calls[$id]['result'] = $this->excerpt($event['result'] ?? $event['error'] ?? '');
                }
            }
        } catch (Throwable) {
            $incomplete = true;
        }

        return ['available' => true, 'items' => array_values($calls), 'total_calls' => count($seen), 'truncated' => $incomplete || count($seen) > count($calls)];
    }

    private function excerpt(mixed $value): array
    {
        $text = is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return ['content' => mb_substr($text, 0, 2_000), 'truncated' => mb_strlen($text) > 2_000];
    }
}
