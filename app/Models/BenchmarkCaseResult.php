<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class BenchmarkCaseResult extends Model
{
    protected $fillable = ['benchmark_evaluation_id', 'case_id', 'expected_vulnerable', 'stage', 'stage_score', 'score_contribution', 'reach', 'milestones', 'detection', 'suspected', 'static_validation', 'confirmation', 'dynamically_confirmed', 'match_mode', 'matched_findings', 'payload'];

    protected function casts(): array
    {
        return [
            'expected_vulnerable' => 'boolean', 'stage_score' => 'float',
            'score_contribution' => 'float', 'reach' => 'array', 'milestones' => 'array',
            'matched_findings' => 'array', 'payload' => 'array',
        ];
    }

    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(BenchmarkEvaluation::class);
    }
}
