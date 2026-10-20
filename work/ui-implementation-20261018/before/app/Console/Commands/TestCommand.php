<?php

namespace App\Console\Commands;

use App\Models\AuditRun;
use App\Services\OutcomeService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:test')]
#[Description('Command description')]
class TestCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(OutcomeService $outcomes)
    {
        $run = AuditRun::latest()->firstOrFail();
        $outcome = $run->getOutcomeSummary();
        if ($outcome === null) {
            $this->error('The run has no saved outcome.');

            return self::FAILURE;
        }

        dd($outcomes->getEnrichedPayloadWithSnippets($run, $outcome));
    }
}
