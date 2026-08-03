<?php

namespace App\Http\Controllers;

use App\Models\AuditRun;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class BenchmarkComparisonController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $ids = array_slice((array) $request->input('runs', []), 0, 8);
        $runs = $request->user()->auditRuns()->whereIn('id', $ids)->get()->map(function (AuditRun $run): array {
            $root = rtrim((string) $run->artifact_path, '/\\');
            $path = is_file($root.'/benchmark-score.json') ? $root.'/benchmark-score.json' : $root.'/benchmark.json';
            $decoded = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
            $data = is_array($decoded) ? $decoded : null;

            return ['id' => $run->id, 'auditId' => $run->audit_id, 'target' => $run->parameters['benchmark_id'] ?? $run->parameters['preset'] ?? 'Benchmark', 'benchmark' => $data];
        });
        $available = $request->user()->auditRuns()->where('status', 'completed')->latest()->get(['id', 'audit_id', 'parameters', 'artifact_path'])
            ->filter(fn (AuditRun $run): bool => is_file(rtrim((string) $run->artifact_path, '/\\').'/benchmark.json') || is_file(rtrim((string) $run->artifact_path, '/\\').'/benchmark-score.json'))
            ->map(fn (AuditRun $run): array => ['id' => $run->id, 'audit_id' => $run->audit_id, 'parameters' => $run->parameters])
            ->values();

        return Inertia::render('audits/Compare', ['runs' => $runs, 'available' => $available]);
    }
}
