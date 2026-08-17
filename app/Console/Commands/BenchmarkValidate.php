<?php

namespace App\Console\Commands;

use App\Services\Pentest\BenchmarkCatalog;
use App\Services\Pentest\BenchmarkManifest;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

final class BenchmarkValidate extends Command
{
    protected $signature = 'benchmark:validate {target-id? : Target da validare, default tutti}';
    protected $description = 'Valida i manifest statici lailaps.benchmark senza modificarli';

    public function handle(BenchmarkCatalog $catalog): int
    {
        $target = $this->argument('target-id');
        $targets = $target !== null
            ? [(string) $target]
            : array_map('basename', glob(base_path('agent/pentest-agent/benchmarks/targets').'/*', GLOB_ONLYDIR) ?: []);
        $valid = 0;
        foreach ($targets as $targetId) {
            if ($targetId === '.' || $targetId === '..') continue;
            foreach ($catalog->forTarget($targetId) as $manifest) {
                $this->validateManifest($manifest);
                $valid++;
                $this->line("OK {$manifest->id()} (".count($manifest->cases()).' casi)');
            }
        }
        $this->info("Manifest validati: {$valid}");
        return self::SUCCESS;
    }

    private function validateManifest(BenchmarkManifest $manifest): void
    {
        $sourceRoot = base_path('targets/'.$manifest->targetId());
        $expectedCommit = $manifest->data['source']['commit'] ?? null;
        if (is_string($expectedCommit) && is_dir($sourceRoot.'/.git')) {
            $process = new Process(['git', '-C', $sourceRoot, 'rev-parse', 'HEAD']);
            $process->run();
            if (! $process->isSuccessful() || ! hash_equals(strtolower($expectedCommit), strtolower(trim($process->getOutput())))) {
                throw new \InvalidArgumentException("Commit sorgente non valido per {$manifest->id()}");
            }
        }
        $counts = [true => 0, false => 0];
        foreach ($manifest->cases() as $case) {
            $counts[(bool) $case['expected_vulnerable']]++;
            foreach ($case['anchors'] as $anchor) {
                if ((int) ($anchor['start_line'] ?? 0) < 1 || (int) ($anchor['end_line'] ?? 0) < (int) ($anchor['start_line'] ?? 0)) {
                    throw new \InvalidArgumentException("Anchor non valido per {$case['id']}");
                }
                $source = base_path('targets/'.$manifest->targetId().'/'.ltrim((string) ($anchor['file'] ?? ''), '/\\'));
                if (is_file($source) && isset($case['source_sha256']) && ! hash_equals(strtolower((string) $case['source_sha256']), strtolower((string) hash_file('sha256', $source)))) {
                    throw new \InvalidArgumentException("Hash sorgente non valido per {$case['id']}");
                }
            }
        }
        if ($manifest->targetId() === 'owasp-benchmark-java' && $counts[true] !== $counts[false]) {
            throw new \InvalidArgumentException("Manifest {$manifest->id()} non bilanciato.");
        }
    }
}
