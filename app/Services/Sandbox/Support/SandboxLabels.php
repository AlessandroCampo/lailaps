<?php

namespace App\Services\Sandbox\Support;

use App\Services\Sandbox\DTO\SandboxSpecDTO;

/**
 * Schema di label comune a tutti i driver.
 *
 * È il solo canale attraverso cui il service ritrova e smonta una sandbox:
 * finché un driver le applica, teardown e reaping funzionano senza sapere
 * come il container sia stato avviato.
 */
final class SandboxLabels
{
    public const OWNER = 'lailaps';

    public const MANAGED_BY = 'sandbox.managed_by';

    public const AUDIT_ID = 'sandbox.audit_id';

    public const DRIVER = 'sandbox.driver';

    public const SERVICE = 'sandbox.service';

    public const EXPIRES_AT = 'sandbox.expires_at';

    /** @return array<string, string> */
    public static function for(SandboxSpecDTO $spec, string $driver, ?string $service = null): array
    {
        $labels = [
            self::MANAGED_BY => self::OWNER,
            self::AUDIT_ID => $spec->auditId,
            self::DRIVER => $driver,
            self::EXPIRES_AT => (string) $spec->expiresAt()->getTimestamp(),
        ];

        if ($service !== null) {
            $labels[self::SERVICE] = $service;
        }

        return $labels;
    }

    /** @param  array<string, string>  $labels */
    public static function read(array $labels, string $key, ?string $default = null): ?string
    {
        return $labels[$key] ?? $default;
    }
}
