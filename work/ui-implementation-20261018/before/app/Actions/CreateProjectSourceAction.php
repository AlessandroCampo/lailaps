<?php

namespace App\Actions;

use App\Enums\ProjectSourceStatus;
use App\Enums\ProjectSourceType;
use App\Models\Project;
use App\Models\ProjectSource;
use App\Services\ZipService;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CreateProjectSourceAction
{
    public function __construct(private ZipService $zipService) {}

    public function handle(Project $project, UploadedFile $zip): ProjectSource
    {
        return $this->zipService->withSnapshot(
            $zip,
            fn (string $hash, Closure $storeSnapshot): ProjectSource => $this->storeSnapshot($project, $hash, $storeSnapshot),
        );
    }

    public function storeSnapshot(Project $project, string $hash, Closure $storeSnapshot): ProjectSource
    {
        return DB::transaction(function () use ($project, $hash, $storeSnapshot): ProjectSource {
            Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();

            $source = ProjectSource::query()->withTrashed()->where('project_id', $project->id)->where('content_hash', $hash)->first();

            if ($source !== null && $source->status === ProjectSourceStatus::READY
                && Storage::disk('projects')->directoryExists($source->sourcePath())) {
                if ($source->trashed()) {
                    $source->restore();
                }
            } else {
                $source ??= new ProjectSource(['project_id' => $project->id, 'content_hash' => $hash]);
                $source->fill([
                    'source_type' => ProjectSourceType::ZIP,
                    'status' => ProjectSourceStatus::IMPORTING,
                    'error' => null,
                    'deleted_at' => null,
                ])->save();

                $storeSnapshot($source->storageDirectory());

                $source->fill([
                    'status' => ProjectSourceStatus::READY,
                    'uploaded_at' => now(),
                ])->save();
            }

            $project->sources()->whereKeyNot($source->id)->delete();

            return $source;
        });
    }
}
