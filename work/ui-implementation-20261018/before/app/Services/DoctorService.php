<?php

namespace App\Services;

use App\Enums\AgentSessionStatus;
use App\Models\AgentSession;
use App\Models\ProjectSource;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Throwable;

class DoctorService
{
    public function __construct(
        private ProjectService $projects,
        private DockerService $docker,
        private SandboxService $sandbox,
    ) {}

    public function createSession(ProjectSource $source, ?array $paths = null): AgentSession
    {
        $paths ??= $this->projects->prepareDoctorWorkspace($source);
        $session = AgentSession::create([
            'project_id' => $source->project_id,
            'project_source_id' => $source->id,
            'agent' => 'doctor',
            'model' => config('agent.model') ?? 'not-used',
            'paths' => $paths,
            'expires_at' => now()->addDay(),
        ]);
        $session->appendMessage('Inspect /workspace and save a complete candidate environment recipe in /artifacts.');

        return $session;
    }

    public function run(AgentSession $session, callable $output, bool $artifactsOnly = false, bool $keep = false, bool $decorated = false): void
    {
        if ($session->expires_at->isPast()) {
            throw new RuntimeException('This session has expired.');
        }
        $lock = Cache::lock('agent-session:'.$session->id, 7260);
        if (! $lock->get()) {
            throw new RuntimeException('This agent session already has an active worker.');
        }
        $containerId = null;
        $runtime = null;
        $healthy = false;
        $failure = null;
        $paths = $session->paths;

        try {
            $session->update(['status' => AgentSessionStatus::RUNNING, 'output' => null]);
            if ($session->attempt > 0) {
                $session->appendMessage('Resume the preparation using the existing workspace, artifacts and diagnostics.');
            }
            $output('out', 'Session: '.$session->id."\nArtifacts: ".$paths['artifacts']."\n");
            Process::timeout(10)->run(['docker', 'version', '--format', '{{.Server.Version}}'])->throw();
            Process::timeout(10)->run(['docker', 'compose', 'version'])->throw();
            if ($session->runtime_config !== null) {
                $this->sandbox->destroy($this->sandbox->decodeConfig($session->runtime_config), $paths['artifacts'], $session->id);
                $session->update(['runtime_config' => null]);
            }
            if (! $artifactsOnly) {
                if (! config('agent.openrouter_api_key') || $session->model === 'not-used') {
                    throw new RuntimeException('OPENROUTER_API_KEY and AI_MODEL are required.');
                }
                $sessionUrl = URL::temporarySignedRoute('internal.agent-sessions.show', $session->expires_at, ['agentSession' => $session->id]);
                Http::connectTimeout(2)->timeout(5)->acceptJson()->get($sessionUrl)->throw();
                $containerId = $this->docker->createDoctorContainer($paths['workspace'], $paths['artifacts']);
            }
            $maxAttempts = $artifactsOnly ? 1 : (int) config('sandbox.attempts');
            for ($attempt = $session->attempt + 1; $attempt <= $maxAttempts; $attempt++) {
                $session->update(['attempt' => $attempt, 'status' => AgentSessionStatus::RUNNING, 'output' => null]);
                $output('out', "Preparation attempt {$attempt}/{$maxAttempts}\n");
                if (! $artifactsOnly) {
                    $result = $this->docker->runDoctor($containerId, $sessionUrl, $output, $decorated);
                    if ($result->failed()) {
                        $diagnostic = $this->sandbox->redact(mb_substr($result->errorOutput(), -8000), [
                            ...array_values($session->environment ?? []),
                            config('agent.openrouter_api_key'),
                        ]);

                        throw new RuntimeException('Doctor process failed with exit code '.$result->exitCode().'. The last completed step remains in the database.'.($diagnostic !== '' ? "\n".$diagnostic : ''));
                    }
                    $session->refresh();
                    if (($session->output['decision'] ?? null) !== 'prepare') {
                        throw new RuntimeException('Doctor did not produce a candidate: '.json_encode($session->output, JSON_THROW_ON_ERROR));
                    }
                }
                try {
                    $manifest = $this->sandbox->manifest($paths['artifacts'], $paths['workspace']);
                    $environment = $this->sandbox->environment($manifest, $session->environment ?? []);
                    $session->update(['environment' => $environment]);
                    $runtime = $this->sandbox->configure($manifest, $paths, $session->id, $environment);
                    $session->update(['runtime_config' => json_encode($runtime, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]);
                    $url = $this->sandbox->start($runtime, $paths['artifacts'], $session->id, $manifest, $output);
                    $output('out', 'Checking application readiness: '.$url."\n");
                    $this->sandbox->waitUntilHealthy($url, $manifest['readiness']);
                    $healthy = true;
                    $output('out', 'Application readiness passed: '.$url."\n");
                    if ($keep) {
                        $output('out', 'Stack retained. Cleanup: php artisan sandbox:stop '.$session->id."\n");
                    }
                    break;
                } catch (Throwable $error) {
                    $diagnostic = $error->getMessage();
                    if ($runtime !== null) {
                        $diagnostic .= "\n".$this->sandbox->diagnosticLogs($runtime, $paths['artifacts'], $session->id);
                    }
                    $diagnostic = $this->sandbox->redact(mb_substr($diagnostic, -40_000), $session->environment ?? []);
                    $session->update(['failure_reason' => $diagnostic]);
                    $session->appendMessage("Environment validation failed on attempt {$attempt}/{$maxAttempts}.\n".$diagnostic."\nRepair the existing recipe using this evidence. Do not repeat completed operations. Return prepare after saving the corrected files.");
                    $output('err', $diagnostic."\n");
                    if ($runtime !== null) {
                        $this->sandbox->destroy($runtime, $paths['artifacts'], $session->id);
                        $runtime = null;
                        $session->update(['runtime_config' => null]);
                    }
                    if ($attempt === $maxAttempts) {
                        throw new RuntimeException('Preparation attempts exhausted. Diagnostics and conversation are retained in session '.$session->id.'.');
                    }
                }
            }
            if (! $healthy) {
                throw new RuntimeException('The session has no preparation attempts remaining.');
            }
        } catch (Throwable $error) {
            $failure = $error;
        } finally {
            if ($runtime !== null && (! $healthy || ! $keep)) {
                try {
                    $this->sandbox->destroy($runtime, $paths['artifacts'], $session->id);
                    $session->update(['runtime_config' => null]);
                    $output('out', "Verification stack removed; recipe and conversation retained.\n");
                } catch (Throwable $error) {
                    $output('err', 'Target cleanup failed: '.$error->getMessage()."\n");
                    $failure ??= $error;
                }
            }
            if ($containerId !== null) {
                try {
                    $this->docker->removeContainer($containerId);
                } catch (Throwable $error) {
                    $output('err', 'Toolbox cleanup failed: '.$error->getMessage()."\n");
                    $failure ??= $error;
                }
            }
            try {
                if ($failure !== null) {
                    $reason = $this->sandbox->redact(mb_substr($failure->getMessage()."\n".$session->failure_reason, 0, 40_000), $session->environment ?? []);
                    $session->update(['status' => AgentSessionStatus::FAILED, 'failure_reason' => $reason]);
                } else {
                    $session->update(['status' => AgentSessionStatus::READY, 'failure_reason' => null]);
                }
            } finally {
                $lock->release();
            }
        }
        if ($failure !== null) {
            throw $failure;
        }
    }
}
