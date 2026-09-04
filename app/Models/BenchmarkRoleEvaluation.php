<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class BenchmarkRoleEvaluation extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'score' => 'float', 'cache_hit_rate' => 'float',
            'provider_cost_usd' => 'float', 'estimated_cost_usd' => 'float',
            'true_positives_per_1k_tokens' => 'float',
            'objective' => 'array', 'metrics' => 'array', 'usage' => 'array', 'pricing' => 'array',
        ];
    }

    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(BenchmarkEvaluation::class, 'benchmark_evaluation_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AuditRun::class, 'audit_run_id');
    }

    public function cases(): HasMany
    {
        return $this->hasMany(BenchmarkRoleCaseResult::class);
    }
}
