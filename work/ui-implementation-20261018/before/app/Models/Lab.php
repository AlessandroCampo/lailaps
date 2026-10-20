<?php

namespace App\Models;

use App\Enums\LabStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lab extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => LabStatus::class,
            'environment' => 'encrypted:array',
            'verified_at' => 'datetime',
        ];
    }

    public function storageDirectory(): string
    {
        return $this->project_id.'/labs/'.$this->id;
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(ProjectSource::class, 'project_source_id')->withTrashed();
    }

    public function doctorSession(): BelongsTo
    {
        return $this->belongsTo(AgentSession::class, 'doctor_session_id');
    }
}
