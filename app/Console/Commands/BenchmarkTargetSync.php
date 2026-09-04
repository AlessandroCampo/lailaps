<?php

namespace App\Console\Commands;

use App\Services\Pentest\BenchmarkCatalog;
use App\Services\Pentest\BenchmarkTargetDescriptor;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

final class BenchmarkTargetSync extends Command
{
    protected $signature = 'benchmark:target:sync {target-id?} {--all : Sincronizza tutti i descriptor}';

    protected $description = 'Clona e verifica gli snapshot frozen dichiarati nel registry benchmark';

    public function handle(BenchmarkCatalog $catalog): int
    {
        $targetId = $this->argument('target-id');
        if (! $this->option('all') && ! is_string($targetId)) {
            $this->error('Passa target-id oppure --all.');

            return self::INVALID;
        }
        $descriptors = $this->option('all')
            ? $catalog->descriptors()
            : array_filter([$catalog->descriptor((string) $targetId)]);
        foreach ($descriptors as $descriptor) {
            $this->sync($descriptor);
        }

        return self::SUCCESS;
    }

    private function sync(BenchmarkTargetDescriptor $descriptor): void
    {
        $path = base_path('targets/'.$descriptor->id());
        if (! file_exists($path)) {
            $this->runProcess(['git', 'clone', '--no-checkout', '--filter=blob:none', $descriptor->repository(), $path], base_path());
            $this->runProcess(['git', 'checkout', '--detach', $descriptor->commit()], $path);
        } else {
            if (! is_dir($path.'/.git')) {
                throw new \RuntimeException("Target esistente non gestito da Git: {$path}");
            }
            $status = trim($this->output([
                'git', 'status', '--porcelain', '--untracked-files=no',
            ], $path));
            if ($status !== '') {
                throw new \RuntimeException("Sorgente versionato modificato, sync rifiutato: {$descriptor->id()}");
            }
            $remote = rtrim(trim($this->output(['git', 'remote', 'get-url', 'origin'], $path)), '/');
            if (! hash_equals(strtolower($descriptor->repository()), strtolower($remote))) {
                throw new \RuntimeException("Remote inatteso per {$descriptor->id()}: {$remote}");
            }
        }
        $head = strtolower(trim($this->output(['git', 'rev-parse', 'HEAD'], $path)));
        if (! hash_equals($descriptor->commit(), $head)) {
            throw new \RuntimeException("Commit inatteso per {$descriptor->id()}: {$head}");
        }
        if (($tree = $descriptor->tree()) !== null) {
            $actual = strtolower(trim($this->output(['git', 'rev-parse', 'HEAD^{tree}'], $path)));
            if (! hash_equals($tree, $actual)) {
                throw new \RuntimeException("Tree hash inatteso per {$descriptor->id()}: {$actual}");
            }
        }
        $this->info("OK {$descriptor->id()} @ {$head}");
    }

    /** @param list<string> $command */
    private function runProcess(array $command, string $cwd): void
    {
        $process = new Process($command, $cwd, timeout: 600);
        $process->mustRun(fn (string $type, string $buffer) => $this->output->write($buffer));
    }

    /** @param list<string> $command */
    private function output(array $command, string $cwd): string
    {
        $process = new Process($command, $cwd, timeout: 30);
        $process->mustRun();

        return $process->getOutput();
    }
}
