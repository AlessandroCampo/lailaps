<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AuditRunModel extends Model
{
    protected $fillable = ['audit_run_id', 'role', 'requested_model', 'effective_model', 'provider', 'reasoning_effort', 'metadata'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AuditRun::class, 'audit_run_id');
    }
}
