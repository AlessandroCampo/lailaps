<?php

namespace App\Enums;

enum ProjectSourceStatus: string
{
    case PENDING = 'pending';
    case IMPORTING = 'importing';
    case READY = 'ready';
    case FAILED = 'failed';
}
