<?php

namespace App\Http\Controllers;

use App\Actions\CreateProjectSourceAction;
use App\Enums\LabStatus;
use App\Enums\ProjectSourceType;
use App\Jobs\LabSetup;
use App\Jobs\PrepareProjectAnalysis;
use App\Models\Project;
use App\Models\ProjectSource;
use App\Services\ZipService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProjectSourceController extends Controller
{
    public function store(Request $request, Project $project, CreateProjectSourceAction $createSource): RedirectResponse
    {
        abort_unless($project->user_id === $request->user()->id, 404);

        $request->validate([
            'source_type' => ['required', Rule::in([ProjectSourceType::ZIP->value])],
            'source' => ['required', 'file', 'mimes:zip', 'max:'.ZipService::MAX_UPLOAD_KB],
        ]);

        $createSource->handle($project, $request->file('source'));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Source uploaded.',
        ]);

        return to_route('projects.show', $project);
    }

    public function show(Request $request, Project $project, ProjectSource $source): Response
    {
        abort_unless(
            $project->user_id === $request->user()->id && $source->project_id === $project->id,
            404,
        );

        return Inertia::render('projects/sources/Show', [
            'project' => $project->only(['id', 'name', 'created_at']),
            'source' => $source->only([
                'id', 'source_type', 'status', 'error', 'uploaded_at', 'created_at',
            ]),
        ]);
    }

    public function retrySetup(Request $request, Project $project, ProjectSource $source): RedirectResponse
    {
        abort_unless(
            $project->user_id === $request->user()->id && $source->project_id === $project->id,
            404,
        );

        $lab = $source->lab;
        abort_unless($lab, 404);

        DB::transaction(function () use ($source, $lab): void {
            if (in_array($lab->status, [LabStatus::FAILED, LabStatus::UNAVAILABLE], true)) {
                $lab->update(['status' => LabStatus::PENDING]);
            }

            LabSetup::dispatch($lab->id)->afterCommit();
            PrepareProjectAnalysis::dispatch($source->id)->afterCommit();
        });

        return to_route('projects.show', ['project' => $project, 'step' => 'environment']);

    }
}
