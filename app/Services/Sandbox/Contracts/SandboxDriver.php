<?php

namespace App\Services\Sandbox\Contracts;

use App\Services\Sandbox\DTO\SandboxSpecDTO;

interface SandboxDriver
{
    /** Identificativo persistito su SandboxDTO::$driver e sulle label. */
    public function name(): string;

    public function supports(SandboxSpecDTO $spec): bool;

    /**
     * Avvia lo stack e restituisce i container, nel formato di GET /containers/json.
     *
     * Non deve fare pulizia in caso di errore: il rollback è del SandboxService.
     *
     * @return array<int, array<string, mixed>>
     */
    public function boot(SandboxSpecDTO $spec): array;

    /** Idempotente e best-effort: usato sia per teardown che per rollback. */
    public function destroy(string $auditId): void;
}
