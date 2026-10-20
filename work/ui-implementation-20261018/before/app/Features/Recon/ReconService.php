<?php

namespace App\Features\Recon;

use App\Enums\AiModel;
use App\Enums\ProjectArtifactStatus;
use App\Enums\ProjectArtifactType;
use App\Models\ProjectArtifact;
use App\Models\ProjectSource;
use App\Services\DockerService;
use App\Services\ProjectArtifactPreparation;
use App\Services\ProjectService;
use Carbon\Carbon;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Override;
use RuntimeException;

final class ReconService extends ProjectArtifactPreparation
{
    public const string IMAGE = 'lailaps-recon:minimal-1d1cb151-cbm0121-osv-ulid';

    public const AiModel RECON_DEFAULT_MODEL = AiModel::GLM_FLASH;

    public function __construct(DockerService $docker, private ProjectService $projects)
    {
        parent::__construct($docker);
    }

    protected function type(): ProjectArtifactType
    {
        return ProjectArtifactType::RECON;
    }

    protected function execute(ProjectSource $source, ProjectArtifact $artifact): ProcessResult
    {

        $storage = Storage::disk('projects');

        $inputsRelativePath = $artifact->path().'/inputs';
        $indexRelativePath = $artifact->path().'/index';

        $inputsPath = $this->projects->prepareDirectory($inputsRelativePath);
        $indexPath = $this->projects->prepareDirectory($indexRelativePath);
        $outputPath = $this->projects->prepareDirectory($artifact->path().'/output');

        $staticAnalysisArtifact = $source->artifacts()->whereIn('status', [ProjectArtifactStatus::READY, ProjectArtifactStatus::PARTIAL])->where('type', ProjectArtifactType::STATIC_ANALYSIS)->first();
        $codeIndexArtifact = $source->artifacts()->whereIn('status', [ProjectArtifactStatus::READY, ProjectArtifactStatus::PARTIAL])->where('type', ProjectArtifactType::CODE_INDEX)->first();
        $dependencyArtifact = $source->artifacts()->whereIn('status', [ProjectArtifactStatus::READY, ProjectArtifactStatus::PARTIAL])->where('type', ProjectArtifactType::DEPENDENCY_ANALYSIS)->first();

        $prepared = [
            'schema_version' => 1,
            'run_id' => 'recon-'.$artifact->id.'-'.Carbon::now()->format('Ymd-His-u'),
            'source_id' => $source->id,
            'source_hash' => $source->content_hash,
            'source_path' => '/workspace/source',
            'source_host_path' => $storage->path($source->sourcePath()),

            'model' => self::RECON_DEFAULT_MODEL->value,
            'reasoning_effort' => 'medium',
            'point_limit' => 160000,
            'request_limit' => 48,
        ];

        if ($staticAnalysisArtifact) {

            $semgrepJson = json_encode(
                $staticAnalysisArtifact->payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            );

            $saved = $storage->put($inputsRelativePath.'/semgrep.json', $semgrepJson);

            if (! $saved) {
                throw new RuntimeException('Unable to save semgrep json');
            }
            $semgrepChecksum = hash('sha256', $semgrepJson);

            $prepared[ProjectArtifactType::STATIC_ANALYSIS->value] = [
                'id' => $staticAnalysisArtifact->id,
                'project_source_id' => $staticAnalysisArtifact->project_source_id,
                'status' => $staticAnalysisArtifact->status->value,
                'reason' => $staticAnalysisArtifact->error,
                'path' => '/inputs/semgrep.json',
                'sha256' => $semgrepChecksum,
            ];

        }

        if ($codeIndexArtifact) {
            $copied = File::copyDirectory(
                $storage->path($codeIndexArtifact->path()),
                $indexPath,
            );

            if (! $copied) {
                throw new RuntimeException('Unable to copy code index');
            }
            $prepared[ProjectArtifactType::CODE_INDEX->value] = [
                'id' => $codeIndexArtifact->id,
                'project_source_id' => $codeIndexArtifact->project_source_id,
                'status' => $codeIndexArtifact->status->value,
                'reason' => $codeIndexArtifact->error,
                'path' => '/index',
                'project' => $codeIndexArtifact->payload['project'],
                'engine_version' => '0.12.1',
            ];
        }

        if ($dependencyArtifact) {
            $dependencyJson = json_encode($dependencyArtifact->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

            if (! $storage->put($inputsRelativePath.'/dependencies.json', $dependencyJson)) {
                throw new RuntimeException('Unable to write dependency analysis inputs.');
            }

            $prepared[ProjectArtifactType::DEPENDENCY_ANALYSIS->value] = [
                'id' => $dependencyArtifact->id,
                'project_source_id' => $dependencyArtifact->project_source_id,
                'status' => $dependencyArtifact->status->value,
                'reason' => $dependencyArtifact->error,
                'path' => '/inputs/dependencies.json',
                'sha256' => hash('sha256', $dependencyJson),
            ];
        }

        $preparedJson = json_encode(
            $prepared,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );

        if (! $storage->put($inputsRelativePath.'/prepared.json', $preparedJson)) {
            throw new RuntimeException('Unable to write Recon inputs.');
        }

        return $this->docker->runRecon(
            self::IMAGE,
            $storage->path($source->sourcePath()),
            $inputsPath,
            $indexPath,
            $outputPath,
        );
    }

    #[Override]
    protected function checkIfPartial(array $payload): ?string
    {
        return match ($payload['preparation_status'] ?? null) {
            'ready' => null,
            'partial' => $payload['preparation_reason'] ?? 'Recon is incomplete.',
            default => throw new RuntimeException('Invalid Recon outcome.'),
        };
    }

    #[Override]
    protected function metadata(): array
    {
        return [
            'producer' => self::RECON_DEFAULT_MODEL->value,
            'image' => self::IMAGE,
        ];
    }
}
