<?php

namespace App\Jobs;

use App\Enums\ProjectArtifactStatus;
use App\Enums\ProjectArtifactType;
use App\Features\CodeIndex\CodeIndexService;
use App\Features\DependencyAnalysis\DependencyAnalysisService;
use App\Features\ProjectAnalysis\StaticAnalysisService;
use App\Features\Recon\ReconService;
use App\Models\ProjectArtifact;
use App\Models\ProjectSource;
use App\Models\ReconArea;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class PrepareProjectAnalysis implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public bool $failOnTimeout = true;

    public function __construct(public string $sourceId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('project-analysis:source:'.$this->sourceId))
            ->dontRelease()
            ->expireAfter($this->timeout + 60)];
    }

    public function handle(
        StaticAnalysisService $analysis,
        CodeIndexService $index,
        ReconService $recon,
        DependencyAnalysisService $dependencyAnalysis,
    ): void {
        $source = ProjectSource::withTrashed()->findOrFail($this->sourceId);

        $analysis->prep($source);
        $index->prep($source);
        $dependencyAnalysis->prep($source);
        $reconArtifact = $recon->prep($source);

       

        if (in_array($reconArtifact->status, [ProjectArtifactStatus::READY, ProjectArtifactStatus::PARTIAL])) {
            DB::transaction(function() use($reconArtifact) {
                 collect($reconArtifact->payload['report']['area_ledger'])->each(function(array $area) use($reconArtifact) {

                    $reconArea = ReconArea::firstOrCreate([
                        'recon_artifact_id' => $reconArtifact->id,
                        'area_key' => $area['area_id'],
                        ],
                        [
                        'name' => $area['title'] ?? '',
                        'project_source_id' => $reconArtifact->project_source_id,
                        'payload' => $area
                        ]
                );
            });
        });
        }

       
    }

    public function failed(?Throwable $error): void
    {
        $message = $error?->getMessage() ?? 'Project analysis preparation failed.';

        $artifacts = ProjectArtifact::where('project_source_id', $this->sourceId)
            ->whereIn('type', ProjectArtifactType::cases())
            ->whereIn('status', [ProjectArtifactStatus::PENDING, ProjectArtifactStatus::RUNNING])
            ->get();

        foreach ($artifacts as $artifact) {
            $artifact->update([
                'status' => ProjectArtifactStatus::FAILED,
                'error' => Str::limit($message, 255, ''),
                'metadata' => array_merge($artifact->metadata ?? [], [
                    'failure' => $message,
                    'failure_stage' => 'queue',
                ]),
            ]);
        }
    }
}
