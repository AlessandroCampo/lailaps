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
        $project = RunStorage::project($parameters);
        $categories = (array) ($parameters['categories'] ?? []);
        if ($categories === [] && isset($parameters['preset'])) {
            $preset = AuditCommandBuilder::PRESETS[(string) $parameters['preset']] ?? [];
            $categories = isset($preset['category']) && $preset['category'] !== '' ? [(string) $preset['category']] : [];
        }
        $location = app(RunStorage::class)->create($project, $categories);

        return AuditRun::query()->create([
            'id' => $id,
            'user_id' => $user->id,
            'audit_id' => $location['run_id'],
            'type' => $parameters['type'],
            'status' => AuditRunStatus::Queued,
            'parameters' => $parameters,
            'run_path' => str_replace('\\', '/', $location['directory']),
        ]);
    }
}
