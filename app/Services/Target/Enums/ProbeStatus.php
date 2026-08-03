<?php

namespace App\Services\Target\Enums;

enum ProbeStatus: string
{
    case PASSED = 'passed';

    /** Il target risponde ma c'è qualcosa che l'agente pagherà: si prosegue, avvisando. */
    case WARNING = 'warning';

    case FAILED = 'failed';

    /** Marcatore usato nel report della CLI. */
    public function mark(): string
    {
        return match ($this) {
            self::PASSED => 'ok',
            self::WARNING => 'attenzione',
            self::FAILED => 'ko',
        };
    }
}
