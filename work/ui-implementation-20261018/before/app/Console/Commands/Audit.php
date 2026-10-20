<?php

namespace App\Console\Commands;

use App\Actions\CreateAuditRunAction;
use App\Actions\RunAuditAction;
use App\Models\ProjectSource;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('audit:run {source_id}')]
#[Description('Smoke-test an audit against a prepared project source')]
class Audit extends Command
{
    public function handle(CreateAuditRunAction $createAuditRun, RunAuditAction $runAudit): int
    {
        $source = ProjectSource::findOrFail($this->argument('source_id'));
        try {
            $run = $createAuditRun->handle($source, RunAuditAction::DEFAULT_CONFIGS);
            $runAudit->handle($run, function (string $type, string $text): void {
                $this->output->write($text);
            });

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
