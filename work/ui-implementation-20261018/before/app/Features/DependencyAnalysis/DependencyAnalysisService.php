<?php

namespace App\Features\DependencyAnalysis;

use App\Enums\ProjectArtifactType;
use App\Models\ProjectArtifact;
use App\Models\ProjectSource;
use App\Services\ProjectArtifactPreparation;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class DependencyAnalysisService extends ProjectArtifactPreparation
{
    private const string VERSION = '2.7.0';

    public const string IMAGE = 'ghcr.io/google/osv-scanner:v2.7.0@sha256:4f3b7e2c64b9d38a5febc5a7e4a3dfef1d1362f726dea7a2ef4762b46469fcd0';

    protected function type(): ProjectArtifactType
    {
        return ProjectArtifactType::DEPENDENCY_ANALYSIS;
    }

    protected function metadata(): array
    {
    
        return [
            'producer' => 'osv-scanner',
            'image' => self::IMAGE,
            'version' => self::VERSION,
            'scanned_at' => now()->toIso8601String(),
        ];
    }

    protected function execute(ProjectSource $source, ProjectArtifact $artifact): ProcessResult
    {
        $res = $this->docker->runDependencyAnalysis(
            self::IMAGE,
            Storage::disk('projects')->path($source->sourcePath()),
        );

        return $res;
            }

    protected function successfulExitCodes(): array
    {
        return [0, 1];
    }

    protected function checkIfPartial(array $payload): ?string
    {
        if (! is_array($payload['results'] ?? null) || ! array_is_list($payload['results'])) {
            throw new RuntimeException('OSV-Scanner returned an invalid scan result.');
        }

        return null;
    }
}
