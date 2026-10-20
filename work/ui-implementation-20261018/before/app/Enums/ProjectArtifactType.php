<?php

namespace App\Enums;

enum ProjectArtifactType: string
{
    case STATIC_ANALYSIS = 'static_analysis';
    case CODE_INDEX = 'code_index';
    case ENVIRONMENT = 'environemnt';
    case RECON = 'recon';
    case DEPENDENCY_ANALYSIS = 'dependency_analysis';
}
