<?php

namespace App\Jobs;

use App\Models\Preparation;
use App\Services\Preparation\PreparationDoctorRunner;
use App\Services\Sandbox\Audit\AuditProfile;
use App\Services\Sandbox\Audit\SandboxPreparationService;
use App\Services\Sandbox\DTO\SandboxSpecDTO;
use App\Services\Sandbox\SandboxService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class RunPreparation implements ShouldQueue
{
    use Queueable;

    public int $timeout = 2100;

    public function __construct(public readonly string $preparationId) {}

    public function handle(
        PreparationDoctorRunner $doctor,
        SandboxService $sandboxes,
        SandboxPreparationService $preflight,
    ): void {
        $limit = (int) config('audits.doctor.timeout', 1800);
        $lock = Cache::lock('lailaps-active-environment', $limit + 600);
        if (! $lock->get()) {
            $this->release(10);

            return;
        }
        $started = microtime(true);
        try {
            $preparation = Preparation::query()->with('revision')->findOrFail($this->preparationId);
            if ($preparation->cancellation_requested) {
                $preparation->update(['status' => 'cancelled']);

                return;
            }
            $paths = $this->initializeWorkspace($preparation);
            $preparation->update(['status' => 'discovering', 'started_at' => $preparation->started_at ?? now()]);
            $runtimeError = null;

            while ((microtime(true) - $started) < $limit) {
                $preparation->refresh();
                if ($preparation->cancellation_requested) {
                    $sandboxes->destroyByAuditId($this->sandboxId($preparation));
                    $preparation->update(['status' => 'cancelled']);

                    return;
                }
                $remaining = max(1, $limit - (int) (microtime(true) - $started));
                $payload = $doctor->run(
                    $preparation,
                    $preparation->revision->sourceRoot(),
                    $paths['artifacts'],
                    $this->doctorState($preparation, $paths['artifacts'], $runtimeError),
                    $remaining,
                );
                $decision = $payload['decision'];
                $usage = (array) ($preparation->doctor_usage ?? []);
                $usage['model_calls'] = ((int) ($usage['model_calls'] ?? 0)) + 1;
                $usage['last'] = $payload['usage'] ?? [];
                $updates = [
                    'summary' => (string) ($decision['checkpoint_summary'] ?? $decision['reason'] ?? ''),
                    'actors' => array_values((array) ($decision['actors_summary'] ?? [])),
                    'doctor_usage' => $usage,
                    'failure_reason' => null,
                ];

                if (($decision['decision'] ?? null) === 'ask') {
                    $updates['status'] = 'awaiting_input';
                    $updates['questions'] = array_values((array) ($decision['questions'] ?? []));
                    $preparation->update($updates);

                    return;
                }
                if (($decision['decision'] ?? null) === 'blocked') {
                    $updates['status'] = 'blocked';
                    $updates['failure_reason'] = (string) ($decision['reason'] ?? 'Preparazione bloccata.');
                    $preparation->update($updates);

                    return;
                }
                if (($decision['decision'] ?? null) !== 'prepare') {
                    throw new RuntimeException('Decisione Doctor non riconosciuta.');
                }

                $configuration = array_filter([
                    'service' => $decision['service'] ?? null,
                    'health_path' => $decision['health_path'] ?? null,
                    'compose_file' => $decision['compose_file'] ?? null,
                    'dockerfile' => $decision['dockerfile'] ?? null,
                    'audit_profile' => $decision['audit_profile'] ?? null,
                ], static fn (mixed $value): bool => $value !== null && $value !== '');
                $configuration = array_replace($configuration, (array) ($preparation->configuration ?? []));
                $this->publishArtifacts($paths['artifacts'], $this->runtimeApplicationRoot($preparation, $paths['runtime']));
                $preparation->update([...$updates, 'status' => 'preparing', 'configuration' => $configuration, 'questions' => []]);

                try {
                    $capabilities = $this->verifyEnvironment($preparation, $configuration, $paths['runtime'], $sandboxes, $preflight);
                    $preparation->update([
                        'status' => 'ready',
                        'capabilities' => $capabilities,
                        'fingerprint' => $this->fingerprint($preparation, $configuration, $paths['artifacts']),
                        'completed_at' => now(),
                        'failure_reason' => null,
                    ]);

                    return;
                } catch (Throwable $exception) {
                    $runtimeError = [
                        'message' => mb_substr($exception->getMessage(), 0, 4000),
                        'logs' => $sandboxes->diagnosticLogs($this->sandboxId($preparation)),
                    ];
                    $sandboxes->destroyByAuditId($this->sandboxId($preparation));
                    $preparation->update(['status' => 'diagnosing', 'failure_reason' => $runtimeError['message']]);
                }
            }

            $preparation->update([
                'status' => 'timed_out',
                'failure_reason' => 'Limite di 30 minuti di lavoro attivo raggiunto; lavoro e artefatti conservati.',
            ]);
        } catch (Throwable $exception) {
            $preparation = Preparation::query()->find($this->preparationId);
            if ($preparation && ! in_array($preparation->status, ['awaiting_input', 'blocked', 'ready', 'cancelled'], true)) {
                $preparation->update(['status' => 'failed', 'failure_reason' => mb_substr($exception->getMessage(), 0, 4000)]);
            }
        } finally {
            if (isset($preparation)) {
                $preparation->increment('active_seconds', max(1, (int) ceil(microtime(true) - $started)));
            }
            $lock->release();
        }
    }

    /** @return array{runtime: string, artifacts: string} */
    private function initializeWorkspace(Preparation $preparation): array
    {
        $base = storage_path("app/private/lailaps/projects/{$preparation->project_id}/preparations/{$preparation->id}");
        $runtime = $base.'/runtime';
        $artifacts = $base.'/artifacts';
        if (! is_dir($runtime)) {
            File::ensureDirectoryExists($base);
            if (! File::copyDirectory((string) $preparation->revision->source_path, $runtime)) {
                throw new RuntimeException('Impossibile creare la copia runtime della revisione.');
            }
            if ($preparation->basedOn && is_dir((string) $preparation->basedOn->artifacts_path)) {
                File::copyDirectory((string) $preparation->basedOn->artifacts_path, $artifacts);
            }
        }
        File::ensureDirectoryExists($artifacts);
        $preparation->update([
            'runtime_path' => str_replace('\\', '/', $runtime),
            'artifacts_path' => str_replace('\\', '/', $artifacts),
        ]);

        return ['runtime' => $runtime, 'artifacts' => $artifacts];
    }

    /** @return array<string, mixed> */
    private function doctorState(Preparation $preparation, string $artifacts, ?array $runtimeError): array
    {
        $secretKeys = (array) data_get($preparation->configuration, 'secret_keys', []);
        $answers = [];
        foreach ((array) $preparation->answers as $key => $value) {
            $answers[$key] = in_array($key, $secretKeys, true)
                ? (((string) $value) !== '' ? 'PROVIDED' : 'NOT_PROVIDED')
                : $value;
        }

        return [
            'checkpoint_summary' => $preparation->summary,
            'answers' => $answers,
            'operator_configuration' => $preparation->configuration ?? [],
            'existing_artifacts' => collect(File::files($artifacts))->map->getFilename()->values()->all(),
            'runtime_error' => $runtimeError,
        ];
    }

    private function publishArtifacts(string $artifacts, string $runtime): void
    {
        foreach (['Dockerfile.lailaps', 'compose.lailaps.yml', 'lailaps.audit.yaml', 'prepare.sh'] as $name) {
            if (is_file($artifacts.'/'.$name)) {
                File::copy($artifacts.'/'.$name, $runtime.'/'.$name);
            }
        }
    }

    /** @param array<string, mixed> $configuration
     *  @return array<string, mixed>
     */
    private function verifyEnvironment(
        Preparation $preparation,
        array $configuration,
        string $runtime,
        SandboxService $sandboxes,
        SandboxPreparationService $preflight,
    ): array {
        $runtimeRoot = $this->runtimeApplicationRoot($preparation, $runtime);
        $profilePath = $this->relativeFile($runtimeRoot, $configuration['audit_profile'] ?? null);
        $profile = $profilePath ? AuditProfile::fromPath($profilePath) : AuditProfile::fromProject($runtimeRoot);
        $compose = $this->relativeFile($runtimeRoot, $configuration['compose_file'] ?? null);
        $sandboxId = $this->sandboxId($preparation);
        $sandboxes->destroyByAuditId($sandboxId);

        $restore = $this->installSecrets($preparation);
        try {
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $sandbox = $sandboxes->spawn(new SandboxSpecDTO(
                    auditId: $sandboxId,
                    projectPath: $runtimeRoot,
                    ttlSeconds: (int) config('sandbox.defaults.ttl'),
                    webService: $configuration['service'] ?? $profile->service,
                    healthPath: $configuration['health_path'] ?? $profile->healthPath,
                    dockerfile: $configuration['dockerfile'] ?? null,
                    composeFile: $compose,
                    keepOnFailure: true,
                ));
                try {
                    $preflight->prepare($sandbox, $profile);
                    $preflight->verifyReadiness($sandbox, $profile);
                } finally {
                    $sandboxes->teardown($sandbox);
                }
            }
        } finally {
            $this->restoreEnvironment($restore);
        }

        return [
            'http' => ['available' => true, 'verified' => true],
            'commands' => ['available' => true, 'verified' => true],
            'database' => ['available' => $profile->database !== [], 'verified' => $profile->database !== []],
            'browser' => ['available' => (bool) data_get(config('pentest.agent'), 'browser.enabled'), 'verified' => false],
            'reset' => ['available' => true, 'verified' => true],
        ];
    }

    private function runtimeApplicationRoot(Preparation $preparation, string $runtime): string
    {
        $subdirectory = $preparation->revision->application_subdirectory;
        $path = $subdirectory ? $runtime.'/'.trim($subdirectory, '/') : $runtime;
        if (! is_dir($path)) {
            throw new RuntimeException('Sottodirectory applicativa assente nella copia runtime.');
        }

        return $path;
    }

    private function relativeFile(string $root, mixed $relative): ?string
    {
        if (! is_string($relative) || trim($relative) === '') {
            return null;
        }
        if (str_contains($relative, '..') || preg_match('#^(?:[A-Za-z]:[\\/]|/)#', $relative)) {
            throw new RuntimeException('Percorso di configurazione non valido.');
        }
        $path = realpath($root.'/'.str_replace('\\', '/', $relative));
        $resolvedRoot = realpath($root);
        if ($path === false || $resolvedRoot === false || ! is_file($path) || ! str_starts_with($path, $resolvedRoot.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException("File di configurazione inesistente: {$relative}");
        }

        return $path;
    }

    /** @return array<string, string|false> */
    private function installSecrets(Preparation $preparation): array
    {
        $secretKeys = (array) data_get($preparation->configuration, 'secret_keys', []);
        $restore = [];
        foreach ($secretKeys as $key) {
            $restore[$key] = getenv((string) $key);
            $value = (string) data_get($preparation->answers, $key, '');
            if ($value !== '') {
                putenv($key.'='.$value);
            }
        }

        return $restore;
    }

    /** @param array<string, string|false> $restore */
    private function restoreEnvironment(array $restore): void
    {
        foreach ($restore as $key => $value) {
            putenv($value === false ? $key : $key.'='.$value);
        }
    }

    /** @param array<string, mixed> $configuration */
    private function fingerprint(Preparation $preparation, array $configuration, string $artifacts): string
    {
        $files = [];
        foreach (File::files($artifacts) as $file) {
            if (! str_starts_with($file->getFilename(), 'doctor-')) {
                $files[$file->getFilename()] = hash_file('sha256', $file->getPathname());
            }
        }
        ksort($files);

        return hash('sha256', json_encode([
            'source' => $preparation->revision->content_hash,
            'configuration' => $configuration,
            'answers' => $preparation->answers,
            'files' => $files,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function sandboxId(Preparation $preparation): string
    {
        return 'prep-'.$preparation->id;
    }
}
