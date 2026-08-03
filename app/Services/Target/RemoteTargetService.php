<?php

namespace App\Services\Target;

use App\Services\Target\Contracts\TargetProbe;
use App\Services\Target\DTO\ProbeResultDTO;
use App\Services\Target\DTO\RemoteTargetDTO;
use App\Services\Target\DTO\RemoteTargetSpecDTO;
use RuntimeException;

/**
 * Il gemello di SandboxService per gli ambienti che non avviamo noi: dove
 * quello builda e pubblica una porta, questo si limita a bussare e a
 * certificare che dall'altra parte ci sia qualcosa di testabile.
 *
 * Restituisce lo stesso tipo di oggetto — un TestTarget — così che a valle
 * nessuno debba sapere da quale dei due rami arriva il bersaglio.
 */
class RemoteTargetService
{
    /** @param  array<int, TargetProbe>  $probes */
    public function __construct(protected array $probes) {}

    public function connect(RemoteTargetSpecDTO $spec): RemoteTargetDTO
    {
        $results = [];

        // si eseguono tutti i controlli applicabili anche dopo il primo fallito:
        // sapere in un colpo solo che l'app è giù *e* il DB pure vale l'attesa
        foreach ($this->probes as $probe) {
            if ($probe->appliesTo($spec)) {
                $results[] = $probe->check($spec);
            }
        }

        $failed = array_filter($results, fn (ProbeResultDTO $result): bool => $result->hasFailed());

        if ($failed !== []) {
            throw new RuntimeException(
                "Il target {$spec->url} non è pronto:".PHP_EOL
                .implode(PHP_EOL, array_map(
                    fn (ProbeResultDTO $result): string => "  - {$result->name}: {$result->detail}",
                    $failed,
                ))
            );
        }

        return new RemoteTargetDTO($spec->url, $results);
    }
}
