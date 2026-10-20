<?php

namespace App\Models;

use App\Enums\AgentSessionStatus;
use App\Enums\LabStatus;
use App\Enums\ProjectArtifactStatus;
use App\Enums\ProjectSourceStatus;
use App\Enums\ProjectSourceType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProjectSource extends Model
{
    use HasUlids, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => ProjectSourceStatus::class,
            'source_type' => ProjectSourceType::class,
            'metadata' => 'array',
            'uploaded_at' => 'datetime',
        ];
    }

    public function storageDirectory(): string
    {
        return $this->project_id.'/sources/'.$this->id;
    }

    public function sourcePath(): string
    {
        return $this->storageDirectory().'/files';
    }

    public function archivePath(): string
    {
        return $this->storageDirectory().'/source.zip';
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function lab(): HasOne
    {
        return $this->hasOne(Lab::class);
    }

    public function readyLab(): HasOne
    {
        return $this->hasOne(Lab::class)->latestOfMany()->where('status', LabStatus::READY);
    }

    public function artifacts(): HasMany
    {
        return $this->hasMany(ProjectArtifact::class);
    }

    public function usableArtifacts(): HasMany
    {
        return $this->hasMany(ProjectArtifact::class)->whereIn('status', [ProjectArtifactStatus::PARTIAL, ProjectArtifactStatus::READY]);
    }

    public function readyDoctorSession(): HasOne
    {
        return $this->hasOne(AgentSession::class)->where('status', AgentSessionStatus::READY)->where('agent', 'doctor')->orderByDesc('created_at')->orderByDesc('id');
    }
}
