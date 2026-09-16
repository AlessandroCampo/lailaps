<?php

use App\Models\Project;
use App\Models\SourceRevision;
use App\Models\User;
use App\Services\Source\SourceRevisionMaterializer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ZipArchive;

uses(RefreshDatabase::class);

function sourceImportZip(array $files): string
{
    $path = storage_path('framework/testing/'.Str::uuid().'.zip');
    File::ensureDirectoryExists(dirname($path));
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE);
    foreach ($files as $name => $contents) {
        $zip->addFromString($name, $contents);
    }
    $zip->close();

    return $path;
}

it('normalizes archive container folders into the same immutable content hash', function (): void {
    $user = User::factory()->create();
    $project = Project::query()->create(['user_id' => $user->id, 'name' => 'Demo']);
    $first = SourceRevision::query()->create(['project_id' => $project->id, 'provider' => 'zip']);
    $second = SourceRevision::query()->create(['project_id' => $project->id, 'provider' => 'github']);
    $materializer = app(SourceRevisionMaterializer::class);

    try {
        $materializer->materialize($first, sourceImportZip(['upload-a/README.md' => 'hello', 'upload-a/src/app.php' => '<?php']));
        $materializer->materialize($second, sourceImportZip(['repository-sha/README.md' => 'hello', 'repository-sha/src/app.php' => '<?php']));

        expect($first->fresh()->status)->toBe('ready')
            ->and($first->fresh()->content_hash)->toBe($second->fresh()->content_hash)
            ->and(is_file($first->fresh()->source_path.'/README.md'))->toBeTrue();
    } finally {
        File::deleteDirectory(storage_path('app/private/lailaps/projects/'.$project->id));
    }
});

it('rejects traversal before publishing a revision', function (): void {
    $user = User::factory()->create();
    $project = Project::query()->create(['user_id' => $user->id, 'name' => 'Traversal']);
    $revision = SourceRevision::query()->create(['project_id' => $project->id, 'provider' => 'zip']);

    expect(fn () => app(SourceRevisionMaterializer::class)->materialize(
        $revision,
        sourceImportZip(['../escape.php' => 'nope']),
    ))->toThrow(RuntimeException::class, 'Traversal');

    expect($revision->fresh()->status)->toBe('failed')->and($revision->fresh()->source_path)->toBeNull();
});
