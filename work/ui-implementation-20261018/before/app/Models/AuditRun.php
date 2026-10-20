<?php

namespace App\Models;

use App\DTOs\AuditRun\RunOutcome;
use App\Enums\AuditRunStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class AuditRun extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'configs' => 'array',
            'outcome' => 'array',
            'status' => AuditRunStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function path(): string
    {
        return $this->project_id.'/runs/'.$this->id;
    }

    public function getOutcome(): ?array
    {
        $storage = Storage::disk('projects');
        $path = $this->path().'/output/outcome.json';
        if (! $storage->exists($path)) {
            return null;
        }

        return json_decode($storage->get($path), true, 512, JSON_THROW_ON_ERROR);
    }

    public function getOutcomeSummary(): ?RunOutcome
    {
        $outcome = $this->getOutcome();

        return $outcome !== null ? RunOutcome::fromArray($outcome) : null;
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(
            ProjectSource::class,
            'project_source_id',
        )->withTrashed();
    }

    public function artifacts(): BelongsToMany
    {
        return $this->belongsToMany(ProjectArtifact::class);
    }

    public function areas(): HasMany
    {
        return $this->hasMany(AuditRunArea::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(AuditRunLead::class);
    }

    public function lab(): BelongsTo
    {
        return $this->belongsTo(Lab::class);
    }
}
