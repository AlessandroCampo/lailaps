<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class BenchmarkRoleCaseResult extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'expected_positive' => 'boolean', 'reached' => 'boolean',
            'score' => 'float', 'payload' => 'array',
        ];
    }

    public function roleEvaluation(): BelongsTo
    {
        return $this->belongsTo(BenchmarkRoleEvaluation::class, 'benchmark_role_evaluation_id');
    }
}
