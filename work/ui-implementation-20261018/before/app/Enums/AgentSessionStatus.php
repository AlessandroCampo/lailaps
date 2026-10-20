<?php

namespace App\Enums;

enum AgentSessionStatus: string
{
    case RUNNING = 'running';
    case READY = 'ready';
    case FAILED = 'failed';
}
