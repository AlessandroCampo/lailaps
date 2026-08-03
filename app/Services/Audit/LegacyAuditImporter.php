<?php

namespace App\Services\Audit;

use App\Enums\AuditRunStatus;
use App\Models\AuditRun;
use App\Models\User;
use Illuminate\Support\Str;

final class LegacyAuditImporter
{
    public function importFor(User $user): int
    {
        $root = storage_path('app/audits');
        if (! is_dir($root)) {
            return 0;
        }
        $imported = 0;
        foreach (new \DirectoryIterator($root) as $entry) {
            if (! $entry->isDir() || $entry->isDot()) {
                continue;
            }
            $auditId = $entry->getFilename();
            $artifacts = $entry->getPathname().DIRECTORY_SEPARATOR.'artifacts';
            if (! is_file($artifacts.DIRECTORY_SEPARATOR.'report.json') || AuditRun::query()->where('audit_id', $auditId)->exists()) {
                continue;
            }
            $run = AuditRun::query()->create([
                'id' => (string) Str::ulid(),
                'user_id' => $user->id,
                'audit_id' => $auditId,
                'type' => is_file($artifacts.DIRECTORY_SEPARATOR.'benchmark.json') || is_file($artifacts.DIRECTORY_SEPARATOR.'benchmark-score.json') ? 'benchmark' : 'audit',
                'status' => AuditRunStatus::Completed,
                'parameters' => ['legacy' => true, 'unknown' => true],
                'artifact_path' => str_replace('\\', '/', $artifacts),
                'legacy' => true,
                'started_at' => date('Y-m-d H:i:s', $entry->getMTime()),
                'finished_at' => date('Y-m-d H:i:s', $entry->getMTime()),
            ]);
            app(AuditEventRecorder::class)->record($run, 'report_published', ['legacy' => true], artifactRef: 'report.json');
            $imported++;
        }

        return $imported;
    }
}
