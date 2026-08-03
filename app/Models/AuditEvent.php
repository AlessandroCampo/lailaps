<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $audit_run_id
 * @property int $sequence
 * @property string $type
 * @property string|null $category
 * @property string|null $role
 * @property array<string, mixed> $payload
 * @property string|null $artifact_ref
 * @property Carbon|null $occurred_at
 */
class AuditEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'audit_run_id', 'sequence', 'type', 'category', 'role', 'payload',
        'artifact_ref', 'occurred_at',
    ];

    protected function casts(): array
    {
        return ['payload' => 'array', 'occurred_at' => 'datetime'];
    }

    /** @return BelongsTo<AuditRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AuditRun::class, 'audit_run_id');
    }
}
