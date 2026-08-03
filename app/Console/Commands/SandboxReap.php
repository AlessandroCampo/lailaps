<?php

namespace App\Console\Commands;

use App\Services\Sandbox\SandboxService;
use Illuminate\Console\Command;

class SandboxReap extends Command
{
    protected $signature = 'sandbox:reap';

    protected $description = 'Smonta le sandbox con TTL scaduto, qualunque driver le abbia avviate';

    public function handle(SandboxService $sandboxService): int
    {
        $reaped = $sandboxService->reapExpired();

        if ($reaped === []) {
            $this->info('Nessuna sandbox scaduta.');

            return self::SUCCESS;
        }

        $this->info(count($reaped).' sandbox smontate: '.implode(', ', $reaped));

        return self::SUCCESS;
    }
}
