<?php

namespace App\Enums;

enum AuditRunLeadStatus: string
{
    case PENDING = 'pending';
    case RUNNING = 'running';
    case CONFIRMED = 'confirmed';
    case SUSPECTED = 'suspected';
    case BLOCKED = 'blocked';
    case INCONCLUSIVE = 'inconclusive';
    case REJECTED = 'rejected';
    case FAILED = 'failed';
}
