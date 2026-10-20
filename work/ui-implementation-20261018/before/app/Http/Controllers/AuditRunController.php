<?php

namespace App\Http\Controllers;

use App\Actions\CreateAuditRunAction;
use App\Actions\RunAuditAction;
use App\Enums\AuditRunStatus;
use App\Jobs\RunAudit;
use App\Models\AuditRun;
use App\Models\Project;
use App\Models\ProjectSource;
use App\Services\OutcomeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

class AuditRunController extends Controller
{
    public function progress(Request $request, AuditRun $auditRun, OutcomeService $outcomes): JsonResponse
    {
        abort_unless(Project::whereKey($auditRun->project_id)->where('user_id', $request->user()->id)->exists(), 404);

        return response()->json($outcomes->progress($auditRun))->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, Project $project, ProjectSource $source, CreateAuditRunAction $createAuditRun): RedirectResponse
    {
        abort_unless($project->user_id === $request->user()->id && $source->project_id === $project->id, 404);

        $run = $createAuditRun->handle($source, RunAuditAction::DEFAULT_CONFIGS);
        try {
            RunAudit::dispatch($run->id)->afterCommit();
        } catch (Throwable $error) {
            $run->newQuery()->whereKey($run->id)->where('status', AuditRunStatus::PENDING)->update([
                'status' => AuditRunStatus::FAILED,
                'finished_at' => now(),
                'error' => 'Unable to queue the audit.',
            ]);
            throw $error;
        }

        return to_route('projects.show', $project);
    }
}
