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
 * @property string|null $artifact_path
 * @property int|null $exit_code
 * @property string|null $error
 * @property bool $cancellation_requested
 * @property bool $legacy
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AuditRun extends Model
{
    use HasUlids;

    protected $fillable = [
        'user_id', 'audit_id', 'type', 'status', 'parameters', 'artifact_path',
        'exit_code', 'error', 'cancellation_requested', 'legacy', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'status' => AuditRunStatus::class,
            'cancellation_requested' => 'boolean',
            'legacy' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<AuditEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(AuditEvent::class);
    }

    public function isTerminal(): bool
    {
        return $this->status->terminal();
    }
}
