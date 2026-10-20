<?php

namespace App\Models;

use App\Enums\AuditRunLeadStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditRunLead extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => AuditRunLeadStatus::class,
            'payload' => 'array',
        ];
    }

    public function auditRun(): BelongsTo
    {
        return $this->belongsTo(AuditRun::class);
    }

    public function auditRunArea(): BelongsTo
    {
        return $this->belongsTo(AuditRunArea::class);
    }
}
