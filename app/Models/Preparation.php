<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Preparation extends Model
{
    use HasUlids;

    protected $fillable = [
        'project_id', 'source_revision_id', 'based_on_id', 'status', 'questions',
        'answers', 'configuration', 'summary', 'actors', 'capabilities', 'doctor_usage',
        'active_seconds', 'runtime_path', 'artifacts_path', 'fingerprint',
        'failure_reason', 'cancellation_requested', 'started_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'questions' => 'array',
            'answers' => 'encrypted:array',
            'configuration' => 'array',
            'actors' => 'array',
            'capabilities' => 'array',
            'doctor_usage' => 'array',
            'cancellation_requested' => 'boolean',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(SourceRevision::class, 'source_revision_id');
    }

    public function basedOn(): BelongsTo
    {
        return $this->belongsTo(self::class, 'based_on_id');
    }
}
