<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $counts = [
            'confirmed' => (int) $this->confirmed_count,
            'suspected' => (int) $this->suspected_count,
            'inconclusive' => (int) $this->inconclusive_count,
            'blocked' => (int) $this->blocked_count,
        ];

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'created_at' => $this->created_at?->toISOString(),
            'started_at' => $this->started_at?->toISOString(),
            'finished_at' => $this->finished_at?->toISOString(),
            'duration_seconds' => $this->started_at === null ? null : max(0, (int) $this->started_at->diffInSeconds($this->finished_at ?? now())),
            'source' => [
                'id' => $this->source->id,
                'source_type' => $this->source->source_type->value,
                'uploaded_at' => $this->source->uploaded_at?->toISOString(),
            ],
            'has_report' => (bool) $this->has_report,
            'coverage_complete' => match ($this->coverage_complete) {
                'true', true => true,
                'false', false => false,
                default => null,
            },
            'findings_count' => $this->has_report ? array_sum($counts) : null,
            'lead_counts' => $this->has_report ? $counts : null,
        ];
    }
}
