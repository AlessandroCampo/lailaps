<?php

namespace App\Console\Commands;

use App\Models\BenchmarkRoleEvaluation;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class BenchmarkRoleCompare extends Command
{
    protected $signature = 'benchmark:role:compare
        {role : recon, reader, reviewer, confirmer, worker oppure judge}
        {--experiment= : Limita a un esperimento}
        {--target= : Limita al target}
        {--category= : Limita alla categoria}
        {--model=* : Limita a uno o piu modelli}
        {--include-observational : Include run dove il ruolo non era la variabile controllata}';

    protected $description = 'Confronta qualita e costo dei modelli per un singolo ruolo AgentBench';

    public function handle(): int
    {
        $role = strtolower(trim((string) $this->argument('role')));
        if (! in_array($role, ['recon', 'reader', 'reviewer', 'confirmer', 'worker', 'judge'], true)) {
            $this->error('Ruolo non valido.');

            return self::INVALID;
        }
        $query = BenchmarkRoleEvaluation::query()
            ->where('role', $role)
            ->whereIn('status', ['scored', 'partial'])
            ->with(['evaluation', 'run.experiment']);
        if (! $this->option('include-observational')) {
            $query->where('attribution', 'controlled_variable');
        }
        if ($this->option('experiment')) {
            $query->whereHas('run', fn (Builder $query) => $query->where('benchmark_experiment_id', $this->option('experiment')));
        }
        if ($this->option('target')) {
            $query->whereHas('evaluation', fn (Builder $query) => $query->where('target_id', $this->option('target')));
        }
        if ($this->option('category')) {
            $query->whereHas('evaluation', fn (Builder $query) => $query->where('category', $this->option('category')));
        }
        $models = array_values(array_filter((array) $this->option('model')));
        if ($models !== []) {
            $query->whereIn('model', $models);
        }
        $evaluations = $query->get();
        if ($evaluations->isEmpty()) {
            $this->warn('Nessuna scorecard confrontabile. Usa --include-observational per includere le run legacy/non controllate.');

            return self::SUCCESS;
        }
        $rows = $evaluations
            ->groupBy(fn (BenchmarkRoleEvaluation $item): string => $item->comparison_signature.'|'.($item->model ?? 'unknown'))
            ->map(function (Collection $items): array {
                /** @var BenchmarkRoleEvaluation $first */
                $first = $items->first();
                $score = $this->summary($items->pluck('score'));
                $tokens = $this->summary($items->pluck('total_tokens'));
                $cost = $this->summary($items->pluck('provider_cost_usd'));
                $cache = $this->summary($items->pluck('cache_hit_rate'));

                return [
                    substr($first->comparison_signature, 0, 8),
                    $first->evaluation->target_id,
                    $first->evaluation->category,
                    $first->model ?? 'unknown',
                    $items->count(),
                    $this->number($score['mean'], 2).' +/- '.$this->number($score['stddev'], 2),
                    (string) $items->sum('true_positives'),
                    $this->number($items->avg('true_positives'), 2),
                    $this->number($tokens['mean'], 0),
                    $this->number($cache['mean'] === null ? null : 100 * $cache['mean'], 1).'%',
                    $cost['mean'] === null ? 'n/a' : '$'.$this->number($cost['mean'], 4),
                    $this->number($items->avg('true_positives_per_1k_tokens'), 4),
                ];
            })->values()->all();

        $this->table(
            ['Comparable', 'Target', 'Category', 'Model', 'N', 'Score avg +/- sd', 'TP total', 'TP avg', 'Tokens avg', 'Cache hit', 'USD avg', 'TP/1k tok'],
            $rows,
        );

        return self::SUCCESS;
    }

    /** @param Collection<int, mixed> $values @return array{mean: float|null, stddev: float|null} */
    private function summary(Collection $values): array
    {
        $numbers = $values->filter(fn (mixed $value): bool => $value !== null && is_numeric($value))->map(fn (mixed $value): float => (float) $value)->values();
        if ($numbers->isEmpty()) {
            return ['mean' => null, 'stddev' => null];
        }
        $mean = (float) $numbers->avg();
        $variance = (float) $numbers->map(fn (float $value): float => ($value - $mean) ** 2)->avg();

        return ['mean' => $mean, 'stddev' => sqrt($variance)];
    }

    private function number(?float $value, int $decimals): string
    {
        return $value === null ? 'n/a' : number_format($value, $decimals, '.', '');
    }
}
