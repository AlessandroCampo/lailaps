<?php

namespace App\Services\Audit;

use App\Models\AuditEvent;
use App\Models\AuditRun;
use Illuminate\Support\Facades\DB;

final class AuditEventRecorder
{
    /** @param array<string, mixed> $payload */
    public function record(
        AuditRun $run,
        string $type,
        array $payload = [],
        ?string $category = null,
        ?string $role = null,
        ?string $artifactRef = null,
    ): AuditEvent {
        return DB::transaction(function () use ($run, $type, $payload, $category, $role, $artifactRef): AuditEvent {
            $locked = AuditRun::query()->lockForUpdate()->findOrFail($run->id);
            $sequence = ((int) $locked->events()->max('sequence')) + 1;
            $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

            if (strlen($encoded) > (int) config('audits.event_payload_bytes', 65536)) {
                $relative = "ui-events/{$sequence}.json";
                $directory = rtrim((string) $locked->artifact_path, '/\\').'/ui-events';
                if (! is_dir($directory)) {
                    mkdir($directory, 0755, true);
                }
                file_put_contents($directory."/{$sequence}.json", $encoded);
                $artifactRef ??= $relative;
                $payload = [
                    'preview' => mb_substr((string) ($payload['content'] ?? $payload['output'] ?? $encoded), 0, 4000),
                    'truncated' => true,
                    'bytes' => strlen($encoded),
                ];
            }

            return $locked->events()->create([
                'sequence' => $sequence,
                'type' => $type,
                'category' => $category,
                'role' => $role,
                'payload' => $payload,
                'artifact_ref' => $artifactRef,
                'occurred_at' => now(),
            ]);
        }, 3);
    }
}
