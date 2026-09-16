<?php

namespace App\Jobs;

use App\Models\SourceRevision;
use App\Services\Source\GithubAppClient;
use App\Services\Source\SourceRevisionMaterializer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use RuntimeException;

final class ImportGithubRevision implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public function __construct(public readonly string $revisionId) {}

    public function handle(GithubAppClient $github, SourceRevisionMaterializer $materializer): void
    {
        $revision = SourceRevision::query()->with('project.user.githubConnection')->findOrFail($this->revisionId);
        $connection = $revision->project->user->githubConnection;
        if ($connection === null) {
            $revision->update(['status' => 'failed', 'error' => 'Connessione GitHub assente o revocata.']);
            throw new RuntimeException('Connessione GitHub assente o revocata.');
        }
        $path = storage_path("app/private/lailaps/incoming/{$revision->id}.zip");
        File::ensureDirectoryExists(dirname($path));
        $github->downloadCommit(
            $connection,
            (string) $revision->github_repository_id,
            (string) $revision->github_full_name,
            (string) $revision->github_commit_sha,
            $path,
        );
        $materializer->materialize($revision, $path);
    }
}
