<?php

namespace App\Services\Sandbox\Enums;

enum SandboxStatus: string
{
    case RUNNING = 'running';
    case DEAD = 'dead';
}
