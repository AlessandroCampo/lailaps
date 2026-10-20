<?php

namespace App\Enums;

enum LabStatus: string
{
    case PENDING = 'pending';
    case RUNNING = 'running';
    case FAILED = 'failed';
    case READY = 'ready';
    case UNAVAILABLE = 'unavailable';
}
