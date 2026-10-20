<?php

namespace App\Actions;

use App\DTOs\AuditRun\RunOutcome;
use App\Enums\AiModel;
use App\Enums\AuditRunStatus;
use App\Models\AuditRun;
use App\Services\DockerService;
use App\Services\OutcomeService;
use App\Services\ProjectService;
use App\Services\SandboxService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class RunAuditAction
{
    private const string IMAGE = 'lailaps-agent:minimal-prepared-global-ulid';

    public const array DEFAULT_CONFIGS = [
        'reader_model' => AiModel::MIMO_PRO->value,
        'lead_model' => AiModel::GLM_FLASH->value,
        'reader_reasoning_effort' => 'medium',
        'lead_reasoning_effort' => 'high',
        'reader_concurrency' => 2,
        'lead_concurrency' => 2,
        'point_limit' => 1_000_000,
        'reader_points' => 250_000,
        'worker_points' => 350_000,
    ];

    public function __construct(
        private SandboxService $sandbox,
        private ProjectService $projects,
        private DockerService $docker,
        private OutcomeService $outcomes,
    ) {}

    public function handle(AuditRun $auditRun, ?callable $output = null): AuditRun
    {
        $claimed = DB::transaction(function () use ($auditRun): bool {
            $run = AuditRun::whereKey($auditRun->id)->lockForUpdate()->firstOrFail();
            if ($run->status !== AuditRunStatus::PENDING) {
                return false;
            }
            $run->update(['status' => AuditRunStatus::RUNNING, 'started_at' => now(), 'error' => null]);

            return true;
        });
        $auditRun->refresh();
        if (! $claimed) {
            return $auditRun;
        }

        $sandbox = $this->sandbox;
        $projects = $this->projects;
        $docker = $this->docker;
        $outcomes = $this->outcomes;
        $source = $auditRun->source;
        $emit = static function (string $type, string $text) use ($output): void {
            if ($output !== null) {
                $output($type, $text);
            }
        };
        $storage = Storage::disk('projects');
        $runtime = null;
        $prepared = null;
        $diagnostic = null;
        $cleanupFailed = false;
        $importFailed = false;
        $runOutcome = null;
        $status = AuditRunStatus::FAILED;
        $redactions = ['provider_key' => config('agent.openrouter_api_key')];
        $emit('out', 'Audit run: '.$auditRun->id."\n");

        try {
            $prepared = $projects->prepareAuditRunWorkspace($auditRun, $sandbox);
            $doctorPaths = $prepared['doctor_paths'];
            $manifest = $prepared['manifest'];
            $redactions = [...$prepared['environment'], ...$redactions];
            $targetOutput = function (string $type, string $text) use ($sandbox, $redactions, $emit): void {
                $emit($type, $sandbox->redact($text, $redactions));
            };

            $runtime = $sandbox->configure($manifest, $doctorPaths, (string) $auditRun->id, $prepared['environment']);
            $hostUrl = $sandbox->start($runtime, $doctorPaths['artifacts'], (string) $auditRun->id, $manifest, $targetOutput);
            $sandbox->waitUntilHealthy($hostUrl, $manifest['readiness']);

            $webContainerName = $runtime['services'][$manifest['web_service']]['container_name'];
            $inspectionJson = Process::timeout(10)->run([
                'docker', 'inspect',
                '--format', '{"id":{{json .Id}},"networks":{{json .NetworkSettings.Networks}}}',
                $webContainerName,
            ])->throw()->output();
            $inspection = json_decode($inspectionJson, true, 512, JSON_THROW_ON_ERROR);
            $attachedNetworks = array_keys($inspection['networks']);
            $networkName = null;

            foreach ($runtime['networks'] ?? [] as $network) {
                if (in_array($network['name'], $attachedNetworks, true) && ! ($network['internal'] ?? false)) {
                    $networkName = $network['name'];
                    break;
                }
            }

            if ($networkName === null) {
                throw new RuntimeException('No shared network with outbound access is configured.');
            }

            $runInputs = [
                ...$auditRun->configs,
                ...$prepared['descriptors'],
                'schema_version' => 1,
                'run_id' => (string) $auditRun->id,
                'source_id' => $source->id,
                'source_hash' => $source->content_hash,
                'source_path' => '/workspace/source',
                'source_host_path' => $prepared['paths']['source'],
                'actors_file' => $prepared['actors_file'],
                'target_url' => 'http://'.$webContainerName.':'.$manifest['web_port'],
                'target_container_id' => $inspection['id'],
                'target_session_id' => (string) $auditRun->id,
            ];

            if (! $storage->put($auditRun->path().'/inputs/run.json', json_encode($runInputs, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))) {
                throw new RuntimeException('Unable to write run inputs.');
            }

            if (! $storage->put($auditRun->path().'/output/runtime.log', '')) {
                throw new RuntimeException('Unable to create the runtime log.');
            }

            $emit('out', "Starting Python audit.\n");
            $result = $docker->runAudit(
                self::IMAGE,
                $prepared['paths'],
                $networkName,
                function (string $type, string $text) use ($sandbox, $redactions, $prepared, $emit): void {
                    if ($type !== 'err') {
                        return;
                    }

                    $text = $sandbox->redact($text, $redactions);
                    if (file_put_contents($prepared['paths']['output'].'/runtime.log', $text, FILE_APPEND) !== strlen($text)) {
                        throw new RuntimeException('Unable to append the runtime log.');
                    }
                    $emit($type, $text);
                },
            );

            $outcomePath = $auditRun->path().'/output/outcome.json';
            if (! $storage->exists($outcomePath)) {
                throw new RuntimeException('Python did not write outcome.json. Exit code '.$result->exitCode().': '.mb_substr($result->errorOutput(), -8_000));
            }

            $outcome = json_decode($storage->get($outcomePath), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($outcome)
                || ($outcome['schema_version'] ?? null) !== 1
                || ($outcome['run_id'] ?? null) !== (string) $auditRun->id
                || ($outcome['project_source_id'] ?? null) !== $source->id
                || ($outcome['source_hash'] ?? null) !== $source->content_hash) {
                throw new RuntimeException('The Python outcome does not identify this run and source.');
            }

            if ($result->failed() || ($outcome['run_status'] ?? null) === 'failed') {
                if (($outcome['run_status'] ?? null) === 'failed') {
                    $runOutcome = RunOutcome::fromArray($outcome);
                }
                throw new RuntimeException('Python audit failed (exit code '.$result->exitCode().'): '.($outcome['run_reason'] ?? mb_substr($result->errorOutput(), -8_000)));
            }

            $finalStatus = match ($outcome['run_status'] ?? null) {
                'completed' => AuditRunStatus::COMPLETED,
                'partial' => AuditRunStatus::PARTIAL,
                default => throw new RuntimeException('Python did not publish a final audit outcome.'),
            };
            if (! is_array($outcome['report'] ?? null) || ($outcome['report']['command_failed'] ?? false)) {
                throw new RuntimeException('Python did not publish a usable audit report.');
            }

            $runOutcome = RunOutcome::fromArray($outcome);
            $status = $finalStatus;
        } catch (Throwable $error) {
            $diagnostic = $error->getMessage();
            if ($runtime !== null) {
                try {
                    $diagnostic .= "\n".$sandbox->diagnosticLogs($runtime, $prepared['doctor_paths']['artifacts'], (string) $auditRun->id);
                } catch (Throwable $logError) {
                    $diagnostic .= "\nDiagnostic collection failed: ".$logError->getMessage();
                }
            }
            $diagnostic = $sandbox->redact(mb_substr($diagnostic, -40_000), $redactions);
        } finally {
            if ($runtime !== null) {
                try {
                    $sandbox->destroy($runtime, $prepared['doctor_paths']['artifacts'], (string) $auditRun->id);
                } catch (Throwable $cleanupError) {
                    $cleanupFailed = true;
                    $message = $sandbox->redact('Target cleanup failed: '.$cleanupError->getMessage(), $redactions);
                    $diagnostic = trim(($diagnostic ?? '')."\n".$message);
                    $emit('err', $message."\n");
                }
            }
        }

        $auditRun->update(['status' => $status, 'error' => $diagnostic]);
        if ($runOutcome !== null) {
            try {
                $outcomes->persist($auditRun, $runOutcome);
                $emit('out', "Outcome, areas and leads persisted.\n");
            } catch (Throwable $importError) {
                $importFailed = true;
                $message = $sandbox->redact('Outcome persistence failed: '.$importError->getMessage(), $redactions);
                $diagnostic = trim(($diagnostic ?? '')."\n".$message);
                $auditRun->update(['error' => $diagnostic]);
            }
        }
        if ($diagnostic !== null) {
            try {
                if ($storage->directoryExists($auditRun->path().'/output')) {
                    if (! $storage->put($auditRun->path().'/output/error.log', $diagnostic)) {
                        throw new RuntimeException('Unable to write error.log.');
                    }
                }
            } catch (Throwable $logError) {
                $emit('err', $sandbox->redact('Unable to save diagnostics: '.$logError->getMessage(), $redactions)."\n");
            }
        }

        $auditRun->update(['finished_at' => now()]);
        $emit('out', 'Audit status: '.$status->value."\n");
        $emit('out', 'Output directory: '.$storage->path($auditRun->path().'/output')."\n");
        if ($status === AuditRunStatus::FAILED || $cleanupFailed || $importFailed) {
            throw new RuntimeException($diagnostic ?? 'Audit execution failed.');
        }

        return $auditRun->refresh();
    }
}
