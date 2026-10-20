<?php

namespace App\Services;

use App\Enums\AuditRunAreaStatus;
use App\Enums\LabStatus;
use App\Enums\ProjectArtifactStatus;
use App\Enums\ProjectArtifactType;
use App\Enums\ProjectSourceStatus;
use App\Models\AuditRun;
use App\Models\AuditRunArea;
use App\Models\ProjectSource;
use App\Models\ReconArea;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class ProjectService
{
    public function prepareDirectory(string $directory): string
    {
        if (Str::startsWith($directory, ['/', '\\']) || preg_match('/^[A-Za-z]:/', $directory)) {
            throw new RuntimeException('Project storage directories must be relative to the projects disk.');
        }

        $storage = Storage::disk('projects');
        $storage->makeDirectory($directory);
        $path = $storage->path($directory);
        clearstatcache(true, $path);

        if (fileowner($path) !== posix_geteuid() || filegroup($path) !== posix_getegid()) {
            throw new RuntimeException('Project storage directories must belong to the PHP worker UID/GID.');
        }

        $storage->setVisibility($directory, 'private');
        clearstatcache(true, $path);

        if (! is_writable($path)) {
            throw new RuntimeException('The project storage directory is not writable by the PHP worker.');
        }

        return $path;
    }

    public function prepareDoctorWorkspace(ProjectSource $source): array
    {
        $storage = Storage::disk('projects');

        if ($source->status !== ProjectSourceStatus::READY || ! $storage->directoryExists($source->sourcePath())) {
            throw new RuntimeException('The project source is not ready.');
        }

        $directory = $source->project_id.'/doctor/'.Str::uuid();
        $workspace = $directory.'/workspace';
        $artifacts = $directory.'/artifacts';

        foreach ([$artifacts, $workspace] as $path) {
            $this->prepareDirectory($path);
        }

        $copied = File::copyDirectory(
            $storage->path($source->sourcePath()),
            $storage->path($workspace),
        );

        if (! $copied) {
            throw new RuntimeException('Unable to copy the source into the workspace.');
        }

        foreach ($storage->allDirectories($workspace) as $path) {
            $this->prepareDirectory($path);
        }

        return [
            'workspace' => $storage->path($workspace),
            'artifacts' => $storage->path($artifacts),
        ];
    }

    public function prepareAuditRunWorkspace(AuditRun $auditRun, SandboxService $sandbox): array
    {
        $storage = Storage::disk('projects');
        $source = $auditRun->source;

        if (! $source || $source->status !== ProjectSourceStatus::READY || ! $storage->directoryExists($source->sourcePath())) {
            throw new RuntimeException('The project source is not ready.');
        }

        $lab = $auditRun->lab;
        $doctor = $lab?->doctorSession;

        if ($lab?->status !== LabStatus::READY || ! $doctor) {
            throw new RuntimeException('The run has no verified Doctor recipe.');
        }

        $artifacts = $auditRun->artifacts;
        $recon = $artifacts->firstWhere('type', ProjectArtifactType::RECON);

      

        if (! $recon || ! in_array($recon->status, [ProjectArtifactStatus::READY, ProjectArtifactStatus::PARTIAL], true)) {
            throw new RuntimeException('The run has no usable Recon artifact.');
        }

        $doctorPaths = $doctor->paths;
        $manifest = $sandbox->manifest($doctorPaths['artifacts'], $doctorPaths['workspace']);
        $environment = $lab->environment ?? $doctor->environment ?? [];
        $actors = array_map(function (array $actor) use ($environment): array {
            $password = $environment[$actor['password_ref']] ?? null;

            if (! is_string($password) || $password === '') {
                throw new RuntimeException('An application actor password is missing from the verified environment.');
            }

            return [
                'role' => $actor['role'],
                'username' => $actor['username'],
                'password' => $password,
            ];
        }, $manifest['actors']);

        $directory = $auditRun->path();
        $paths = ['source' => $storage->path($source->sourcePath())];

        foreach (['inputs', 'index', 'private', 'output'] as $name) {
            $paths[$name] = $this->prepareDirectory($directory.'/'.$name);
        }

        $descriptors = [];

        foreach ($artifacts as $artifact) {
            if (! in_array($artifact->type, [ProjectArtifactType::STATIC_ANALYSIS, ProjectArtifactType::DEPENDENCY_ANALYSIS, ProjectArtifactType::RECON, ProjectArtifactType::CODE_INDEX], true)) {
                continue;
            }

            $type = $artifact->type->value;
            $descriptor = [
                'id' => $artifact->id,
                'project_source_id' => $artifact->project_source_id,
                'status' => $artifact->status->value,
                'reason' => $artifact->error,
            ];

            if ($artifact->type === ProjectArtifactType::CODE_INDEX) {
                $descriptor['engine_version'] = '0.12.1';

                try {
                    $project = $artifact->payload['project'] ?? null;

                    if (! is_string($project) || $project === '') {
                        throw new RuntimeException('The code index has no native project identifier.');
                    }

                    if (! File::copyDirectory($storage->path($artifact->path()), $paths['index'])) {
                        throw new RuntimeException('Unable to copy the code index.');
                    }

                    $descriptor['path'] = '/index';
                    $descriptor['project'] = $project;
                } catch (Throwable $error) {
                    report($error);
                    $descriptor['status'] = ProjectArtifactStatus::FAILED->value;
                    $descriptor['reason'] = 'Unable to prepare the code index.';
                    $descriptor['path'] = null;
                    $descriptor['project'] = null;
                }
            } else {
                if (! is_array($artifact->payload) || $artifact->payload === []) {
                    throw new RuntimeException('The '.$type.' artifact has no result payload.');
                }

                $json = json_encode($artifact->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

                if (! $storage->put($directory.'/inputs/'.$type.'.json', $json)) {
                    throw new RuntimeException('Unable to export the '.$type.' artifact.');
                }

                $descriptor['path'] = '/inputs/'.$type.'.json';
                $descriptor['sha256'] = hash('sha256', $json);
            }

            $descriptors[$type] = $descriptor;
        }

        $actorsJson = json_encode(['actors' => $actors], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        if (! $storage->put($directory.'/private/actors.json', $actorsJson)) {
            throw new RuntimeException('Unable to export application actors.');
        }

        //maybe convert to DTO
        return [
            'paths' => $paths,
            'descriptors' => $descriptors,
            'doctor_paths' => $doctorPaths,
            'manifest' => $manifest,
            'environment' => $environment,
            'actors_file' => $actors !== [] ? '/private/actors.json' : null,
        ];
    }
}
