<?php

namespace App\Services\Sandbox\Audit;

use App\Services\Sandbox\DockerClient;
use App\Services\Sandbox\DTO\SandboxDTO;
use App\Services\Target\Contracts\TestTarget;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use InvalidArgumentException;
use RuntimeException;

/** Esegue bootstrap e readiness prima che l'agente possa consumare token. */
final class SandboxPreparationService
{
    public function __construct(private readonly DockerClient $docker) {}

    public function prepare(SandboxDTO $sandbox, AuditProfile $profile): void
    {
        if (! $profile->hasPreparation()) {
            return;
        }

        $client = new Client([
            'base_uri' => rtrim($sandbox->url(), '/').'/',
            'allow_redirects' => false,
            'cookies' => new CookieJar,
            'http_errors' => false,
            'timeout' => 10,
        ]);
        $variables = [];

        foreach ($profile->setup as $index => $step) {
            $this->runStep($client, $sandbox, $step, $variables, "setup[{$index}]");
        }

        foreach ($profile->readiness as $index => $step) {
            $this->runStep($client, $sandbox, $step, $variables, "readiness[{$index}]");
        }

        // Contratto esplicito del progetto: una route che esegue una query o
        // un comando nel container. Un fallimento blocca la run prima
        // dell'avvio dell'agente.
        foreach ($profile->database as $index => $step) {
            $this->runStep($client, $sandbox, $step, $variables, "database[{$index}]");
        }
    }

    /** @param array<int, array<string, mixed>> $probes */
    public function verifyFixtureProbes(TestTarget $target, array $probes, ?SandboxDTO $sandbox = null): void
    {
        if ($probes === []) {
            return;
        }

        $client = new Client([
            'base_uri' => rtrim($target->url(), '/').'/',
            'allow_redirects' => false,
            'cookies' => new CookieJar,
            'http_errors' => false,
            'timeout' => 10,
        ]);
        $variables = [];

        foreach ($probes as $index => $probe) {
            $caseId = is_string($probe['case_id'] ?? null) ? $probe['case_id'] : 'unknown-case';
            $probeId = is_string($probe['id'] ?? null) ? $probe['id'] : (string) $index;
            $this->runStep($client, $sandbox, $probe, $variables, "fixture_probes[{$caseId}:{$probeId}]");
        }
    }

    /** Re-run only non-mutating HTTP readiness probes after the agent finishes. */
    public function verifyReadiness(SandboxDTO $sandbox, AuditProfile $profile): void
    {
        $client = new Client([
            'base_uri' => rtrim($sandbox->url(), '/').'/',
            'allow_redirects' => false,
            'cookies' => new CookieJar,
            'http_errors' => false,
            'timeout' => 10,
        ]);
        $variables = [];
        foreach ($profile->readiness as $index => $step) {
            $type = (string) ($step['type'] ?? 'http');
            $method = strtoupper((string) ($step['method'] ?? 'GET'));
            if ($type !== 'http' || ! in_array($method, ['GET', 'HEAD'], true) || str_contains(json_encode($step, JSON_THROW_ON_ERROR), '{{')) {
                continue;
            }
            $this->runStep($client, $sandbox, $step, $variables, "post_readiness[{$index}]");
        }
    }

    /**
     * @param  array<string, mixed>  $step
     * @param  array<string, string>  $variables
     */
    private function runStep(Client $client, ?SandboxDTO $sandbox, array $step, array &$variables, string $label): void
    {
        $retrySeconds = (int) ($step['retry_seconds'] ?? 0);
        if ($retrySeconds < 0 || $retrySeconds > 120) {
            throw new InvalidArgumentException("{$label}: retry_seconds deve essere compreso tra 0 e 120.");
        }
        $deadline = time() + $retrySeconds;

        do {
            try {
                $this->runStepOnce($client, $sandbox, $step, $variables, $label);

                return;
            } catch (RuntimeException $error) {
                if (time() >= $deadline) {
                    throw $error;
                }

                usleep(1_000_000);
            }
        } while (true);
    }

    /**
     * @param  array<string, mixed>  $step
     * @param  array<string, string>  $variables
     */
    private function runStepOnce(Client $client, ?SandboxDTO $sandbox, array $step, array &$variables, string $label): void
    {
        $type = $step['type'] ?? 'http';
        if ($type === 'http') {
            $this->runHttpStep($client, $step, $variables, $label);

            return;
        }

        if ($type === 'target_exec') {
            if ($sandbox === null) {
                throw new InvalidArgumentException("{$label}: target_exec richiede una sandbox locale.");
            }
            $argv = $step['argv'] ?? null;
            if (! is_array($argv) || $argv === [] || ! array_is_list($argv) || array_filter($argv, fn (mixed $value): bool => ! is_string($value)) !== []) {
                throw new InvalidArgumentException("{$label}: target_exec richiede argv come lista non vuota di stringhe.");
            }

            $exitCode = $this->docker->execContainer($sandbox->containerId, $argv);
            $expected = (int) ($step['expected_exit_code'] ?? 0);
            if ($exitCode !== $expected) {
                throw new RuntimeException("{$label}: target_exec terminato con exit code {$exitCode}, atteso {$expected}.");
            }

            return;
        }

        throw new InvalidArgumentException("{$label}: type non supportato '{$type}'. Usa http o target_exec.");
    }

