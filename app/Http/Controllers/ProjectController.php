<?php

namespace App\Http\Controllers;

use App\Jobs\ImportGithubRevision;
use App\Jobs\ImportZipRevision;
use App\Models\Project;
use App\Models\SourceRevision;
use App\Services\Source\GithubAppClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

final class ProjectController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('projects/Index', [
            'projects' => $request->user()->projects()->withCount(['revisions', 'preparations'])->latest()->get()->map(fn (Project $project): array => [
                'id' => $project->id, 'name' => $project->name,
                'revisions_count' => $project->revisions_count, 'preparations_count' => $project->preparations_count,
            ]),
            'github' => $request->user()->githubConnection
                ? ['login' => $request->user()->githubConnection->login, 'revoked' => $request->user()->githubConnection->revoked_at !== null]
                : null,
            'githubConfigured' => (string) config('services.github_app.client_id') !== '',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:160']]);
        $project = $request->user()->projects()->create($validated);

        return to_route('projects.show', $project);
    }

    public function show(Request $request, Project $project, GithubAppClient $github): Response
    {
        $this->authorizeProject($request, $project);
        $project->load(['revisions' => fn ($query) => $query->latest(), 'preparations' => fn ($query) => $query->latest()]);
        $repositories = null;
        $githubError = null;
        if ($request->user()->githubConnection) {
            try {
                $repositories = $github->repositories($request->user()->githubConnection, max(1, $request->integer('github_page', 1)));
            } catch (Throwable $exception) {
                $githubError = $exception->getMessage();
            }
        }

        if (is_array($repositories)) {
            $repositories['items'] = collect($repositories['items'])->map(fn (array $repository): array => [
                'id' => $repository['id'], 'full_name' => $repository['full_name'],
                'default_branch' => $repository['default_branch'] ?? 'main', 'private' => (bool) ($repository['private'] ?? false),
            ])->values()->all();
        }
        return Inertia::render('projects/Show', [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'revisions' => $project->revisions->map(fn (SourceRevision $revision): array => [
                    'id' => $revision->id, 'provider' => $revision->provider, 'status' => $revision->status,
                    'content_hash' => $revision->content_hash, 'github_full_name' => $revision->github_full_name,
                    'github_commit_sha' => $revision->github_commit_sha, 'provider_metadata' => $revision->provider_metadata,
                    'error' => $revision->error,
                ])->values(),
                'preparations' => $project->preparations->map(fn ($preparation): array => [
                    'id' => $preparation->id, 'source_revision_id' => $preparation->source_revision_id,
                    'status' => $preparation->status, 'summary' => $preparation->summary,
                ])->values(),
            ],
            'repositories' => $repositories,
            'githubError' => $githubError,
        ]);
    }

    public function upload(Request $request, Project $project): RedirectResponse
    {
        $this->authorizeProject($request, $project);
        $maxKb = (int) ceil(((int) config('audits.imports.compressed_bytes')) / 1024);
        $validated = $request->validate([
            'archive' => ['required', 'file', 'mimes:zip', 'max:'.$maxKb],
            'application_subdirectory' => ['nullable', 'string', 'max:1024', 'not_regex:/\.\./'],
        ]);
        $revision = $project->revisions()->create([
            'provider' => 'zip',
            'status' => 'pending',
            'application_subdirectory' => $validated['application_subdirectory'] ?? null,
            'original_filename' => $request->file('archive')->getClientOriginalName(),
        ]);
        $directory = storage_path('app/private/lailaps/incoming');
        File::ensureDirectoryExists($directory);
        $request->file('archive')->move($directory, $revision->id.'.zip');
        ImportZipRevision::dispatch($revision->id, $directory.'/'.$revision->id.'.zip');

        return back()->with('success', 'Importazione ZIP accodata.');
    }

    public function importGithub(Request $request, Project $project, GithubAppClient $github): RedirectResponse
    {
        $this->authorizeProject($request, $project);
        $validated = $request->validate([
            'repository_id' => ['required', 'string', 'max:64'],
            'full_name' => ['required', 'string', 'max:255'],
            'ref' => ['required', 'string', 'max:255'],
            'application_subdirectory' => ['nullable', 'string', 'max:1024', 'not_regex:/\.\./'],
        ]);
        $connection = $request->user()->githubConnection;
        abort_if($connection === null, 409, 'Collega prima la GitHub App.');
        $resolved = $github->resolveCommit($connection, $validated['repository_id'], $validated['full_name'], $validated['ref']);
        $revision = $project->revisions()->create([
            'provider' => 'github',
            'status' => 'pending',
            'application_subdirectory' => $validated['application_subdirectory'] ?? null,
            'github_repository_id' => $resolved['repository_id'],
            'github_full_name' => $resolved['full_name'],
            'github_commit_sha' => $resolved['sha'],
            'github_ref' => $validated['ref'],
        ]);
        ImportGithubRevision::dispatch($revision->id);

        return back()->with('success', 'Importazione GitHub accodata sul commit '.$resolved['sha'].'.');
    }

    public function branches(Request $request, Project $project, GithubAppClient $github): JsonResponse
    {
        $this->authorizeProject($request, $project);
        $validated = $request->validate(['repository' => ['required', 'string'], 'page' => ['nullable', 'integer', 'min:1']]);
        abort_if($request->user()->githubConnection === null, 409);

        return response()->json($github->branches($request->user()->githubConnection, $validated['repository'], (int) ($validated['page'] ?? 1)));
    }

    public function commits(Request $request, Project $project, GithubAppClient $github): JsonResponse
    {
        $this->authorizeProject($request, $project);
        $validated = $request->validate([
            'repository' => ['required', 'string'], 'sha' => ['nullable', 'string'], 'page' => ['nullable', 'integer', 'min:1'],
        ]);
        abort_if($request->user()->githubConnection === null, 409);

        return response()->json($github->commits($request->user()->githubConnection, $validated['repository'], $validated['sha'] ?? null, (int) ($validated['page'] ?? 1)));
    }

    private function authorizeProject(Request $request, Project $project): void
    {
        abort_unless($project->user_id === $request->user()->id, 404);
    }
}
