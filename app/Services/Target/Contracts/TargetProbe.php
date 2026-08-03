<?php

namespace App\Services\Target\Contracts;

use App\Services\Target\DTO\ProbeResultDTO;
use App\Services\Target\DTO\RemoteTargetSpecDTO;

/**
 * Un singolo controllo di pre-volo su un bersaglio remoto.
 *
 * Esiste per non far scoprire all'agente — dopo minuti e token — che il target
 * era spento, dietro un interstitial o senza database.
 */
interface TargetProbe
{
    /** Etichetta breve mostrata nel report della CLI. */
    public function name(): string;

    /** False quando lo spec non fornisce ciò che serve (es. nessun DSN da testare). */
    public function appliesTo(RemoteTargetSpecDTO $spec): bool;

    /** Non deve lanciare: un controllo andato male è un ProbeResultDTO fallito. */
    public function check(RemoteTargetSpecDTO $spec): ProbeResultDTO;
}
