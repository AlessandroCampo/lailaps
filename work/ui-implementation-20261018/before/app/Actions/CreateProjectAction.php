<?php

namespace App\Actions;

use App\Jobs\LabSetup;
use App\Jobs\PrepareProjectAnalysis;
use App\Models\Project;
use App\Services\ProjectService;
use App\Services\ZipService;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CreateProjectAction
{
    public function __construct(
        private ZipService $zipService,
        private CreateProjectSourceAction $createSource,
        private CreateLabAction $createLab,
        private ProjectService $projects,
    ) {}

    public function handle(string $name, UploadedFile $zip): Project
    {

        [$project, $sourceId, $labId] = $this->zipService->withSnapshot($zip, function (string $hash, Closure $storeSnapshot) use ($name): array {
            return DB::transaction(function () use ($name, $hash, $storeSnapshot): array {
                $project = Project::create(['name' => $name, 'user_id' => Auth::id()]);
                $source = $this->createSource->storeSnapshot($project, $hash, $storeSnapshot);
                $this->projects->prepareDirectory($project->artifactsPath());
                $lab = $this->createLab->handle($source);

                return [$project, $source->id, $lab->id];
            });
        });

        LabSetup::dispatch($labId)->afterCommit();

        PrepareProjectAnalysis::dispatch($sourceId)->afterCommit();

        return $project;
    }
}
