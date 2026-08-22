<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class BenchmarkEvaluation extends Model
{
    use HasUlids;

    protected $fillable = ['audit_run_id', 'benchmark_id', 'target_id', 'category', 'status', 'evaluator_version', 'oracle_version', 'artifact_checksum', 'artifact_state', 'run_state', 'adjudication_state', 'environment_state', 'reach', 'detection', 'static_validation', 'confirmation', 'cost', 'payload', 'pending_adjudications', 'global_score', 'adjudicated_score', 'file_recall', 'anchor_recall', 'detection_recall', 'static_validation_recall', 'confirmation_recall', 'confirmation_f1', 'total_tokens', 'provider_cost_usd', 'evaluated_at'];

    protected function casts(): array
    {
        return [
            'reach' => 'array', 'detection' => 'array', 'static_validation' => 'array',
            'confirmation' => 'array', 'cost' => 'array', 'payload' => 'array',
            'global_score' => 'float', 'adjudicated_score' => 'float',
            'file_recall' => 'float', 'anchor_recall' => 'float',
            'detection_recall' => 'float', 'static_validation_recall' => 'float',
            'confirmation_recall' => 'float', 'confirmation_f1' => 'float',
            'provider_cost_usd' => 'float', 'evaluated_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AuditRun::class, 'audit_run_id');
    }

    public function cases(): HasMany
    {
        return $this->hasMany(BenchmarkCaseResult::class);
    }

    public function adjudications(): HasMany
    {
        return $this->hasMany(BenchmarkAdjudication::class);
    }
}
