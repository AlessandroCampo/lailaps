<?php

namespace App\Models;

use App\Enums\AuditRunAreaStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AuditRunArea extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => AuditRunAreaStatus::class,
            'payload' => 'array',
        ];
    }

    public function auditRun(): BelongsTo
    {
        return $this->belongsTo(AuditRun::class);
    }

    public function reconArea(): BelongsTo
    {
        return $this->belongsTo(ReconArea::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(AuditRunLead::class);
    }
}
