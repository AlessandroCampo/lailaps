<?php

namespace App\Services\Target\Support;

use InvalidArgumentException;

/**
 * DSN del database del target, scritto in forma di URL perché sia una sola
 * opzione di CLI: mysql://user:password@127.0.0.1:3306/app.
 *
 * Qui si valida solo la sintassi e si traduce in ciò che vuole PDO: se il
 * database poi risponde davvero lo stabilisce DatabaseProbe.
 */
final class DatabaseDsn
{
    /** schema URL => [driver PDO, porta di default] */
    private const DRIVERS = [
        'mysql' => ['mysql', 3306],
        'mariadb' => ['mysql', 3306],
        'pgsql' => ['pgsql', 5432],
        'postgres' => ['pgsql', 5432],
        'postgresql' => ['pgsql', 5432],
    ];

    public function __construct(
        public string $driver,
        public string $host,
        public int $port,
        public string $database,
        public string $username = '',
        public string $password = '',
    ) {}

    public static function parse(string $value): self
    {
        $value = trim($value);

        if (preg_match('#^sqlite:#i', $value) === 1) {
            return self::sqlite($value);
        }

        $parts = parse_url($value);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException(
                "DSN non valido: attesa una URL tipo mysql://utente:password@127.0.0.1:3306/database, ricevuto '{$value}'"
            );
        }

        $scheme = strtolower($parts['scheme']);

        if (! isset(self::DRIVERS[$scheme])) {
            throw new InvalidArgumentException(
                "Driver DSN non supportato: {$scheme} (supportati: ".implode(', ', array_keys(self::DRIVERS)).', sqlite)'
            );
        }

        [$driver, $defaultPort] = self::DRIVERS[$scheme];

        $database = ltrim($parts['path'] ?? '', '/');

        if ($database === '') {
            throw new InvalidArgumentException("DSN senza nome del database: {$value}");
        }

        return new self(
            driver: $driver,
            host: $parts['host'],
            port: $parts['port'] ?? $defaultPort,
            database: $database,
            // le credenziali viaggiano urlencoded: una password con '@' o ':'
            // spaccherebbe l'URL se non lo fosse
            username: rawurldecode($parts['user'] ?? ''),
            password: rawurldecode($parts['pass'] ?? ''),
        );
    }

    /** DSN nel formato che si passa al costruttore di PDO. */
    public function pdoDsn(): string
    {
        return match ($this->driver) {
            'sqlite' => "sqlite:{$this->database}",
            default => "{$this->driver}:host={$this->host};port={$this->port};dbname={$this->database}",
        };
    }

    /** Rappresentazione stampabile: la password non esce mai da qui. */
    public function label(): string
    {
        if ($this->driver === 'sqlite') {
            return "sqlite:{$this->database}";
        }

        $credentials = $this->username !== '' ? "{$this->username}@" : '';

        return "{$this->driver}://{$credentials}{$this->host}:{$this->port}/{$this->database}";
    }

    public function isSqlite(): bool
    {
        return $this->driver === 'sqlite';
    }

    /**
     * sqlite non ha host né credenziali: quello che segue lo schema è un path,
     * con la complicazione che su Windows inizia con la lettera di unità.
     */
    private static function sqlite(string $value): self
    {
        $path = (string) preg_replace('#^sqlite:(//)?#i', '', $value);

        // "sqlite:///C:/app/database.sqlite" → parse ingenuo darebbe "/C:/app/..."
        if (preg_match('#^/[A-Za-z]:#', $path) === 1) {
            $path = ltrim($path, '/');
        }

        if (trim($path) === '') {
            throw new InvalidArgumentException("DSN sqlite senza path: {$value}");
        }

        return new self(driver: 'sqlite', host: '', port: 0, database: $path);
    }
}
