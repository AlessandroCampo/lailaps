<?php

namespace App\Http\Controllers;

use App\Enums\AuditRunStatus;
use App\Http\Requests\StoreAuditRunRequest;
use App\Jobs\ExecuteAuditRun;
use App\Models\AuditRun;
use App\Services\Audit\AuditCommandBuilder;
use App\Services\Audit\AuditRunFactory;
use App\Services\Audit\AuditRunQueueFailureReconciler;
use App\Services\Audit\RunStorage;
use App\Services\Pentest\BenchmarkCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AuditRunController extends Controller
{
    public function index(Request $request, AuditRunQueueFailureReconciler $queueFailures): Response
    {
        $runs = $request->user()->auditRuns()->latest()->get()
            ->each(fn (AuditRun $run) => $queueFailures->reconcile($run))
            ->map(fn (AuditRun $run) => $this->summary($run));

        return Inertia::render('audits/Index', ['runs' => $runs]);
    }

    public function create(BenchmarkCatalog $catalog): Response
    {
        return Inertia::render('audits/Create', [
            'presets' => collect(AuditCommandBuilder::PRESETS)->map(fn (array $preset, string $id) => [
                'id' => $id,
                'category' => $preset['category'] ?? '',
                'benchmark' => (bool) ($preset['benchmark'] ?? false),
            ])->values(),
            'benchmarks' => collect($catalog->all())
                ->groupBy('target_id')
                ->map(fn ($manifests, string $targetId) => [
                    'id' => $targetId,
                    'name' => $targetId,
                    'categories' => $manifests->pluck('category')->unique()->values(),
                ])
                ->values(),
        ]);
    }

    /** A focused operator console: it launches the same queued PentestRun flow. */
    public function console(BenchmarkCatalog $catalog): Response
    {
        return Inertia::render('audits/Console', [
            'run' => null,
            ...$this->consoleConfiguration($catalog),
        ]);
    }

    public function consoleIndex(Request $request, AuditRunQueueFailureReconciler $queueFailures): Response
    {
        $runs = $request->user()->auditRuns()->latest()->get()
            ->each(fn (AuditRun $run) => $queueFailures->reconcile($run))
            ->map(fn (AuditRun $run) => $this->summary($run, false));

        return Inertia::render('audits/ConsoleIndex', ['runs' => $runs]);
    }

    public function store(StoreAuditRunRequest $request, AuditRunFactory $factory): RedirectResponse
    {
        $run = $factory->create($request->user(), $request->normalized());
        ExecuteAuditRun::dispatch($run->id);

        return to_route('audits.show', $run);
    }

    public function launchFromConsole(StoreAuditRunRequest $request, AuditRunFactory $factory): RedirectResponse
    {
        $run = $factory->create($request->user(), $request->normalized());
        ExecuteAuditRun::dispatch($run->id);

        return to_route('audits.console.show', $run);
    }

    public function showConsole(Request $request, AuditRun $audit, AuditRunQueueFailureReconciler $queueFailures): Response
    {
        $this->authorizeRun($request, $audit);
        $queueFailures->reconcile($audit);

        return Inertia::render('audits/Console', [
            'run' => [
                ...$this->summary($audit, false),
                'parameters' => $audit->parameters,
                'error' => $audit->error,
                'cancellationRequested' => $audit->cancellation_requested,
            ],
            'initialSnapshot' => $this->runSnapshot($audit, false),
        ]);
    }

    public function show(Request $request, AuditRun $audit, AuditRunQueueFailureReconciler $queueFailures): Response
    {
        $this->authorizeRun($request, $audit);
        $queueFailures->reconcile($audit);
        $audit->load(['models', 'experiment', 'evaluations.cases', 'evaluations.adjudications']);

        return Inertia::render('audits/Show', [
            'run' => [...$this->summary($audit),
                'parameters' => $audit->parameters,
                'error' => $audit->error,
                'cancellationRequested' => $audit->cancellation_requested,
            ],
            'initialEvents' => $this->transcriptEvents($audit),
            'report' => $this->outcomePart($audit, 'report'),
            'benchmark' => $this->outcomePart($audit, 'benchmark'),
            'telemetry' => $this->telemetry($audit),
            'models' => $audit->models->map(fn ($model): array => [
                'role' => $model->role, 'requested' => $model->requested_model,
                'effective' => $model->effective_model, 'provider' => $model->provider,
                'reasoningEffort' => $model->reasoning_effort,
            ])->values(),
            'experiment' => $audit->experiment ? ['id' => $audit->experiment->id, 'name' => $audit->experiment->name] : null,
            'evaluations' => $audit->evaluations->map(fn ($evaluation): array => [
                'id' => $evaluation->id, 'benchmarkId' => $evaluation->benchmark_id,
                'status' => $evaluation->status, 'category' => $evaluation->category,
                'evaluatorVersion' => $evaluation->evaluator_version,
                'artifactState' => $evaluation->artifact_state,
                'runState' => $evaluation->run_state,
                'adjudicationState' => $evaluation->adjudication_state,
                'environmentState' => $evaluation->environment_state,
                'terminationReason' => data_get($evaluation->payload, 'termination_reason'),
                'score' => $evaluation->global_score,
                'adjudicatedScore' => $evaluation->adjudicated_score,
                'pending' => $evaluation->pending_adjudications,
                'reach' => $evaluation->reach,
                'detection' => $evaluation->detection,
                'staticValidation' => $evaluation->static_validation,
                'confirmation' => $evaluation->confirmation,
                'cost' => $evaluation->cost, 'cases' => $evaluation->cases,
                'ambiguous' => data_get($evaluation->payload, 'ambiguous_findings', []),
                'unmatched' => data_get($evaluation->payload, 'unmatched_findings', []),
                'adjudications' => $evaluation->adjudications,
            ])->values(),
            'artifacts' => $this->artifacts($audit),
        ]);
    }

    public function cancel(Request $request, AuditRun $audit): RedirectResponse
    {
        $this->authorizeRun($request, $audit);
        abort_if($audit->isTerminal(), 409, 'La run è già terminata.');
        $audit->update(['cancellation_requested' => true]);

        return back();
    }

    public function rerun(Request $request, AuditRun $audit, AuditRunFactory $factory): RedirectResponse
    {
        $this->authorizeRun($request, $audit);
        $copy = $factory->create($request->user(), $audit->parameters);
        ExecuteAuditRun::dispatch($copy->id);

        return to_route('audits.show', $copy);
    }

    public function retry(Request $request, AuditRun $audit, AuditRunQueueFailureReconciler $queueFailures): RedirectResponse
    {
        $this->authorizeRun($request, $audit);
        $queueFailures->reconcile($audit);
        abort_unless($audit->status === AuditRunStatus::Failed, 409, 'La run non è fallita.');

        $queueFailures->forget($audit);
        $audit->update([
            'status' => AuditRunStatus::Queued,
            'error' => null,
            'exit_code' => null,
            'cancellation_requested' => false,
            'started_at' => null,
            'finished_at' => null,
        ]);
        Cache::forget('audit-console.snapshot.'.$audit->id);
        ExecuteAuditRun::dispatch($audit->id);

        return to_route('audits.console.show', $audit);
    }

    public function destroy(Request $request, AuditRun $audit): RedirectResponse
    {
        $this->deleteRun($request, $audit);

        return to_route('audits.index');
    }

    public function destroyConsole(Request $request, AuditRun $audit): RedirectResponse
    {
        $this->deleteRun($request, $audit);

        return to_route('audits.console.index');
    }

    /** Return one bounded slice of the human transcript without hydrating it into Inertia. */
    public function logChunk(Request $request, AuditRun $audit, AuditRunQueueFailureReconciler $queueFailures): JsonResponse
    {
        $this->authorizeRun($request, $audit);
        $queueFailures->reconcile($audit);
        $position = max(0, $request->integer('after'));
        $path = app(RunStorage::class)->logPath((string) $audit->run_path, $audit->audit_id);
        if (! is_file($path)) {
            return response()->json([
                'start' => 0, 'offset' => 0, 'base64' => '', 'eof' => true,
                'status' => $audit->status->value,
            ], headers: ['Cache-Control' => 'no-store, private']);
        }

        clearstatcache(true, $path);
        $size = (int) (filesize($path) ?: 0);
        $position = min($position, $size);
        $stream = fopen($path, 'rb');
        abort_if($stream === false, 500, 'Impossibile leggere il transcript.');
        try {
            fseek($stream, $position);
            if ($request->boolean('align') && $position > 0) {
                $probe = (string) fread($stream, 65536);
                $newline = strpos($probe, "\n");
                if ($newline !== false) {
                    $position += $newline + 1;
                }
                fseek($stream, $position);
            }
            $start = $position;
            $chunk = (string) fread($stream, 131072);
            $position += strlen($chunk);
        } finally {
            fclose($stream);
        }

        $audit->refresh();

        return response()->json([
            'start' => $start,
            'offset' => $position,
            'base64' => base64_encode($chunk),
            'eof' => $position >= $size,
            'status' => $audit->status->value,
        ], headers: ['Cache-Control' => 'no-store, private']);
    }

    public function snapshot(Request $request, AuditRun $audit, AuditRunQueueFailureReconciler $queueFailures): JsonResponse
    {
        $this->authorizeRun($request, $audit);
        $queueFailures->reconcile($audit);
        $audit->refresh();

        return response()->json($this->runSnapshot($audit), headers: ['Cache-Control' => 'no-store, private']);
    }

    public function events(Request $request, AuditRun $audit, AuditRunQueueFailureReconciler $queueFailures): StreamedResponse
    {
        $this->authorizeRun($request, $audit);
        $queueFailures->reconcile($audit);
        $cursor = max((int) $request->header('Last-Event-ID', '0'), $request->integer('after'));
        $seconds = app()->runningUnitTests() ? 0 : (int) config('audits.sse_window_seconds', 20);

        return response()->stream(function () use ($audit, $cursor, $seconds): void {
            $deadline = microtime(true) + $seconds;
            $last = $cursor;
            $offset = 0;
            $lastStatus = null;
            do {
                $batch = $this->readTranscriptEvents($audit, $last, $offset);
                foreach ($batch as $event) {
                    $last = (int) $event['sequence'];
                    echo "id: {$last}\n";
                    echo 'event: '.str_replace(["\r", "\n"], '', (string) $event['type'])."\n";
                    echo 'data: '.json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n";
                }
                $audit->refresh();
                $currentStatus = $audit->status->value;
                if ($currentStatus !== $lastStatus) {
                    // Status changes live in SQL, while the durable CLI output
                    // lives in the run's single logs.php. Publish the current DB state on every
                    // connection so a page opened while queued can transition
                    // to preparing/running without an Inertia reload.
                    $statusEvent = [
                        'sequence' => $this->liveStatusSequence($currentStatus),
                        'type' => 'status',
                        'category' => null,
                        'role' => null,
                        'payload' => ['status' => $currentStatus, 'exit_code' => $audit->exit_code],
                        'artifact_ref' => null,
                        'occurred_at' => ($audit->finished_at ?? $audit->started_at ?? $audit->updated_at)?->toIso8601String(),
                    ];
                    echo "id: {$last}\n";
                    echo "event: status\n";
                    echo 'data: '.json_encode($statusEvent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n";
                    $lastStatus = $currentStatus;
                }
                if ($batch === []) {
                    echo ": heartbeat\n\n";
                }
                if (function_exists('ob_flush')) {
                    @ob_flush();
                }
                flush();
                if ($audit->isTerminal() && $batch === []) {
                    break;
                }
                if ($seconds > 0) {
                    usleep(500000);
                }
            } while (microtime(true) < $deadline);
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-store, private',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function liveStatusSequence(string $status): int
    {
        return match ($status) {
            'queued' => -1,
            'preparing' => -2,
            'running' => -3,
            'finalizing' => -4,
            'completed' => -5,
            'failed' => -6,
            'cancelled' => -7,
            default => -99,
        };
    }

    public function artifact(Request $request, AuditRun $audit, string $path): BinaryFileResponse
    {
        $this->authorizeRun($request, $audit);
        abort_if(str_contains($path, '..') || str_contains($path, "\0"), 404);
        $root = realpath((string) $audit->run_path);
        $file = realpath(rtrim((string) $audit->run_path, '/\\').DIRECTORY_SEPARATOR.$path);
        abort_if($root === false || $file === false || ! is_file($file) || ! str_starts_with($file, $root.DIRECTORY_SEPARATOR), 404);

        return response()->file($file, ['Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** @return array<int, array<string, mixed>> */
    private function transcriptEvents(AuditRun $run, array $fallback = []): array
    {
        $events = $this->readTranscriptEvents($run, 0);
        if ($events === [] && $fallback !== []) {
            return collect($fallback)->map(fn ($event): array => [
                'sequence' => (int) ($event->sequence ?? 0),
                'type' => $event->type,
                'category' => $event->category,
                'role' => $event->role,
                'payload' => $event->payload,
                'artifact_ref' => $event->artifact_ref,
                'occurred_at' => $event->occurred_at?->toIso8601String(),
            ])->values()->all();
        }

        if ($run->isTerminal()) {
            $events[] = [
                'sequence' => ((int) (collect($events)->max('sequence') ?? 0)) + 1,
                'type' => 'status',
                'category' => null,
                'role' => null,
                'payload' => ['status' => $run->status->value, 'exit_code' => $run->exit_code],
                'artifact_ref' => null,
                'occurred_at' => $run->finished_at?->toIso8601String(),
            ];
        }

        return array_slice($events, -3000);
    }

    /** @return array<int, array<string, mixed>> */
    private function readTranscriptEvents(AuditRun $run, int $after = 0, ?int &$offset = null): array
    {
        $path = app(RunStorage::class)->logPath((string) $run->run_path, $run->audit_id);
        if (! is_file($path)) {
            return [];
        }

        $stream = fopen($path, 'rb');
        if ($stream === false) {
            return [];
        }
        if ($offset !== null) {
            fseek($stream, $offset);
        }

        $events = [];
        while (($line = fgets($stream)) !== false) {
            if (! str_ends_with($line, "\n")) {
                fseek($stream, -strlen($line), SEEK_CUR);
                break;
            }
            $offset = ftell($stream);
            $marker = strpos($line, 'LAILAPS_EVENT ');
            $json = $marker === false ? trim($line) : trim(substr($line, $marker + strlen('LAILAPS_EVENT ')));
            $payload = json_decode($json, true);
            if (! is_array($payload) || ! isset($payload['sequence'], $payload['type'])) {
                continue;
            }
            if ((int) $payload['sequence'] <= $after) {
                continue;
            }
            $events[] = [
                'sequence' => (int) $payload['sequence'],
                'type' => (string) $payload['type'],
                'category' => $payload['category'] ?? null,
                'role' => $payload['role'] ?? null,
                'payload' => is_array($payload['payload'] ?? null) ? $payload['payload'] : [],
                'artifact_ref' => $payload['artifact_ref'] ?? null,
                'occurred_at' => $payload['occurred_at'] ?? null,
            ];
            if (count($events) >= 250) {
                break;
            }
        }
        if ($offset === null) {
            $offset = ftell($stream);
        }
        fclose($stream);

        return $events;
    }

    /** @return array<string, mixed> */
    private function summary(AuditRun $run, bool $withArtifacts = true): array
    {
        $report = $withArtifacts ? ($this->outcomePart($run, 'report') ?? []) : [];
        $benchmark = $withArtifacts ? ($this->outcomePart($run, 'benchmark') ?? []) : [];
        $parameters = $run->parameters;
        $categories = (array) ($parameters['categories'] ?? []);
        if ($categories === [] && isset($parameters['preset'])) {
            $preset = AuditCommandBuilder::PRESETS[(string) $parameters['preset']] ?? [];
            $categories = isset($preset['category']) && $preset['category'] !== '' ? [(string) $preset['category']] : [];
        }
        $target = $parameters['project_name'] ?? $parameters['benchmark_id'] ?? $parameters['preset'] ?? $parameters['url'] ?? $parameters['path'] ?? null;

        return [
            'id' => $run->id,
            'auditId' => $run->audit_id,
            'type' => $run->type,
            'status' => $run->status->value,
            'createdAt' => $run->created_at?->toIso8601String(),
            'startedAt' => $run->started_at?->toIso8601String(),
            'finishedAt' => $run->finished_at?->toIso8601String(),
            'target' => $target ?: $run->audit_id,
            'categories' => $categories !== [] ? array_values($categories) : $this->reportCategories($report),
            'confirmed' => count((array) ($report['confirmed'] ?? [])),
            'suspected' => count((array) ($report['suspected'] ?? [])),
            'score' => data_get($benchmark, 'adjudicated_score.normalized') ?? data_get($benchmark, 'score.normalized') ?? data_get($benchmark, 'confirmation.recall') ?? data_get($benchmark, 'detection.recall'),
            'hasBenchmark' => $benchmark !== [],
        ];
    }

    /** @return array<string, mixed> */
    private function runSnapshot(AuditRun $run, bool $includeArtifacts = true): array
    {
        $artifactData = ['confirmed' => 0, 'suspected' => 0, 'findings' => [], 'usage' => []];
        if ($includeArtifacts) {
            $signature = $this->snapshotArtifactSignature($run);
            $cacheKey = 'audit-console.snapshot.'.$run->id;
            $cached = Cache::get($cacheKey);
            if (is_array($cached) && ($cached['signature'] ?? null) === $signature) {
                $artifactData = (array) ($cached['data'] ?? $artifactData);
            } else {
                $artifactData = $this->snapshotArtifactData($run);
                Cache::put($cacheKey, ['signature' => $signature, 'data' => $artifactData], now()->addSeconds(2));
            }
        }
        $logPath = app(RunStorage::class)->logPath((string) $run->run_path, $run->audit_id);

        return [
            'status' => $run->status->value,
            'error' => $run->error,
            'exitCode' => $run->exit_code,
            'startedAt' => $run->started_at?->toIso8601String(),
            'finishedAt' => $run->finished_at?->toIso8601String(),
            'logBytes' => is_file($logPath) ? (int) filesize($logPath) : 0,
            ...$artifactData,
        ];
    }

    /** @return array{confirmed: int, suspected: int, findings: array<int, array<string, mixed>>, usage: array<string, mixed>} */
    private function snapshotArtifactData(AuditRun $run): array
    {
        $report = $this->outcomePart($run, 'report') ?? [];
        $confirmed = (array) ($report['confirmed'] ?? []);
        $suspected = (array) ($report['suspected'] ?? []);
        $findings = collect($confirmed)->map(fn ($finding): array => $this->compactFinding((array) $finding, 'confirmed'))
            ->merge(collect($suspected)->map(fn ($finding): array => $this->compactFinding((array) $finding, 'suspected')))
            ->values()->all();

        return [
            'confirmed' => count($confirmed),
            'suspected' => count($suspected),
            'findings' => $findings,
            'usage' => collect($this->telemetry($run) ?? [])->only([
                'model_requests', 'total_model_input_tokens', 'total_model_output_tokens',
                'cached_model_input_tokens', 'uncached_model_input_tokens', 'total_tokens',
                'economic_points_used', 'economic_point_limit', 'economic_points_remaining',
                'provider_cost_usd', 'number_of_tool_calls', 'http_tool_calls',
                'http_network_requests', 'role_usage',
            ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function compactFinding(array $finding, string $fallbackStatus): array
    {
        return [
            'id' => (string) ($finding['finding_id'] ?? $finding['lead_id'] ?? ''),
            'title' => (string) ($finding['title'] ?? 'Finding senza titolo'),
            'status' => (string) ($finding['status'] ?? $fallbackStatus),
            'severity' => $finding['severity'] ?? null,
            'category' => $finding['owasp_category'] ?? null,
        ];
    }

    private function snapshotArtifactSignature(AuditRun $run): string
    {
        $path = app(RunStorage::class)->outcomePath((string) $run->run_path, $run->audit_id);
        if (! is_file($path)) {
            return sha1('missing');
        }
        clearstatcache(true, $path);

        return sha1($path.':'.(filemtime($path) ?: 0).':'.(filesize($path) ?: 0));
    }

    /** @return array<int, string> */
    private function reportCategories(array $report): array
    {
        return collect((array) ($report['categories'] ?? []))->map(function ($category, $key): string {
            return is_array($category) ? (string) ($category['category'] ?? $key) : (string) $key;
        })->filter()->values()->all();
    }

    private function deleteRun(Request $request, AuditRun $audit): void
    {
        $this->authorizeRun($request, $audit);
        abort_unless($audit->isTerminal(), 409, 'Annulla la run prima di eliminarla.');
        $runDirectory = realpath((string) $audit->run_path);
        $runsRoot = realpath(storage_path('app/runs'));
        if ($runDirectory !== false && $runsRoot !== false && $runDirectory !== $runsRoot
            && str_starts_with($runDirectory, $runsRoot.DIRECTORY_SEPARATOR)) {
            File::deleteDirectory($runDirectory);
        }
        Cache::forget('audit-console.snapshot.'.$audit->id);
        $audit->delete();
    }

    private function authorizeRun(Request $request, AuditRun $run): void
    {
        abort_unless($run->user_id === $request->user()->id, 404);
    }

    /** @return array<string, mixed> */
    private function consoleConfiguration(BenchmarkCatalog $catalog): array
    {
        return [
            'presets' => collect(AuditCommandBuilder::PRESETS)->map(fn (array $preset, string $id) => [
                'id' => $id,
                'category' => $preset['category'] ?? '',
                'benchmark' => (bool) ($preset['benchmark'] ?? false),
            ])->values(),
            'benchmarks' => collect($catalog->all())->groupBy('target_id')->map(fn ($manifests, string $targetId) => [
                'id' => $targetId,
                'name' => $targetId,
                'categories' => $manifests->pluck('category')->unique()->values(),
            ])->values(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function outcomePart(AuditRun $run, string $part): ?array
    {
        $outcome = app(RunStorage::class)->outcome($run);

        return match ($part) {
            'report' => is_array($outcome['report'] ?? null) ? $outcome['report'] : null,
            'benchmark' => is_array($outcome['benchmark'] ?? null) ? $outcome['benchmark'] : null,
            default => null,
        };
    }

    /** @return array<int, array<string, mixed>> */
    private function artifacts(AuditRun $run): array
    {
        $root = (string) $run->run_path;
        if (! is_dir($root)) {
            return [];
        }
        $files = [];
        foreach (File::allFiles($root) as $file) {
            $files[] = ['path' => str_replace('\\', '/', $file->getRelativePathname()), 'bytes' => $file->getSize()];
        }

        return $files;
    }

    /** @return array<string, int|float|array<mixed>>|null */
    private function telemetry(AuditRun $run): ?array
    {
        $report = $this->outcomePart($run, 'report');
        $telemetry = $report['telemetry'] ?? null;

        return is_array($telemetry) ? $telemetry : null;
    }
}
