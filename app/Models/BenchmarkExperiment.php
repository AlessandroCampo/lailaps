<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class BenchmarkExperiment extends Model
{
    use HasUlids;

    protected $fillable = ['user_id', 'name', 'status', 'definition', 'harness_revision', 'reproducible', 'started_at', 'finished_at'];

    protected function casts(): array
    {
        return ['definition' => 'array', 'reproducible' => 'boolean', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AuditRun::class);
    }
}
