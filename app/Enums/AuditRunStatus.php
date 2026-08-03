<?php

namespace App\Enums;

enum AuditRunStatus: string
{
    case Queued = 'queued';
    case Preparing = 'preparing';
    case Running = 'running';
    case Finalizing = 'finalizing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function terminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Cancelled], true);
    }
}
