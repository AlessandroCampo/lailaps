<?php

namespace App\Actions;

use App\DTOs\AuditRun\LeadOutcome;
use App\Enums\AuditRunLeadStatus;
use App\Models\AuditRun;
use App\Models\AuditRunLead;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CreateAuditRunLeadAction
{
    public function handle(AuditRun $run, LeadOutcome $lead): AuditRunLead
    {
        return DB::transaction(function () use ($run, $lead) {

            $area = $lead->areaKey === null ? null : $run->areas()
                ->where('pass_key', $lead->passId ?? 'pass-001')
                ->where('area_key', $lead->areaKey)
                ->first();
            if ($lead->areaKey !== null && $area === null) {
                throw new RuntimeException('No run area matches lead '.$lead->id.'.');
            }

            return $run->leads()->updateOrCreate(
                [
                    'lead_key' => $lead->id,
                ],
                [
                    'audit_run_area_id' => $area?->id,
                    'status' => AuditRunLeadStatus::from($lead->status),
                    'title' => $lead->title,
                    'severity' => $lead->severity,
                    'payload' => $lead->toArray(),
                ]
            );
        });
    }
}
