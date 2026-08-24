<?php

namespace App\Services\Audit;

use App\Models\AuditRun;
use DateTimeInterface;
use RuntimeException;

/**
 * The only filesystem contract for a run.
 *
 * storage/app/runs/{project}/{category}/{run_id}/
 *   {run_id}-logs.php
 *   {run_id}-outcome.json
 */
final class RunStorage
{
    public function create(string $project, array $categories, ?DateTimeInterface $at = null): array
    {
        $project = substr(self::slug($project), 0, 20);
        $category = self::category($categories);
        $startedAt = $at ?? now();
        $stamp = $startedAt->format('Ymd-His');
        $baseId = "{$project}-{$category}-{$stamp}";
        $runId = $baseId;
        $suffix = 2;

        while (is_dir($this->directory($project, $category, $runId))) {
            $runId = $baseId.'-'.$suffix++;
        }

        $directory = $this->directory($project, $category, $runId);
        $this->initialize($directory, $runId, $startedAt);

        return ['run_id' => $runId, 'directory' => $directory];
    }

    public function initialize(string $directory, string $runId, ?DateTimeInterface $startedAt = null): void
    {
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}$/', $runId) !== 1) {
            throw new RuntimeException('run_id non valido.');
        }
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Impossibile creare la directory della run: {$directory}");
        }

        $log = $this->logPath($directory, $runId);
        if (! is_file($log)) {
            $this->appendLog($directory, $runId, "<?php\n// run_id: {$runId}\n");
        }

        $outcome = $this->outcomePath($directory, $runId);
        if (! is_file($outcome)) {
            $this->writeOutcome($directory, $runId, null, null, $startedAt);
        }
    }

    public function forId(string $project, array $categories, string $runId): string
    {
        $directory = $this->directory(substr(self::slug($project), 0, 20), self::category($categories), $runId);
        $this->initialize($directory, $runId);

        return $directory;
    }

    public function initializeRun(AuditRun $run): void
    {
        $this->initialize((string) $run->run_path, $run->audit_id);
    }

    public function appendLog(string $directory, string $runId, string $contents): void
    {
        if ($contents === '') {
            return;
        }
        $stream = fopen($this->logPath($directory, $runId), 'ab');
        if ($stream === false) {
            throw new RuntimeException('Impossibile aprire il log della run.');
        }
        try {
            if (! flock($stream, LOCK_EX) || fwrite($stream, $contents) === false || ! fflush($stream)) {
                throw new RuntimeException('Impossibile persistere il log della run.');
            }
            flock($stream, LOCK_UN);
        } finally {
            fclose($stream);
        }
    }

    public function writeOutcome(
        string $directory,
        string $runId,
        ?array $report,
        ?array $benchmark,
        ?DateTimeInterface $startedAt = null,
    ): void
    {
        $path = $this->outcomePath($directory, $runId);
        $temporary = $path.'.tmp';
        $existingStartedAt = null;
        if (is_file($path)) {
            try {
                $existing = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
                $existingStartedAt = is_array($existing) && is_string($existing['started_at'] ?? null)
                    ? $existing['started_at']
                    : null;
            } catch (\Throwable) {
                // A fresh outcome will receive the current timestamp below.
            }
        }
        $startedAtValue = $existingStartedAt
            ?? ($startedAt?->format(DateTimeInterface::ATOM) ?? now()->toIso8601String());
        $payload = json_encode([
            'run_id' => $runId,
            'started_at' => $startedAtValue,
            'report' => $report,
            'benchmark' => $benchmark,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (file_put_contents($temporary, $payload, LOCK_EX) === false || ! rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Impossibile persistere outcome.json.');
        }
    }

    public function outcome(AuditRun|string $run, ?string $runId = null): array
    {
        $directory = $run instanceof AuditRun ? (string) $run->run_path : $run;
        $id = $run instanceof AuditRun ? $run->audit_id : (string) $runId;
        $path = $this->outcomePath($directory, $id);
        if (! is_file($path)) {
            return ['run_id' => $id, 'started_at' => null, 'report' => null, 'benchmark' => null];
        }
        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return ['run_id' => $id, 'started_at' => null, 'report' => null, 'benchmark' => null];
        }

        return is_array($decoded) ? $decoded : ['run_id' => $id, 'started_at' => null, 'report' => null, 'benchmark' => null];
    }

    public function logPath(string $directory, string $runId): string
    {
        return rtrim($directory, '/\\').DIRECTORY_SEPARATOR.$runId.'-logs.php';
    }

    public function outcomePath(string $directory, string $runId): string
    {
        return rtrim($directory, '/\\').DIRECTORY_SEPARATOR.$runId.'-outcome.json';
    }

    public static function project(array $parameters): string
    {
        $value = $parameters['project_name'] ?? $parameters['benchmark_id'] ?? $parameters['preset'] ?? $parameters['path'] ?? $parameters['url'] ?? 'project';
        $value = (string) preg_replace('#^https?://#i', '', trim((string) $value));

        return self::slug(basename(rtrim($value, '/\\')) ?: 'project');
    }

    public static function category(array $categories): string
    {
        $slugs = array_values(array_filter(array_map(function (mixed $value): string {
            $value = (string) preg_replace('/^A(?:0[1-9]|10)(?::\d{4})?\s+/i', '', trim((string) $value));

            return self::slug($value);
        }, $categories)));

        return substr($slugs === [] ? 'all' : implode('+', $slugs), 0, 20);
    }

    public static function slug(string $value): string
    {
        $slug = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $value), '-'));

        return substr($slug ?: 'project', 0, 80);
    }

    private function directory(string $project, string $category, string $runId): string
    {
        return storage_path("app/runs/{$project}/{$category}/{$runId}");
    }
}
