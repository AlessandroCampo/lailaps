<?php

namespace App\Enums;

enum AuditRunAreaStatus: string
{
    case PENDING = 'pending';
    case RUNNING = 'running';
    case COMPLETED = 'completed';
    case PARTIAL = 'partial';
    case FAILED = 'failed';
}
