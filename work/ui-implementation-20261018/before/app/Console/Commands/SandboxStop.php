<?php

namespace App\Console\Commands;

use App\Models\AgentSession;
use App\Services\SandboxService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('sandbox:stop {session : Agent session ID}')]
#[Description('Remove a retained verification stack and its Docker volumes')]
class SandboxStop extends Command
{
    public function handle(SandboxService $sandbox): int
    {
        $session = AgentSession::findOrFail($this->argument('session'));
        if ($session->runtime_config !== null) {
            $sandbox->destroy($sandbox->decodeConfig($session->runtime_config), $session->paths['artifacts'], $session->id);
            $session->update(['runtime_config' => null]);
        }
        $this->info('Verification stack removed.');

        return self::SUCCESS;
    }
}
