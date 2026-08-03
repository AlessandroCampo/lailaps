<?php

namespace App\Jobs;

use App\Enums\AuditRunStatus;
use App\Models\AuditRun;
use App\Services\Audit\AuditCommandBuilder;
use App\Services\Audit\AuditEventRecorder;
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

    public function handle(AuditCommandBuilder $commands, AuditEventRecorder $events): void
    {
        $run = AuditRun::query()->findOrFail($this->runId);
        if ($run->cancellation_requested) {
            $this->finishCancelled($run, $events);

            return;
        }
        $this->status($run, $events, AuditRunStatus::Preparing);
        if (! is_dir((string) $run->artifact_path)) {
            mkdir((string) $run->artifact_path, 0755, true);
        }
        $process = new Process(
            $commands->build($run),
            base_path(),
            ['LAILAPS_EVENT_STREAM' => '1', 'NO_COLOR' => '1'],
            null,
            ((int) ($run->parameters['ttl'] ?? 1800)) + 300,
        );
        $stdout = '';
        $stderr = '';
        try {
            $this->status($run, $events, AuditRunStatus::Running);
            $process->start();
            while ($process->isRunning()) {
                $stdout .= $process->getIncrementalOutput();
                $stderr .= $process->getIncrementalErrorOutput();
                $this->consumeLines($run, $events, $stdout, false);
                $this->consumeLines($run, $events, $stderr, true);
                $run->refresh();
                if ($run->cancellation_requested) {
                    file_put_contents(dirname((string) $run->artifact_path).'/cancel.requested', now()->toIso8601String());
                    $process->stop(5);
                    break;
                }
                $process->checkTimeout();
                usleep(150000);
            }
            $stdout .= $process->getIncrementalOutput();
            $stderr .= $process->getIncrementalErrorOutput();
            $this->consumeLines($run, $events, $stdout, false, true);
            $this->consumeLines($run, $events, $stderr, true, true);
            $run->refresh();
            if ($run->cancellation_requested) {
                $this->finishCancelled($run, $events);

                return;
            }
            $this->status($run, $events, AuditRunStatus::Finalizing);
            $exitCode = $process->getExitCode() ?? 1;
            $this->publishArtifacts($run, $events);
            $run->update([
                'status' => $exitCode === 0 ? AuditRunStatus::Completed : AuditRunStatus::Failed,
                'exit_code' => $exitCode,
                'finished_at' => now(),
                'error' => $exitCode === 0 ? null : 'Il comando è terminato con codice '.$exitCode,
            ]);
            $events->record($run, 'status', ['status' => $run->status->value, 'exit_code' => $exitCode]);
        } catch (Throwable $exception) {
            if ($process->isRunning()) {
                $process->stop(3);
            }
            $run->refresh();
            if ($run->cancellation_requested) {
                $this->finishCancelled($run, $events);

                return;
            }
            $message = $exception instanceof ProcessTimedOutException ? 'Timeout della run.' : $exception->getMessage();
            $run->update(['status' => AuditRunStatus::Failed, 'error' => $message, 'finished_at' => now()]);
            $events->record($run, 'error', ['message' => $message, 'exception' => $exception::class]);
            $events->record($run, 'status', ['status' => 'failed']);
        }
    }

    private function status(AuditRun $run, AuditEventRecorder $events, AuditRunStatus $status): void
    {
        $values = ['status' => $status];
        if ($status === AuditRunStatus::Preparing) {
            $values['started_at'] = now();
        }
        $run->update($values);
        $events->record($run, 'status', ['status' => $status->value]);
    }

    private function finishCancelled(AuditRun $run, AuditEventRecorder $events): void
    {
        $run->update(['status' => AuditRunStatus::Cancelled, 'finished_at' => now(), 'exit_code' => 130]);
        $events->record($run, 'status', ['status' => 'cancelled']);
    }

    private function consumeLines(AuditRun $run, AuditEventRecorder $events, string &$buffer, bool $error, bool $flush = false): void
    {
        $parts = preg_split('/\R/', $buffer) ?: [];
        $tail = (string) array_pop($parts);
        $buffer = $flush ? '' : $tail;
        foreach ($parts as $line) {
            $line = trim((string) preg_replace('/\e\[[0-9;]*m/', '', $line));
            if ($line === '') {
                continue;
            }
            if (str_starts_with($line, 'LAILAPS_EVENT ')) {
                $event = json_decode(substr($line, 14), true);
                if (is_array($event) && isset($event['type'])) {
                    $events->record(
                        $run,
                        (string) $event['type'],
                        (array) ($event['payload'] ?? []),
                        isset($event['category']) ? (string) $event['category'] : null,
                        isset($event['role']) ? (string) $event['role'] : null,
                        isset($event['artifact_ref']) ? (string) $event['artifact_ref'] : null,
                    );

                    continue;
                }
            }
            $events->record($run, $error ? 'error' : 'log', ['message' => $line, 'stream' => $error ? 'stderr' : 'stdout']);
        }
        if ($flush && trim($tail) !== '') {
            $events->record($run, $error ? 'error' : 'log', ['message' => trim($tail), 'stream' => $error ? 'stderr' : 'stdout']);
        }
    }

    private function publishArtifacts(AuditRun $run, AuditEventRecorder $events): void
    {
        foreach (['report.json' => 'report_published', 'benchmark.json' => 'benchmark_published', 'benchmark-score.json' => 'benchmark_published'] as $file => $type) {
            if (is_file(rtrim((string) $run->artifact_path, '/').'/'.$file)) {
                $events->record($run, $type, ['file' => $file], artifactRef: $file);
            }
        }
    }
}