    /**
     * @param  array<string, mixed>  $step
     * @param  array<string, string>  $variables
     */
    private function runHttpStep(Client $client, array $step, array &$variables, string $label): void
    {
        $method = strtoupper((string) ($step['method'] ?? 'GET'));
        $path = (string) ($step['path'] ?? '');
        if (! preg_match('/^[A-Z]+$/', $method) || $path === '' || preg_match('#^[a-z][a-z0-9+.-]*://#i', $path)) {
            throw new InvalidArgumentException("{$label}: method/path HTTP non validi. path deve essere relativo.");
        }

        $options = [];
        if (array_key_exists('headers', $step)) {
            if (! is_array($step['headers'])) {
                throw new InvalidArgumentException("{$label}: headers deve essere una mappa.");
            }
            $headers = $this->expand($step['headers'], $variables, $label);
            $options['headers'] = $headers;
        }
        if (array_key_exists('form', $step)) {
            if (! is_array($step['form'])) {
                throw new InvalidArgumentException("{$label}: form deve essere una mappa.");
            }
            $options['form_params'] = $this->expand($step['form'], $variables, $label);
        }

        $response = $client->request($method, ltrim($path, '/'), $options);
        $status = $response->getStatusCode();
        $expected = $step['expected_status'] ?? null;
        if ($expected === null) {
            $validStatus = $status >= 200 && $status < 300;
        } elseif (is_int($expected) || ctype_digit((string) $expected)) {
            $validStatus = $status === (int) $expected;
        } elseif (is_array($expected)) {
            $validStatus = in_array($status, array_map('intval', $expected), true);
        } else {
            throw new InvalidArgumentException("{$label}: expected_status deve essere un intero o una lista di interi.");
        }
        if (! $validStatus) {
            $excerpt = trim(preg_replace('/\s+/', ' ', strip_tags((string) $response->getBody())) ?? '');
            $suffix = $excerpt !== '' ? ' Risposta: '.mb_substr($excerpt, 0, 300) : '';

            throw new RuntimeException("{$label}: HTTP {$status}, status atteso non soddisfatto.{$suffix}");
        }

        $location = (string) $response->getHeaderLine('Location');
        foreach ((array) ($step['reject_redirects'] ?? []) as $forbidden) {
            if (is_string($forbidden) && $forbidden !== '' && str_contains($location, $forbidden)) {
                throw new RuntimeException("{$label}: redirect non pronto verso '{$location}'.");
            }
        }

        $body = (string) $response->getBody();
        foreach ((array) ($step['body_contains'] ?? []) as $needle) {
            if (! is_string($needle) || ! str_contains($body, $needle)) {
                $excerpt = trim(preg_replace('/\s+/', ' ', strip_tags($body)) ?? '');
                $suffix = $excerpt !== '' ? ' Risposta: '.mb_substr($excerpt, 0, 300) : '';

                throw new RuntimeException("{$label}: risposta priva del marker richiesto '{$needle}'.{$suffix}");
            }
        }

        foreach ((array) ($step['body_not_contains'] ?? []) as $needle) {
            if (! is_string($needle) || str_contains($body, $needle)) {
                $excerpt = trim(preg_replace('/\s+/', ' ', strip_tags($body)) ?? '');
                $suffix = $excerpt !== '' ? ' Risposta: '.mb_substr($excerpt, 0, 300) : '';

                throw new RuntimeException("{$label}: risposta contiene il marker vietato '{$needle}'.{$suffix}");
            }
        }

        $capture = $step['capture'] ?? [];
        if (! is_array($capture)) {
            throw new InvalidArgumentException("{$label}: capture deve essere una mappa nome => regex.");
        }
        foreach ($capture as $name => $regex) {
            if (! is_string($name) || ! is_string($regex) || @preg_match($regex, '') === false) {
                throw new InvalidArgumentException("{$label}: capture contiene una regex non valida.");
            }
            if (preg_match($regex, $body, $matches) !== 1 || ! isset($matches[1])) {
                throw new RuntimeException("{$label}: impossibile catturare la variabile '{$name}'.");
            }
            $variables[$name] = $matches[1];
        }
    }

    /**
     * @param  array<array-key, mixed>  $form
     * @param  array<string, string>  $variables
     * @return array<string, string>
     */
    private function expand(array $form, array $variables, string $label): array
    {
        $expanded = [];
        foreach ($form as $key => $value) {
            if (! is_string($key) || ! is_scalar($value)) {
                throw new InvalidArgumentException("{$label}: form accetta solo coppie stringa => valore scalare.");
            }
            $expanded[$key] = preg_replace_callback('/{{\s*([A-Za-z_][A-Za-z0-9_]*)\s*}}/', function (array $match) use ($variables, $label): string {
                $name = $match[1];
                if (! array_key_exists($name, $variables)) {
                    throw new RuntimeException("{$label}: variabile '{$name}' non catturata.");
                }

                return $variables[$name];
            }, (string) $value) ?? (string) $value;
        }

        return $expanded;
    }
}
