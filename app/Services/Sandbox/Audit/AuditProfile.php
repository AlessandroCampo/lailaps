<?php

namespace App\Services\Sandbox\Audit;

use InvalidArgumentException;
use Symfony\Component\Yaml\Yaml;

/**
 * Contratto di preparazione di un target avviato nella sandbox.
 *
 * Il profilo e' deliberatamente nel progetto auditato: descrive come rendere
 * ripetibile il suo ambiente di test, non e' un'istruzione per il modello.
 */
final readonly class AuditProfile
{
    /**
     * @param  array<int, array<string, mixed>>  $setup
     * @param  array<int, array<string, mixed>>  $readiness
     * @param  array<int, array<string, mixed>>  $database
     */
    public function __construct(
        public ?string $service,
        public ?string $basePath,
        public ?string $healthPath,
        public array $setup,
        public array $readiness,
        public array $database,
    ) {}

    public static function fromProject(string $projectPath): self
    {
        $path = rtrim($projectPath, '/\\').DIRECTORY_SEPARATOR.'lailaps.audit.yaml';

        return self::fromPath($path, missingAllowed: true);
    }

    public static function fromPath(string $path, bool $missingAllowed = false): self
    {
        $resolved = realpath($path);

        if ($resolved === false || ! is_file($resolved)) {
            if (! $missingAllowed) {
                throw new InvalidArgumentException("Profilo audit inesistente: {$path}");
            }

            return new self(null, null, null, [], [], []);
        }
        $path = $resolved;

        try {
            $raw = Yaml::parseFile($path);
        } catch (\Throwable $e) {
            throw new InvalidArgumentException("Profilo audit non valido ({$path}): {$e->getMessage()}", previous: $e);
        }

        if (! is_array($raw)) {
            throw new InvalidArgumentException("Profilo audit non valido ({$path}): la root deve essere una mappa YAML.");
        }

        $target = $raw['target'] ?? [];
        if (! is_array($target)) {
            throw new InvalidArgumentException("Profilo audit non valido ({$path}): target deve essere una mappa.");
        }

        $service = $target['service'] ?? null;
        if ($service !== null && (! is_string($service) || trim($service) === '')) {
            throw new InvalidArgumentException("Profilo audit non valido ({$path}): target.service deve essere una stringa non vuota.");
        }

        $basePath = self::relativePath($target['base_path'] ?? null, 'target.base_path', $path);
        $healthPath = self::relativePath($target['health_path'] ?? null, 'target.health_path', $path);

        return new self(
            $service !== null ? trim($service) : null,
            $basePath,
            $healthPath,
            self::steps($raw['setup'] ?? [], 'setup', $path),
            self::steps($raw['readiness'] ?? [], 'readiness', $path),
            self::steps($raw['database'] ?? [], 'database', $path),
        );
    }

    private static function relativePath(mixed $value, string $name, string $path): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) || trim($value) === '' || preg_match('#^[a-z][a-z0-9+.-]*://#i', $value)) {
            throw new InvalidArgumentException("Profilo audit non valido ({$path}): {$name} deve essere un path relativo non vuoto.");
        }

        return '/'.trim($value, '/');
    }

    public function hasPreparation(): bool
    {
        return $this->setup !== [] || $this->readiness !== [] || $this->database !== [];
    }

    /** @return array<int, array<string, mixed>> */
    private static function steps(mixed $value, string $name, string $path): array
    {
        if ($value === []) {
            return [];
        }

        // Un singolo step scritto come mappa e' comodo per il caso piu' comune.
        if (is_array($value) && ! array_is_list($value)) {
            $value = [$value];
        }

        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException("Profilo audit non valido ({$path}): {$name} deve essere una lista di step.");
        }

        foreach ($value as $index => $step) {
            if (! is_array($step)) {
                throw new InvalidArgumentException("Profilo audit non valido ({$path}): {$name}[{$index}] deve essere una mappa.");
            }
        }

        /** @var array<int, array<string, mixed>> $value */
        return $value;
    }
}
