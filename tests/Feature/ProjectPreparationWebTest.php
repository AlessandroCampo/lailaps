<?php

use App\Jobs\ImportGithubRevision;
use App\Jobs\RunPreparation;
use App\Models\GithubConnection;
use App\Models\Preparation;
use App\Models\Project;
use App\Models\SourceRevision;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('keeps projects and their revisions scoped to their owner', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $project = Project::query()->create(['user_id' => $owner->id, 'name' => 'Private']);

    $this->actingAs($other)->get('/projects/'.$project->id)->assertNotFound();
});

it('resolves a github ref before queuing an immutable commit import', function (): void {
    Queue::fake();
    Http::fake([
        'api.github.com/repositories/42*' => Http::response(['id' => 42, 'full_name' => 'acme/private'], 200),
        'api.github.com/repos/acme/private/commits/main*' => Http::response(['sha' => str_repeat('a', 40)], 200),
    ]);
    $user = User::factory()->create();
    GithubConnection::query()->create([
        'user_id' => $user->id, 'github_user_id' => '7', 'login' => 'tester', 'access_token' => 'secret-token',
    ]);
    $project = Project::query()->create(['user_id' => $user->id, 'name' => 'GitHub']);

    $this->actingAs($user)->post('/projects/'.$project->id.'/github', [
        'repository_id' => '42', 'full_name' => 'acme/private', 'ref' => 'main',
    ])->assertRedirect();

    $revision = SourceRevision::query()->sole();
    expect($revision->github_commit_sha)->toBe(str_repeat('a', 40));
    Queue::assertPushed(ImportGithubRevision::class, fn ($job): bool => $job->revisionId === $revision->id);
});

it('never exposes encrypted preparation answers or host paths', function (): void {
    $user = User::factory()->create();
    $project = Project::query()->create(['user_id' => $user->id, 'name' => 'Secrets']);
    $revision = SourceRevision::query()->create([
        'project_id' => $project->id, 'provider' => 'zip', 'status' => 'ready',
        'content_hash' => str_repeat('b', 64), 'source_path' => 'C:/private/source',
    ]);
    $preparation = Preparation::query()->create([
        'project_id' => $project->id, 'source_revision_id' => $revision->id, 'status' => 'awaiting_input',
        'questions' => [['key' => 'ADMIN_PASSWORD', 'kind' => 'secret', 'prompt' => 'Password?', 'options' => []]],
        'answers' => ['ADMIN_PASSWORD' => 'super-secret'],
        'runtime_path' => 'C:/private/runtime', 'artifacts_path' => 'C:/private/artifacts',
    ]);

    $this->actingAs($user)->get('/preparations/'.$preparation->id)
        ->assertOk()->assertDontSee('super-secret')->assertDontSee('C:/private');
});

it('persists answers and resumes the doctor through the queue', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    $project = Project::query()->create(['user_id' => $user->id, 'name' => 'Resume']);
    $revision = SourceRevision::query()->create([
        'project_id' => $project->id, 'provider' => 'zip', 'status' => 'ready',
        'content_hash' => str_repeat('c', 64), 'source_path' => storage_path('framework'),
    ]);
    $preparation = Preparation::query()->create([
        'project_id' => $project->id, 'source_revision_id' => $revision->id, 'status' => 'awaiting_input',
        'questions' => [['key' => 'DATABASE_PASSWORD', 'kind' => 'secret', 'prompt' => 'Password?', 'options' => []]],
    ]);

    $this->actingAs($user)->post('/preparations/'.$preparation->id.'/answers', [
        'answers' => ['DATABASE_PASSWORD' => 'only-in-backend'],
    ])->assertRedirect();

    expect($preparation->fresh()->status)->toBe('queued')
        ->and(data_get($preparation->fresh()->configuration, 'secret_keys'))->toBe(['DATABASE_PASSWORD']);
    Queue::assertPushed(RunPreparation::class);
});
