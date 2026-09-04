<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class BenchmarkStageArtifact extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'accepted' => 'boolean',
            'is_golden' => 'boolean',
            'is_canonical' => 'boolean',
            'payload' => 'array',
            'usage' => 'array',
            'metrics' => 'array',
        ];
    }

    public function parentArtifact(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_artifact_id');
    }

    public function childArtifacts(): HasMany
    {
        return $this->hasMany(self::class, 'parent_artifact_id');
    }
}
