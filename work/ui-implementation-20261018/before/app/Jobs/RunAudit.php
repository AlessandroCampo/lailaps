<?php

namespace App\Jobs;

use App\Actions\RunAuditAction;
use App\Enums\AuditRunStatus;
use App\Models\AuditRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RunAudit implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 7200;

    public bool $failOnTimeout = true;

    public function __construct(public string $auditRunId) {}

    public function handle(RunAuditAction $runAudit): void
    {
        $runAudit->handle(AuditRun::findOrFail($this->auditRunId));
    }

    public function failed(?Throwable $error): void
    {
        AuditRun::whereKey($this->auditRunId)
            ->whereIn('status', [AuditRunStatus::PENDING, AuditRunStatus::RUNNING])
            ->update([
                'status' => AuditRunStatus::FAILED,
                'finished_at' => now(),
                'error' => 'The audit job failed or timed out before publishing a terminal outcome.',
            ]);
    }
}
