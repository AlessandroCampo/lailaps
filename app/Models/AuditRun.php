<?php

namespace App\Models;

use App\Enums\AuditRunStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int|null $user_id
 * @property string $audit_id
 * @property string $type
 * @property AuditRunStatus $status
 * @property array<string, mixed> $parameters
 * @property string|null $run_path
 * @property int|null $exit_code
 * @property string|null $error
 * @property bool $cancellation_requested
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AuditRun extends Model
{
    use HasUlids;

    protected $fillable = [
        'user_id', 'benchmark_experiment_id', 'audit_id', 'type', 'repetition', 'status', 'parameters', 'run_path',
        'harness_revision', 'target_commit', 'reproducible',
        'exit_code', 'error', 'cancellation_requested', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'status' => AuditRunStatus::class,
            'cancellation_requested' => 'boolean',
            'reproducible' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function experiment(): BelongsTo
    {
        return $this->belongsTo(BenchmarkExperiment::class, 'benchmark_experiment_id');
    }

    public function models(): HasMany
    {
        return $this->hasMany(AuditRunModel::class);
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(BenchmarkEvaluation::class);
    }

    public function roleEvaluations(): HasMany
    {
        return $this->hasMany(BenchmarkRoleEvaluation::class);
    }

    public function isTerminal(): bool
    {
        return $this->status->terminal();
    }
}
