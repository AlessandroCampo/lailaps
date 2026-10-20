<?php

namespace App\Actions;

use App\DTOs\AuditRun\AreaOutcome;
use App\Enums\ProjectArtifactType;
use App\Models\AuditRun;
use App\Models\AuditRunArea;
use Illuminate\Support\Facades\DB;

class CreateAuditRunAreaAction
{
    public function handle(AuditRun $run, AreaOutcome $outcome): AuditRunArea
    {
        return DB::transaction(function () use ($run, $outcome): AuditRunArea {
            $area = $run->areas()->firstOrNew([
                'pass_key' => $outcome->passId ?? 'pass-001',
                'area_key' => $outcome->areaKey,
            ]);
            $reconArea = $run->artifacts->firstWhere('type', ProjectArtifactType::RECON)
                ?->reconAreas->firstWhere('area_key', $outcome->areaKey);
            $definition = $area->payload['definition'] ?? $area->payload ?? $reconArea?->payload ?? [
                'area_key' => $outcome->areaKey,
                'title' => $outcome->title,
                'origin' => $outcome->origin,
                'paths' => $outcome->paths,
                'next_check' => $outcome->nextCheck,
            ];

            $area->fill([
                'recon_area_id' => $area->recon_area_id ?? $reconArea?->id,
                'status' => $outcome->status,
                'reason' => $outcome->reason,
                'payload' => ['definition' => $definition, 'outcome' => $outcome->toArray()],
            ])->save();

            return $area;
        });
    }
}
