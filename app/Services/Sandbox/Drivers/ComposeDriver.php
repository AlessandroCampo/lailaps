<?php

namespace App\Services\Sandbox\Drivers;

use App\Services\Sandbox\Contracts\SandboxDriver;
use App\Services\Sandbox\DockerClient;
use App\Services\Sandbox\DTO\SandboxSpecDTO;
use App\Services\Sandbox\Support\SandboxLabels;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;

class ComposeDriver implements SandboxDriver
{
    private const FILE_NAMES = ['docker-compose', 'compose'];

    private const EXTENSIONS = ['yml', 'yaml'];

    private const KEPT_CAPS = ['CHOWN', 'DAC_OVERRIDE', 'FOWNER', 'SETGID', 'SETUID', 'NET_BIND_SERVICE', 'KILL'];

    private const UP_TIMEOUT = 180;

    private const DOWN_TIMEOUT = 120;

    public function __construct(protected DockerClient $docker) {}

    public function name(): string
    {
        return 'compose';
    }

    public function supports(SandboxSpecDTO $spec): bool
    {
        return $spec->composeFile !== null || $this->findComposeFile($spec->projectPath) !== null;
    }

    public function boot(SandboxSpecDTO $spec): array
    {
        $composeFile = $spec->composeFile ?? $this->findComposeFile($spec->projectPath)
            ?? throw new RuntimeException('Nessun compose file trovato nel progetto');

        $projectName = $spec->projectName();
        $files = [$composeFile, $this->writeOverride($composeFile, $spec)];

        $process = $this->compose(dirname($composeFile), $projectName, $files, ['up', '-d', '--wait'], self::UP_TIMEOUT);
        $process->run();

        if (! $process->isSuccessful()) {
            // il rollback è del service: qui ci limitiamo a un errore leggibile
            throw new RuntimeException('docker compose up fallito: '.trim($process->getErrorOutput()));
        }

        return $this->docker->listContainersByLabel('com.docker.compose.project', $projectName);
    }

    public function destroy(string $auditId): void
    {
        $projectName = "audit-{$auditId}";

        // Compose scrive config_files e working_dir sulle label: ricostruiamo il comando da lì.
        $containers = $this->docker->listContainersByLabel('com.docker.compose.project', $projectName);
        $labels = (reset($containers) ?: [])['Labels'] ?? [];

        $files = array_values(array_filter(
            explode(',', $labels['com.docker.compose.project.config_files'] ?? ''),
            'is_file'
        ));

        $cwd = $labels['com.docker.compose.project.working_dir'] ?? null;

        $this->compose($cwd, $projectName, $files, ['down', '-v', '--remove-orphans', '--timeout', '10'], self::DOWN_TIMEOUT)
            ->run(); // best-effort

        // se i container erano già spariti, compose non li conosce: rifiniamo per label
        foreach ($this->docker->listContainersByLabel(SandboxLabels::AUDIT_ID, $auditId) as $container) {
            rescue(fn () => $this->docker->removeContainer($container['Id']), report: false);
        }

        rescue(fn () => $this->docker->pruneVolumesByLabel('com.docker.compose.project', $projectName), report: false);

        File::deleteDirectory($this->overrideDirectory($auditId));
    }

    // ------------------------------------------------------------------ interni

    /**
     * @param  array<int, string>  $files
     * @param  array<int, string>  $args
     */
    private function compose(?string $cwd, string $projectName, array $files, array $args, int $timeout): Process
    {
        $cmd = ['docker', 'compose', '-p', $projectName];

        foreach ($files as $file) {
            $cmd[] = '-f';
            $cmd[] = $file;
        }

        $process = new Process([...$cmd, ...$args], is_dir((string) $cwd) ? $cwd : null);
        $process->setTimeout($timeout);

        return $process;
    }

    private function findComposeFile(string $projectPath): ?string
    {
        foreach (self::FILE_NAMES as $name) {
            foreach (self::EXTENSIONS as $ext) {
                $candidate = "{$projectPath}/{$name}.{$ext}";

                if (is_file($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * Override generato: applica i limiti di sicurezza e, soprattutto, le label
     * comuni, senza le quali teardown e reaping non riconoscerebbero lo stack.
     */
    private function writeOverride(string $composeFile, SandboxSpecDTO $spec): string
    {
        $original = Yaml::parseFile($composeFile);
        $limits = config('sandbox.limits');

        $override = ['services' => []];

        foreach (($original['services'] ?? []) as $service => $definition) {
            $serviceOverride = [
                'cap_drop' => ['ALL'],
                'cap_add' => self::KEPT_CAPS,
                'security_opt' => ['no-new-privileges:true'],
                'pids_limit' => $limits['pids'],
                'mem_limit' => $limits['memory'],
                'restart' => 'no',
                'labels' => SandboxLabels::for($spec, $this->name(), service: (string) $service),
            ];

            // Un container_name esplicito ignora il project name di Compose ed è
            // quindi globale sul daemon. Lo rendiamo specifico della run senza
            // alterare il nome DNS del servizio usato dagli altri container.
            if (is_array($definition) && array_key_exists('container_name', $definition)) {
                $serviceOverride['container_name'] = "{$spec->projectName()}-{$service}";
            }

            // Anche le porte host dichiarate dal progetto sono globali. !override
            // sostituisce la lista (invece di concatenarla) e l'assenza della
            // published port fa scegliere a Docker una porta host libera.
            if (is_array($definition) && ! empty($definition['ports'])) {
                $serviceOverride['ports'] = new TaggedValue(
                    'override',
                    array_map($this->isolatedPort(...), $definition['ports']),
                );
            }

            $override['services'][$service] = $serviceOverride;
        }

        $path = $this->overrideDirectory($spec->auditId).'/docker-compose.override.yml';

        File::ensureDirectoryExists(dirname($path));
        File::put($path, Yaml::dump($override, 4));

        return $path;
    }

    /**
     * @param  int|string|array<string, mixed>  $port
     * @return string|array<string, mixed>
     */
    private function isolatedPort(int|string|array $port): string|array
    {
        if (is_array($port)) {
            unset($port['published']);
            $port['host_ip'] = '127.0.0.1';

            return $port;
        }

        $port = (string) $port;
        $protocol = '';
        $slash = strrpos($port, '/');

        if ($slash !== false) {
            $protocol = substr($port, $slash);
            $port = substr($port, 0, $slash);
        }

        return '127.0.0.1::'.$this->containerPort($port).$protocol;
    }

    /** Estrae il lato container senza confondere IPv6 e default ${VAR:-value}. */
    private function containerPort(string $port): string
    {
        $lastSeparator = null;
        $squareDepth = 0;
        $variableDepth = 0;
        $length = strlen($port);

        for ($index = 0; $index < $length; $index++) {
            $character = $port[$index];

            if ($character === '[') {
                $squareDepth++;
            } elseif ($character === ']') {
                $squareDepth = max(0, $squareDepth - 1);
            } elseif ($character === '$' && ($port[$index + 1] ?? null) === '{') {
                $variableDepth++;
                $index++;
            } elseif ($character === '}' && $variableDepth > 0) {
                $variableDepth--;
            } elseif ($character === ':' && $squareDepth === 0 && $variableDepth === 0) {
                $lastSeparator = $index;
            }
        }

        return $lastSeparator === null ? $port : substr($port, $lastSeparator + 1);
    }

    private function overrideDirectory(string $auditId): string
    {
        return storage_path("framework/lailaps-sandbox/{$auditId}");
    }
}
