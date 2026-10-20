<?php

namespace App\Console\Commands;

use App\Models\AgentSession;
use App\Models\Project;
use App\Services\DoctorService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[Signature('agent:test {project? : Project ID; defaults to the latest project} {--session= : Resume an existing Doctor session} {--artifacts= : Check existing artifacts without model calls} {--keep : Keep a successful target stack running}')]
#[Description('Prepare a candidate environment and verify application readiness')]
class AiTest extends Command
{
    public function handle(DoctorService $doctor): int
    {
        try {
            if ($this->option('session') && $this->option('artifacts')) {
                throw new RuntimeException('Choose either --session or --artifacts.');
            }
            if ($this->option('session')) {
                $session = AgentSession::where('agent', 'doctor')->findOrFail($this->option('session'));
            } else {
                $project = $this->argument('project')
                    ? Project::findOrFail($this->argument('project'))
                    : Project::latest('id')->firstOrFail();
                $source = $project->latestSource ?? throw new RuntimeException('The project has no source.');
                $paths = $this->option('artifacts') ? $this->existingPaths($project, $this->option('artifacts')) : null;
                $session = $doctor->createSession($source, $paths);
            }
            $doctor->run(
                $session,
                fn (string $type, string $text) => $this->output->write($text, false, OutputInterface::OUTPUT_RAW),
                artifactsOnly: (bool) $this->option('artifacts'),
                keep: (bool) $this->option('keep'),
                decorated: $this->output->isDecorated(),
            );

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }

    private function existingPaths(Project $project, string $artifacts): array
    {
        $artifacts = realpath($artifacts);
        $root = realpath(Storage::disk('projects')->path($project->storageDirectory().'/doctor'));
        if ($artifacts === false || $root === false || ! str_starts_with($artifacts, $root.DIRECTORY_SEPARATOR) || ! is_dir(dirname($artifacts).'/workspace')) {
            throw new RuntimeException('Artifacts must belong to an existing Doctor workspace for this project.');
        }

        return ['artifacts' => $artifacts, 'workspace' => dirname($artifacts).'/workspace'];
    }
}
