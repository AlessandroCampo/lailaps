<?php

namespace App\Features\CodeIndex;

use App\Enums\ProjectArtifactType;
use App\Models\ProjectArtifact;
use App\Models\ProjectSource;
use App\Services\DockerService;
use App\Services\ProjectArtifactPreparation;
use App\Services\ProjectService;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class CodeIndexService extends ProjectArtifactPreparation
{
    public const string IMAGE = 'lailaps-code-index:0.12.1';

    public function __construct(DockerService $docker, private ProjectService $projects)
    {
        parent::__construct($docker);
    }

    protected function type(): ProjectArtifactType
    {
        return ProjectArtifactType::CODE_INDEX;
    }

    protected function metadata(): array
    {
        return [
            'producer' => 'codebase-memory',
            'image' => self::IMAGE,
        ];
    }

    protected function execute(ProjectSource $source, ProjectArtifact $artifact): ProcessResult
    {
        return $this->docker->runCodebaseMemory(
            self::IMAGE,
            Storage::disk('projects')->path($source->sourcePath()),
            $this->projects->prepareDirectory($artifact->path()),
        );
    }

    protected function checkIfPartial(array $payload): ?string
    {
        if (($payload['status'] ?? null) !== 'indexed' || ! is_string($payload['project'] ?? null)
            || $payload['project'] === '' || ! is_int($payload['nodes'] ?? null) || $payload['nodes'] < 0
            || ! is_int($payload['edges'] ?? null) || $payload['edges'] < 0) {
            throw new RuntimeException('Codebase Memory returned an invalid index result.');
        }

        if ($payload['nodes'] === 0) {
            throw new RuntimeException('No indexable symbols were found.');
        }

        $reasons = [];

        foreach (['parse_partial_count' => 'partial parses', 'parse_unusable_count' => 'unusable parses', 'skipped_count' => 'skipped files'] as $key => $label) {
            if (($payload[$key] ?? 0) > 0) {
                $reasons[] = $payload[$key].' '.$label;
            }
        }

        if ((isset($payload['expected_nodes']) && $payload['nodes'] !== $payload['expected_nodes'])
            || (isset($payload['expected_edges']) && $payload['edges'] !== $payload['expected_edges'])) {
            $reasons[] = 'incomplete graph counts';
        }

        return $reasons === [] ? null : 'Incomplete index: '.implode(', ', $reasons).'.';
    }
}
