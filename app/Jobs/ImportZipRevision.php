<?php

namespace App\Jobs;

use App\Models\SourceRevision;
use App\Services\Source\SourceRevisionMaterializer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ImportZipRevision implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public function __construct(public readonly string $revisionId, public readonly string $archivePath) {}

    public function handle(SourceRevisionMaterializer $materializer): void
    {
        $materializer->materialize(SourceRevision::query()->findOrFail($this->revisionId), $this->archivePath);
    }
}
