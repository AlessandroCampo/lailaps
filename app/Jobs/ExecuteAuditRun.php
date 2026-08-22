<?php

namespace App\Jobs;

use App\Enums\AuditRunStatus;
use App\Models\AuditRun;
use App\Services\Audit\AuditCommandBuilder;
use App\Services\Audit\AuditRunFinalizer;
use App\Services\Audit\RunStorage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

final class ExecuteAuditRun implements ShouldQueue
{
    use Queueable;

    public int $timeout = 87000;

    public function __construct(public readonly string $runId) {}

    public function handle(AuditCommandBuilder $commands, AuditRunFinalizer $finalizer, RunStorage $storage): void
    {
        $run = AuditRun::query()->findOrFail($this->runId);
        $storage->initializeRun($run);
        if ($run->cancellation_requested) {
            $this->finishCancelled($run, $storage);
            $this->finalizeSafely($run, $finalizer, $storage);

            return;
        }

        $this->status($run, AuditRunStatus::Preparing, $storage);
        $cancelMarker = storage_path("framework/lailaps-cancel/{$run->audit_id}");
        if (is_file($cancelMarker)) {
            unlink($cancelMarker);
        }
        $process = new Process(
            $commands->build($run),
            base_path(),
            [
                'LAILAPS_RUN_DIRECTORY' => (string) $run->run_path,
                'LAILAPS_RUN_ID' => $run->audit_id,
                'LAILAPS_OUTCOME_FILE' => $storage->outcomePath((string) $run->run_path, $run->audit_id),
                // Il processo figlio scrive un transcript umano. Report e
                // telemetria strutturata vivono nell'outcome JSON separato.
                'LAILAPS_EVENT_STREAM' => '0',
                'LAILAPS_SUPERVISED_LOG' => '1',
                'NO_COLOR' => '1',
            ],
            null,
            ((int) ($run->parameters['ttl'] ?? 9000)) + 300,
        );

        try {
            $this->status($run, AuditRunStatus::Running, $storage);
            $process->start(function (string $type, string $buffer) use ($run, $storage): void {
                $storage->appendLog((string) $run->run_path, $run->audit_id, $buffer);
            });
            while ($process->isRunning()) {
                $run->refresh();
                if ($run->cancellation_requested) {
                    $directory = dirname($cancelMarker);
                    if (! is_dir($directory)) {
                        mkdir($directory, 0755, true);
                    }
                    file_put_contents($cancelMarker, now()->toIso8601String());
                    $process->stop(5);
                    break;
                }
                $process->checkTimeout();
                usleep(150000);
            }
            $run->refresh();
            if ($run->cancellation_requested) {
                $this->finishCancelled($run, $storage);
                $this->finalizeSafely($run, $finalizer, $storage);

                return;
            }
            $this->status($run, AuditRunStatus::Finalizing, $storage);
            $exitCode = $process->getExitCode() ?? 1;
            $run->update([
                'status' => $exitCode === 0 ? AuditRunStatus::Completed : AuditRunStatus::Failed,
                'exit_code' => $exitCode,
                'finished_at' => now(),
                'error' => $exitCode === 0 ? null : 'Il comando è terminato con codice '.$exitCode,
            ]);
            $storage->appendLog((string) $run->run_path, $run->audit_id, '[system] status='.$run->status->value." exit_code={$exitCode}\n");
            $this->finalizeSafely($run, $finalizer, $storage);
        } catch (Throwable $exception) {
            if ($process->isRunning()) {
                $process->stop(3);
            }
            $run->refresh();
            if ($run->cancellation_requested) {
                $this->finishCancelled($run, $storage);
                $this->finalizeSafely($run, $finalizer, $storage);

                return;
            }
            $message = $exception instanceof ProcessTimedOutException ? 'Timeout della run.' : $exception->getMessage();
            $storage->appendLog((string) $run->run_path, $run->audit_id, "[system:error] {$message}\n");
            $run->update(['status' => AuditRunStatus::Failed, 'error' => $message, 'finished_at' => now()]);
            $this->finalizeSafely($run, $finalizer, $storage);
        } finally {
            if (is_file($cancelMarker)) {
                unlink($cancelMarker);
            }
        }
    }

    private function finalizeSafely(AuditRun $run, AuditRunFinalizer $finalizer, RunStorage $storage): void
    {
        try {
            $finalizer->finalize($run->fresh());
        } catch (Throwable $exception) {
            $storage->appendLog((string) $run->run_path, $run->audit_id, "[system:finalizer-error] {$exception->getMessage()}\n");
        }
    }

    private function status(AuditRun $run, AuditRunStatus $status, RunStorage $storage): void
    {
        $values = ['status' => $status];
        if ($status === AuditRunStatus::Preparing) {
            $values['started_at'] = now();
        }
        $run->update($values);
        $storage->appendLog((string) $run->run_path, $run->audit_id, "[system] status={$status->value}\n");
    }

    private function finishCancelled(AuditRun $run, RunStorage $storage): void
    {
        $run->update(['status' => AuditRunStatus::Cancelled, 'finished_at' => now(), 'exit_code' => 130]);
        $storage->appendLog((string) $run->run_path, $run->audit_id, "[system] status=cancelled\n");
    }
}
