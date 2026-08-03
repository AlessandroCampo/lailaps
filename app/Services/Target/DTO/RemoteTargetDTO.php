<?php

namespace App\Services\Target\DTO;

use App\Services\Target\Contracts\TestTarget;

/**
 * Un ambiente di proprietà dell'utente, raggiunto via URL.
 *
 * Non ha container, network né scadenza: il suo ciclo di vita non è nostro.
 * Quello che ha da raccontare sono gli health check superati per arrivare qui.
 */
final class RemoteTargetDTO implements TestTarget
{
    /** @param  array<int, ProbeResultDTO>  $checks */
    public function __construct(
        public string $targetUrl,
        public array $checks = [],
    ) {}

    public function url(): string
    {
        return $this->targetUrl;
    }

    public function isRunning(): bool
    {
        foreach ($this->checks as $check) {
            if ($check->hasFailed()) {
                return false;
            }
        }

        return true;
    }

    public function summary(): array
    {
        $lines = ['modalità' => 'remoto — ambiente dell\'utente, nessuna sandbox da smontare'];

        if ($this->checks === []) {
            $lines['check'] = 'saltati';
        }

        foreach ($this->checks as $check) {
            $lines[$check->name] = $check->line();
        }

        return $lines;
    }
}
