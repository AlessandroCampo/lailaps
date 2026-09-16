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

    private const UP_TIMEOUT = 600;

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
        $this->validateEffectiveConfig($composeFile, $spec, $projectName);
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

        // Symfony filtra l'ambiente ereditato attraverso $_SERVER su Windows.
        // Le variabili del runtime benchmark vengono invece aggiunte con putenv(),
        // quindi passiamo esplicitamente l'ambiente corrente al subprocess Compose.
        $environment = getenv();
        $process = new Process(
            [...$cmd, ...$args],
            is_dir((string) $cwd) ? $cwd : null,
            is_array($environment) ? $environment : null,
        );
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
                'cpus' => (string) (((int) $limits['nano_cpus']) / 1_000_000_000),
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

    private function validateEffectiveConfig(string $composeFile, SandboxSpecDTO $spec, string $projectName): void
    {
        $process = $this->compose(dirname($composeFile), $projectName, [$composeFile], ['config', '--format', 'json'], 30);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('Configurazione Compose non valida: '.trim($process->getErrorOutput()));
        }
        $config = json_decode($process->getOutput(), true);
        if (! is_array($config) || ! is_array($config['services'] ?? null)) {
            throw new RuntimeException('docker compose config non ha restituito una configurazione valida.');
        }
        foreach (['networks', 'volumes'] as $section) {
            foreach ((array) ($config[$section] ?? []) as $name => $definition) {
                if (($definition['external'] ?? false) === true) {
                    throw new RuntimeException("Risorsa Compose esterna vietata: {$section}.{$name}");
                }
            }
        }
        $roots = array_values(array_unique(array_filter([
            realpath($spec->projectPath),
            realpath(dirname($composeFile)),
        ])));
        foreach ($config['services'] as $name => $service) {
            if (! is_array($service)) {
                continue;
            }
            foreach (['privileged', 'use_api_socket'] as $option) {
                if (($service[$option] ?? false) === true) {
                    throw new RuntimeException("Compose {$name}: {$option} vietato.");
                }
            }
            foreach (['network_mode', 'pid', 'ipc'] as $option) {
                if (($service[$option] ?? null) === 'host') {
                    throw new RuntimeException("Compose {$name}: {$option}=host vietato.");
                }
            }
            if (! empty($service['devices']) || ! empty($service['volumes_from'])) {
                throw new RuntimeException("Compose {$name}: device o volumes_from host vietati.");
            }
            foreach ((array) ($service['volumes'] ?? []) as $volume) {
                if (! is_array($volume) || ($volume['type'] ?? null) !== 'bind') {
                    continue;
                }
                $source = (string) ($volume['source'] ?? '');
                if ($source === '' || str_contains(str_replace('\\', '/', $source), '/var/run/docker.sock')) {
                    throw new RuntimeException("Compose {$name}: bind mount vietato.");
                }
                $resolved = realpath($source);
                if ($resolved === false || ! $this->withinRoots($resolved, $roots)) {
                    throw new RuntimeException("Compose {$name}: bind mount fuori dal workspace: {$source}");
                }
            }
            $context = data_get($service, 'build.context');
            if (is_string($context)) {
                $resolved = realpath($context);
                if ($resolved === false || ! $this->withinRoots($resolved, $roots)) {
                    throw new RuntimeException("Compose {$name}: build context fuori dal workspace.");
                }
            }
        }
    }

    /** @param array<int, string> $roots */
    private function withinRoots(string $path, array $roots): bool
    {
        foreach ($roots as $root) {
            if ($path === $root || str_starts_with($path, $root.DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }
}
