<?php

namespace App\Services\Target\Probes;

use App\Services\Target\Contracts\TargetProbe;
use App\Services\Target\DTO\ProbeResultDTO;
use App\Services\Target\DTO\RemoteTargetSpecDTO;
use PDO;
use Throwable;

/**
 * Connessione di prova al database del target.
 *
 * Un'app che risponde 200 sulla home può avere il DB irraggiungibile e fallire
 * su ogni rotta che conta: senza questo controllo l'agente lo scoprirebbe da
 * solo, molto più tardi e a pagamento.
 */
final class DatabaseProbe implements TargetProbe
{
    public function name(): string
    {
        return 'database';
    }

    public function appliesTo(RemoteTargetSpecDTO $spec): bool
    {
        return $spec->database !== null;
    }

    public function check(RemoteTargetSpecDTO $spec): ProbeResultDTO
    {
        $dsn = $spec->database;
        $startedAt = microtime(true);

        if ($dsn === null) {
            return ProbeResultDTO::pass($this->name(), 'nessun DSN dichiarato');
        }

        // PDO crea il file sqlite se manca: senza questo controllo una path
        // sbagliata passerebbe il check connettendosi a un database vuoto
        if ($dsn->isSqlite() && ! is_file($dsn->database)) {
            return ProbeResultDTO::fail($this->name(), "file sqlite inesistente: {$dsn->database}");
        }

        try {
            $pdo = new PDO($dsn->pdoDsn(), $dsn->username, $dsn->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => (int) config('pentest.target.db_timeout'),
            ]);

            $pdo->query('SELECT 1');
        } catch (Throwable $e) {
            return ProbeResultDTO::fail($this->name(), "{$dsn->label()}: {$e->getMessage()}", $this->elapsed($startedAt));
        }

        return ProbeResultDTO::pass($this->name(), "{$dsn->label()} raggiungibile", $this->elapsed($startedAt));
    }

    private function elapsed(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
