<?php

namespace App\Jobs;

use App\Enums\LabStatus;
use App\Models\Lab;
use App\Services\DoctorService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class LabSetup implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 7200;

    public bool $failOnTimeout = true;

    public function __construct(public int $labId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('lab-setup:'.$this->labId))->dontRelease()->expireAfter($this->timeout + 60)];
    }

    public function handle(DoctorService $doctor): void
    {
        $lab = Lab::findOrFail($this->labId);
        if ($lab->status === LabStatus::READY) {
            return;
        }
        $lab->update(['status' => LabStatus::RUNNING, 'error' => null, 'verified_at' => null]);

        try {
            $session = $doctor->createSession($lab->source);
            $lab->update(['doctor_session_id' => $session->id]);
            $doctor->run($session, fn (string $type, string $text) => fwrite(STDOUT, $text));
            $session->refresh();
            $lab->update([
                'status' => LabStatus::READY,
                'environment' => $session->environment,
                'verified_at' => now(),
                'error' => null,
            ]);
        } catch (Throwable $error) {
            $lab->update(['status' => LabStatus::FAILED, 'error' => $error->getMessage()]);
            throw $error;
        }
    }

    public function failed(?Throwable $error): void
    {
        Lab::whereKey($this->labId)->where('status', '!=', LabStatus::READY)->update([
            'status' => LabStatus::FAILED,
            'error' => $error?->getMessage() ?? 'Lab setup failed.',
        ]);
    }
}
