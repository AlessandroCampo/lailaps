<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SourceRevision extends Model
{
    use HasUlids;

    protected $fillable = [
        'project_id', 'provider', 'status', 'content_hash', 'source_path',
        'application_subdirectory', 'original_filename', 'github_repository_id',
        'github_full_name', 'github_commit_sha', 'github_ref', 'provider_metadata',
        'error', 'imported_at',
    ];

    protected function casts(): array
    {
        return ['provider_metadata' => 'array', 'imported_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function preparations(): HasMany
    {
        return $this->hasMany(Preparation::class);
    }

    public function audits(): HasMany
    {
        return $this->hasMany(AuditRun::class);
    }

    public function sourceRoot(): string
    {
        if ($this->status !== 'ready' || ! is_string($this->source_path) || ! is_dir($this->source_path)) {
            throw new \RuntimeException('La revisione sorgente non e disponibile.');
        }

        return $this->application_subdirectory
            ? $this->source_path.'/'.trim($this->application_subdirectory, '/')
            : $this->source_path;
    }
}
