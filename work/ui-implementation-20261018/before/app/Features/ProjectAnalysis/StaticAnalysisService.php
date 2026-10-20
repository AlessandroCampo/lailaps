<?php

namespace App\Features\ProjectAnalysis;

use App\Enums\ProjectArtifactType;
use App\Models\ProjectArtifact;
use App\Models\ProjectSource;
use App\Services\ProjectArtifactPreparation;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class StaticAnalysisService extends ProjectArtifactPreparation
{
    private const string VERSION = '1.175.0';

    public const string IMAGE = 'semgrep/semgrep:'.self::VERSION.'@sha256:b94b53d02fd4a022f9eac4e2af1380f5c3c4c21400e79d3336bdff1d1db5e796';

    protected function type(): ProjectArtifactType
    {
        return ProjectArtifactType::STATIC_ANALYSIS;
    }

    protected function metadata(): array
    {
        $rulesPath = app_path('Features/ProjectAnalysis/rules');
        $manifest = json_decode(File::get($rulesPath.'/ruleset.lock.json'), true, 512, JSON_THROW_ON_ERROR);
        $corpusHash = hash_file('sha256', $rulesPath.'/community/default.yml');
        $surfaceHash = hash_file('sha256', $rulesPath.'/security-surfaces.yml');

        if (! is_array($manifest) || ($manifest['engine_version'] ?? null) !== self::VERSION
            || ! is_string($manifest['ruleset_id'] ?? null) || ! is_string($manifest['corpus_sha256'] ?? null)
            || ! is_string($corpusHash) || ! is_string($surfaceHash)
            || ! hash_equals($manifest['corpus_sha256'], $corpusHash)) {
            throw new RuntimeException('The Semgrep rules manifest or checksum is invalid.');
        }

        return [
            'producer' => 'semgrep',
            'image' => self::IMAGE,
            'ruleset' => $manifest['ruleset_id'],
            'community_rules_sha256' => $corpusHash,
            'surface_rules_sha256' => $surfaceHash,
        ];
    }

    protected function execute(ProjectSource $source, ProjectArtifact $artifact): ProcessResult
    {
        return $this->docker->runSemgrep(
            self::IMAGE,
            Storage::disk('projects')->path($source->sourcePath()),
            app_path('Features/ProjectAnalysis/rules'),
        );
    }

    protected function checkIfPartial(array $payload): ?string
    {
        if (! is_array($payload['results'] ?? null) || ! array_is_list($payload['results'])
            || ! is_array($payload['errors'] ?? null) || ! array_is_list($payload['errors'])
            || ! is_array($payload['paths']['scanned'] ?? null) || ! array_is_list($payload['paths']['scanned'])
            || ! is_string($payload['version'] ?? null)) {
            throw new RuntimeException('Semgrep returned an invalid scan result.');
        }

        if ($payload['paths']['scanned'] === [] && $payload['results'] === []) {
            throw new RuntimeException('No supported files were scanned.');
        }

        $reasons = [];

        if ($payload['errors'] !== []) {
            $reasons[] = 'Scan errors.';
        }

        if ($payload['paths']['scanned'] === []) {
            $reasons[] = 'No scanned files.';
        }

        if (! empty($payload['skipped_rules'])) {
            $reasons[] = 'Skipped rules.';
        }

        if (! empty($payload['time']['fixpoint_timeouts'])) {
            $reasons[] = 'Analysis timeouts.';
        }

        if ($payload['version'] !== self::VERSION) {
            $reasons[] = 'Unexpected engine version.';
        }

        return $reasons === [] ? null : implode(' ', $reasons);
    }
}
