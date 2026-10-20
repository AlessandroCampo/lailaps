<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Override;

class ReconArea extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    public function auditRunAreas(): HasMany
    {
        return $this->hasMany(AuditRunArea::class);
    }

    #[Override]
    protected function casts()
    {
        return [
            'payload' => 'array',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(ProjectSource::class, 'project_source_id');
    }

    public function reconArtifact(): BelongsTo
    {
        return $this->belongsTo(ProjectArtifact::class, 'recon_artifact_id');
    }
}
