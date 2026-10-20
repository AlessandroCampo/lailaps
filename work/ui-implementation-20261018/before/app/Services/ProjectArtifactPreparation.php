<?php

namespace App\Services;

use App\Enums\ProjectArtifactStatus;
use App\Enums\ProjectArtifactType;
use App\Models\ProjectArtifact;
use App\Models\ProjectSource;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

abstract class ProjectArtifactPreparation
{
    public function __construct(protected DockerService $docker) {}

    final public function prep(ProjectSource $source): ProjectArtifact
    {
        $type = $this->type();

        try {
            $artifact = ProjectArtifact::firstOrNew([
                'project_source_id' => $source->id,
                'type' => $type,
            ]);

            if ($artifact->exists && in_array($artifact->status, [ProjectArtifactStatus::PARTIAL, ProjectArtifactStatus::READY])) {
                return $artifact;
            }

            $artifact->fill([
                'status' => ProjectArtifactStatus::RUNNING,
                'error' => null,
                'payload' => null,
                'metadata' => [],
                'content_hash' => null,
            ])->save();

            $metadata = [];
            $raw = null;
            $payload = null;

            try {
                $metadata = $this->metadata();
                $result = $this->execute($source, $artifact);
                $raw = $result->output();
                $exitCode = $result->exitCode();
                $metadata['exit_code'] = $exitCode;

                if ($result->errorOutput() !== '') {
                    $metadata['stderr'] = $result->errorOutput();
                }

                if (! in_array($exitCode, $this->successfulExitCodes(), true)) {
                    $payload = json_decode($raw, true);

                    throw new RuntimeException('Process exited with code '.var_export($exitCode, true).'.');
                }

                $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

                if (! is_array($payload) || array_is_list($payload)) {
                    throw new RuntimeException('The producer returned an invalid result object.');
                }

                $reason = $this->checkIfPartial($payload);
                $status = $reason === null ? ProjectArtifactStatus::READY : ProjectArtifactStatus::PARTIAL;
            } catch (Throwable $error) {
                $status = ProjectArtifactStatus::FAILED;
                $reason = $error->getMessage();

                if (Str::length($reason) > 255) {
                    $metadata['failure'] = $reason;
                }

                if ($raw !== null && ! is_array($payload)) {
                    $metadata['stdout'] = $raw;
                }
            }

            $artifact->update([
                'status' => $status,
                'error' => $reason === null ? null : Str::limit($reason, 255, ''),
                'payload' => is_array($payload) ? $payload : null,
                'metadata' => $metadata,
                'content_hash' => $raw === null ? null : hash('sha256', $raw),
            ]);

            return $artifact;
        } finally {
        }
    }

    protected function successfulExitCodes(): array
    {
        return [0];
    }

    abstract protected function type(): ProjectArtifactType;

    abstract protected function metadata(): array;

    abstract protected function execute(ProjectSource $source, ProjectArtifact $artifact): ProcessResult;

    abstract protected function checkIfPartial(array $payload): ?string;
}
