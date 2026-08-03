<?php

namespace App\Services\Target\Contracts;

/**
 * Ciò che pentest:run deve sapere del bersaglio, qualunque sia la sua natura:
 * una sandbox effimera avviata da noi oppure un ambiente già in piedi, di cui
 * l'utente ci passa solo l'URL (staging, tunnel ngrok, docker compose locale).
 *
 * Il contratto è volutamente povero: l'URL è tutto ciò che serve all'agente,
 * il resto è quel minimo che serve alla CLI per raccontare cosa sta attaccando.
 * Chi avvia il bersaglio sa anche come smontarlo: qui dentro non c'è teardown.
 */
interface TestTarget
{
    /** Base URL su cui l'agente farà le richieste. */
    public function url(): string;

    /** False quando il bersaglio non è (più) utilizzabile: sandbox smontata, health fallito. */
    public function isRunning(): bool;

    /** @return array<string, string> etichetta => valore, una riga per voce nel report della CLI */
    public function summary(): array;
}
