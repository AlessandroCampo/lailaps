<?php

namespace App\Services\Audit;

use App\Enums\AuditRunStatus;
use App\Jobs\ExecuteAuditRun;
use App\Models\AuditRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class AuditRunQueueFailureReconciler
{
    public function reconcile(AuditRun $run): AuditRun
    {
        if ($run->status !== AuditRunStatus::Queued) {
            return $run;
        }

        $failure = $this->failedJobs($run)->first();
        if ($failure === null) {
            return $run;
        }

        $run->update([
            'status' => AuditRunStatus::Failed,
            'error' => $this->failureMessage((string) $failure->exception),
            'exit_code' => null,
            'finished_at' => Carbon::parse((string) $failure->failed_at),
        ]);

        return $run->refresh();
    }

    public function forget(AuditRun $run): void
    {
        $ids = $this->failedJobs($run)->pluck('id')->all();
        if ($ids !== []) {
            DB::table('failed_jobs')->whereIn('id', $ids)->delete();
        }
    }

    /** @return Collection<int, object> */
    private function failedJobs(AuditRun $run): Collection
    {
        return DB::table('failed_jobs')
            ->where('payload', 'like', '%'.$run->id.'%')
            ->latest('failed_at')
            ->get()
            ->filter(function (object $job): bool {
                $payload = json_decode((string) $job->payload, true);

                return is_array($payload)
                    && ($payload['data']['commandName'] ?? null) === ExecuteAuditRun::class;
            })
            ->values();
    }

    private function failureMessage(string $exception): string
    {
        $line = trim((string) (preg_split('/\R/', $exception)[0] ?? ''));

        return $line !== '' ? 'Queue job fallito: '.$line : 'Queue job fallito.';
    }
}
