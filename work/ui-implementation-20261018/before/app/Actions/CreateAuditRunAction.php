<?php

namespace App\Actions;

use App\Enums\AuditRunAreaStatus;
use App\Enums\AuditRunStatus;
use App\Enums\LabStatus;
use App\Enums\ProjectArtifactType;
use App\Enums\ProjectSourceStatus;
use App\Models\AuditRun;
use App\Models\Lab;
use App\Models\ProjectSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateAuditRunAction
{
    public function handle(ProjectSource $source, array $configs): AuditRun
    {
        return DB::transaction(function () use ($source, $configs) {

            if ($source->trashed() || $source->status !== ProjectSourceStatus::READY) {
                throw ValidationException::withMessages(['source' => 'The project source is not ready.']);
            }

            $lab = $source->readyLab()->first();

            if (! $lab) {
                throw ValidationException::withMessages(['source' => 'No ready lab is available for this source.']);
            }

            $lab = Lab::whereKey($lab->id)->lockForUpdate()->firstOrFail();
            if ($lab->status !== LabStatus::READY) {
                throw ValidationException::withMessages(['source' => 'The lab is not ready.']);
            }
            if (AuditRun::where('lab_id', $lab->id)->whereIn('status', [AuditRunStatus::PENDING, AuditRunStatus::RUNNING])->exists()) {
                throw ValidationException::withMessages(['audit_run' => 'An audit is already queued or running for this lab.']);
            }

            $artifacts = $source->usableArtifacts()->get();

            $recon = $artifacts->firstWhere('type', ProjectArtifactType::RECON);

            if (! $recon) {
                throw ValidationException::withMessages(['source' => 'No usable Recon artifact is available for this source.']);
            }

            $run = AuditRun::create([
                'project_source_id' => $source->id,
                'project_id' => $source->project_id,
                'lab_id' => $lab->id,
                'configs' => $configs,
                'status' => AuditRunStatus::PENDING,
            ]);

            $run->artifacts()->attach($artifacts->modelKeys());

            foreach ($recon->reconAreas as $area) {
                $run->areas()->firstOrCreate(
                    ['pass_key' => 'pass-001', 'area_key' => $area->area_key],
                    [
                        'recon_area_id' => $area->id,
                        'status' => AuditRunAreaStatus::PENDING,
                        'payload' => $area->payload,
                    ],
                );
            }

            return $run;
        });
    }
}
