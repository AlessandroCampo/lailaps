<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;

final class BenchmarkCompare extends Command
{
    protected $signature = 'benchmark:compare {--runs=* : Directory run o file benchmark.json}';

    protected $description = 'Confronta score benchmark esistenti senza rilanciare target o agenti';

    public function handle(): int
    {
        $runs = (array) $this->option('runs');
        if (count($runs) < 2) {
            throw new InvalidArgumentException('Passare almeno due opzioni --runs.');
        }
        $rows = [];
        foreach ($runs as $run) {
            $path = is_dir((string) $run) ? rtrim((string) $run, '/\\').'/benchmark.json' : (string) $run;
            if (! is_file($path)) {
                throw new InvalidArgumentException("Score inesistente: {$path}");
            }
            $score = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $rows[] = [
                $score['suite_id'] ?? $score['benchmark_id'] ?? basename(dirname($path)),
                $score['detection']['tp'] ?? 0,
                $score['detection']['fp'] ?? 0,
                number_format((float) ($score['detection']['recall'] ?? 0), 3),
                number_format((float) ($score['detection']['precision'] ?? 0), 3),
                $score['cost']['total_tokens'] ?? 0,
            ];
        }
        $this->table(['Suite', 'TP', 'FP', 'Recall', 'Precision', 'Tokens'], $rows);

        return self::SUCCESS;
    }
}
