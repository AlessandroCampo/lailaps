<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;

final class BenchmarkCompare extends Command
{
    protected $signature = 'benchmark:compare {--runs=* : Directory run o file *-outcome.json}';

    protected $description = 'Confronta score benchmark esistenti senza rilanciare target o agenti';

    public function handle(): int
    {
        $runs = (array) $this->option('runs');
        if (count($runs) < 2) {
            throw new InvalidArgumentException('Passare almeno due opzioni --runs.');
        }
        $rows = [];
        foreach ($runs as $run) {
            $paths = is_dir((string) $run) ? (glob(rtrim((string) $run, '/\\').'/*-outcome.json') ?: []) : [(string) $run];
            $path = count($paths) === 1 ? $paths[0] : '';
            if (! is_file($path)) {
                throw new InvalidArgumentException("Score inesistente: {$path}");
            }
            $outcome = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $score = is_array($outcome['benchmark'] ?? null) ? $outcome['benchmark'] : [];
            $rows[] = [
                $score['suite_id'] ?? $score['benchmark_id'] ?? basename(dirname($path)),
                $score['artifact_state'] ?? 'unknown',
                $score['environment_state'] ?? 'unknown',
                data_get($score, 'score.normalized') === null ? 'n/a' : number_format((float) data_get($score, 'score.normalized'), 1),
                number_format((float) data_get($score, 'reach.file_reached.recall', 0), 3),
                number_format((float) data_get($score, 'reach.anchor_reached.recall', 0), 3),
                number_format((float) data_get($score, 'suspected.recall', data_get($score, 'detection.recall', 0)), 3),
                number_format((float) data_get($score, 'static_validation.recall', 0), 3),
                number_format((float) data_get($score, 'dynamically_confirmed.recall', data_get($score, 'confirmation.recall', 0)), 3),
                $score['cost']['total_tokens'] ?? 0,
                number_format((float) data_get($score, 'cost.economic_points', 0), 0),
                data_get($score, 'cost.provider_cost_usd') === null ? 'n/a' : '$'.number_format((float) data_get($score, 'cost.provider_cost_usd'), 4),
                (int) data_get($score, 'cost.model_requests', 0),
                (int) data_get($score, 'cost.tool_calls', 0),
                (int) data_get($score, 'cost.http_requests', 0),
                data_get($score, 'disposition.accuracy') === null ? 'n/a' : number_format((float) data_get($score, 'disposition.accuracy'), 3),
            ];
        }
        $this->table(['Suite', 'Artifact', 'Environment', 'Score', 'File R', 'Anchor R', 'Suspect R', 'Static R', 'Dynamic R', 'Tokens', 'Points', 'USD', 'Model', 'Tool', 'HTTP', 'Disposition'], $rows);

        return self::SUCCESS;
    }
}
