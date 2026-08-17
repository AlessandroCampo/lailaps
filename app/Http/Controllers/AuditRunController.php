<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAuditRunRequest;
use App\Jobs\ExecuteAuditRun;
use App\Models\AuditRun;
use App\Services\Audit\AuditCommandBuilder;
use App\Services\Audit\AuditEventRecorder;
use App\Services\Audit\AuditRunFactory;
use App\Services\Audit\LegacyAuditImporter;
use App\Services\Pentest\BenchmarkCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AuditRunController extends Controller
{
    public function index(Request $request, LegacyAuditImporter $importer): Response
    {
        $importer->importFor($request->user());
        $runs = $request->user()->auditRuns()->latest()->get()->map(fn (AuditRun $run) => $this->summary($run));

        return Inertia::render('audits/Index', ['runs' => $runs]);
    }

    public function create(BenchmarkCatalog $catalog): Response
    {
        return Inertia::render('audits/Create', [
            'presets' => collect(AuditCommandBuilder::PRESETS)->map(fn (array $preset, string $id) => [
                'id' => $id,
                'category' => $preset['category'],
                'dualAgent' => $preset['dual_agent'],
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

    public function store(StoreAuditRunRequest $request, AuditRunFactory $factory): RedirectResponse
    {
        $run = $factory->create($request->user(), $request->normalized());
        ExecuteAuditRun::dispatch($run->id);

        return to_route('audits.show', $run);
    }

    public function show(Request $request, AuditRun $audit): Response
    {
        $this->authorizeRun($request, $audit);
        $tail = $audit->events()->latest('sequence')->limit(3000)->get();
        $prompts = $audit->events()->where('type', 'system_prompt')->get();
        $events = $tail->merge($prompts)->unique('sequence')->sortBy('sequence')->values();

        return Inertia::render('audits/Show', [
            'run' => [...$this->summary($audit),
                'parameters' => $audit->parameters,
                'error' => $audit->error,
                'cancellationRequested' => $audit->cancellation_requested,
            ],
            'initialEvents' => $events,
            'report' => $this->jsonArtifact($audit, 'report.json'),
            'benchmark' => $this->jsonArtifact($audit, 'benchmark.json'),
            'artifacts' => $this->artifacts($audit),
        ]);
    }

    public function cancel(Request $request, AuditRun $audit, AuditEventRecorder $events): RedirectResponse
    {
        $this->authorizeRun($request, $audit);
        abort_if($audit->isTerminal(), 409, 'La run è già terminata.');
        $audit->update(['cancellation_requested' => true]);
        $events->record($audit, 'status', ['status' => $audit->status->value, 'cancellation_requested' => true]);

        return back();
    }

    public function rerun(Request $request, AuditRun $audit, AuditRunFactory $factory): RedirectResponse
    {
        $this->authorizeRun($request, $audit);
        $copy = $factory->create($request->user(), $audit->parameters);
        ExecuteAuditRun::dispatch($copy->id);

        return to_route('audits.show', $copy);
    }

    public function destroy(Request $request, AuditRun $audit): RedirectResponse
    {
        $this->authorizeRun($request, $audit);
        abort_unless($audit->isTerminal(), 409, 'Annulla la run prima di eliminarla.');
        $root = realpath(dirname((string) $audit->artifact_path));
        $auditsRoot = realpath(storage_path('app/audits'));
        if ($root !== false && $auditsRoot !== false && str_starts_with($root, $auditsRoot.DIRECTORY_SEPARATOR)) {
            File::deleteDirectory($root);
        }
        $audit->delete();

        return to_route('audits.index');
    }

    public function events(Request $request, AuditRun $audit): StreamedResponse
    {
        $this->authorizeRun($request, $audit);
        $cursor = max((int) $request->header('Last-Event-ID', '0'), $request->integer('after'));
        $seconds = app()->runningUnitTests() ? 0 : (int) config('audits.sse_window_seconds', 20);

        return response()->stream(function () use ($audit, $cursor, $seconds): void {
            $deadline = microtime(true) + $seconds;
            $last = $cursor;
            do {
                $batch = $audit->events()->where('sequence', '>', $last)->orderBy('sequence')->limit(250)->get();
                foreach ($batch as $event) {
                    $last = $event->sequence;
                    echo "id: {$last}\n";
                    echo 'event: '.str_replace(["\r", "\n"], '', $event->type)."\n";
                    echo 'data: '.json_encode([
                        'sequence' => $event->sequence,
                        'type' => $event->type,
                        'category' => $event->category,
                        'role' => $event->role,
                        'payload' => $event->payload,
                        'artifact_ref' => $event->artifact_ref,
                        'occurred_at' => $event->occurred_at?->toIso8601String(),
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n";
                }
                if ($batch->isEmpty()) {
                    echo ": heartbeat\n\n";
                }
                if (function_exists('ob_flush')) {
                    @ob_flush();
                }
                flush();
                $audit->refresh();
                if ($audit->isTerminal() && $batch->isEmpty()) {
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

    public function artifact(Request $request, AuditRun $audit, string $path): BinaryFileResponse
    {
        $this->authorizeRun($request, $audit);
        abort_if(str_contains($path, '..') || str_contains($path, "\0"), 404);
        $root = realpath((string) $audit->artifact_path);
        $file = realpath(rtrim((string) $audit->artifact_path, '/\\').DIRECTORY_SEPARATOR.$path);
        abort_if($root === false || $file === false || ! is_file($file) || ! str_starts_with($file, $root.DIRECTORY_SEPARATOR), 404);

        return response()->file($file, ['Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** @return array<string, mixed> */
    private function summary(AuditRun $run): array
    {
        $report = $this->jsonArtifact($run, 'report.json') ?? [];
        $benchmark = $this->jsonArtifact($run, 'benchmark.json') ?? [];

        return [
            'id' => $run->id,
            'auditId' => $run->audit_id,
            'type' => $run->type,
            'status' => $run->status->value,
            'legacy' => $run->legacy,
            'createdAt' => $run->created_at?->toIso8601String(),
            'startedAt' => $run->started_at?->toIso8601String(),
            'finishedAt' => $run->finished_at?->toIso8601String(),
            'target' => $run->parameters['preset'] ?? $run->parameters['benchmark_id'] ?? $run->parameters['url'] ?? $run->parameters['path'] ?? 'Run importata',
            'categories' => $run->parameters['categories'] ?? array_keys((array) ($report['categories'] ?? [])),
            'confirmed' => count((array) ($report['confirmed'] ?? [])),
            'suspected' => count((array) ($report['suspected'] ?? [])),
            'score' => data_get($benchmark, 'score.points') ?? data_get($benchmark, 'metrics.recall'),
            'hasBenchmark' => $benchmark !== [],
        ];
    }

    private function authorizeRun(Request $request, AuditRun $run): void
    {
        abort_unless($run->user_id === $request->user()->id, 404);
    }

    /** @return array<string, mixed>|null */
    private function jsonArtifact(AuditRun $run, string $name): ?array
    {
        $path = rtrim((string) $run->artifact_path, '/\\').DIRECTORY_SEPARATOR.$name;
        if (! is_file($path)) {
            return null;
        }
        try {
            $value = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

            return is_array($value) ? $value : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function artifacts(AuditRun $run): array
    {
        $root = (string) $run->artifact_path;
        if (! is_dir($root)) {
            return [];
        }
        $files = [];
        foreach (File::allFiles($root) as $file) {
            $files[] = ['path' => str_replace('\\', '/', $file->getRelativePathname()), 'bytes' => $file->getSize()];
        }

        return $files;
    }
}
