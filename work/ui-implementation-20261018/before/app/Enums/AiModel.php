<?php

namespace App\Enums;

enum AiModel : string
{
    case GLM_FLASH = 'z-ai/glm-5.3-flash';
    case MIMO_PRO = 'xiaomi/mimo-v2.6-pro';

    public function fixedProviders()
    {
        return match ($this) {
            self::GLM_FLASH => [
                
            ],
            default => []
        };
    }

    

}