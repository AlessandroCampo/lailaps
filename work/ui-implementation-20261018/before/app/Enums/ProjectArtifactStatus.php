<?php

namespace App\Enums;

enum ProjectArtifactStatus: string
{
    case PENDING = 'pending';
    case RUNNING = 'running';
    case READY = 'ready';
    case PARTIAL = 'partial';
    case FAILED = 'failed';
}
