<?php

namespace App\Services\Audit;

use App\Enums\AuditRunStatus;
use App\Models\AuditRun;
use App\Models\User;
use Illuminate\Support\Str;

final class AuditRunFactory
{
    /** @param array<string, mixed> $parameters */
    public function create(User $user, array $parameters): AuditRun
    {
        $id = (string) Str::ulid();
        $auditId = strtolower($id);
        $artifactPath = storage_path("app/audits/{$auditId}/artifacts");

        return AuditRun::query()->create([
            'id' => $id,
            'user_id' => $user->id,
            'audit_id' => $auditId,
            'type' => $parameters['type'],
            'status' => AuditRunStatus::Queued,
            'parameters' => $parameters,
            'artifact_path' => str_replace('\\', '/', $artifactPath),
        ]);
    }
}
