<?php

namespace App\Http\Controllers;

use App\Actions\CreateProjectAction;
use App\Enums\AuditRunLeadStatus;
use App\Enums\LabStatus;
use App\Enums\ProjectArtifactStatus;
use App\Enums\ProjectArtifactType;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Resources\AuditRunResource;
use App\Models\AuditRun;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): Response
    {
        return Inertia::render('projects/Index', [
            'projects' => Project::query()
                ->where('user_id', $request->user()->id)
                ->withCount('sources')
                ->latest()
                ->paginate(12)
                ->through(fn (Project $project): array => $project->only([
                    'id', 'name', 'sources_count', 'created_at',
                ])),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): Response
    {
        return Inertia::render('projects/Create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreProjectRequest $request, CreateProjectAction $createProjectAction): RedirectResponse
    {
        $validated = $request->validated();

        $project = $createProjectAction->handle($validated['name'], $request->file('source'));
        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Project created.',
        ]);

        return to_route('projects.show', ['project' => $project, 'step' => 'environment']);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Project $project): Response
    {
        abort_unless($project->user_id === $request->user()->id, 404);

        $source = $project->sources()->latest('id')->first();
        $lab = $source ? $project->labs()->where('project_source_id', $source->id)->latest('id')->first() : null;

        $preparationStatus = null;

        if ($source && $lab) {
            $types = [
                ProjectArtifactType::STATIC_ANALYSIS,
                ProjectArtifactType::CODE_INDEX,
                ProjectArtifactType::DEPENDENCY_ANALYSIS,
                ProjectArtifactType::RECON,
            ];
            $artifacts = $source->artifacts()->whereIn('type', $types)->get();
            $completed = $artifacts->count() === count($types) && $artifacts->every(fn ($artifact) => in_array($artifact->status, [
                ProjectArtifactStatus::READY,
                ProjectArtifactStatus::PARTIAL,
                ProjectArtifactStatus::FAILED,
            ], true));
            $interrupted = $artifacts->contains(fn ($artifact) => ($artifact->metadata['failure_stage'] ?? null) === 'queue');

            if (in_array($lab->status, [LabStatus::FAILED, LabStatus::UNAVAILABLE], true) || $interrupted) {
                $preparationStatus = 'failed';
            } elseif ($lab->status === LabStatus::READY && $completed) {
                $recon = $artifacts->firstWhere('type', ProjectArtifactType::RECON);
                $preparationStatus = $recon->status === ProjectArtifactStatus::FAILED ? 'failed' : 'ready';
            } else {
                $preparationStatus = 'running';
            }
        }

        $runs = $project->auditRuns()
            ->select(['id', 'project_source_id', 'status', 'created_at', 'started_at', 'finished_at', 'outcome->coverage_complete as coverage_complete'])
            ->selectRaw('outcome IS NOT NULL AS has_report')
            ->with('source:id,source_type,uploaded_at')
            ->withCount([
                'leads as confirmed_count' => fn ($query) => $query->where('status', AuditRunLeadStatus::CONFIRMED),
                'leads as suspected_count' => fn ($query) => $query->where('status', AuditRunLeadStatus::SUSPECTED),
                'leads as inconclusive_count' => fn ($query) => $query->where('status', AuditRunLeadStatus::INCONCLUSIVE),
                'leads as blocked_count' => fn ($query) => $query->where('status', AuditRunLeadStatus::BLOCKED),
            ])
            ->latest('created_at')->orderByDesc('id')->get()
            ->map(fn (AuditRun $run): array => (new AuditRunResource($run))->resolve($request));


        return Inertia::render('projects/Show', [
            'project' => $project->only(['id', 'name', 'created_at']),
            'source' => $source?->only([
                'id', 'source_type', 'status', 'error', 'uploaded_at', 'created_at',
            ]),
            'step' => $request->query('step') === 'environment' ? 'environment' : 'source',
            'preparation_status' => $preparationStatus,
            'runs' => [
                'latest' => $runs->first(),
                'history' => $runs->skip(1)->values()->all(),
            ],
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
