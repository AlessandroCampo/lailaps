<?php

namespace App\Models;

use App\Enums\ProjectArtifactStatus;
use App\Enums\ProjectArtifactType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectArtifact extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => ProjectArtifactStatus::class,
            'type' => ProjectArtifactType::class,
            'metadata' => 'array',
            'payload' => 'array',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(ProjectSource::class, 'project_source_id')->withTrashed();
    }

    public function path(): string
    {
        return $this->source->project->artifactsPath().'/'.$this->id.'/'.$this->type->value;
    }

        public function reconAreas(): HasMany
    {
        return $this->hasMany(ReconArea::class, 'recon_artifact_id');
    }
}
