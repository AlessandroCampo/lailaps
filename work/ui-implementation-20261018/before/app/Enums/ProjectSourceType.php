<?php

namespace App\Enums;

enum ProjectSourceType : string
{
    case GITHUB = 'github';
    case ZIP = 'zip';
}
