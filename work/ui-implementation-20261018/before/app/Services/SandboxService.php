<?php

namespace App\Services;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Throwable;

final class SandboxService
{
    public function manifest(string $artifacts, string $workspace): array
    {
        $path = $this->within($artifacts.'/lailaps.manifest.json', [$artifacts]);
        $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $manifest = Validator::make($data, [
            'version' => ['required', 'in:1'],
            'application_root' => ['required', 'string'],
            'compose_file' => ['required', 'string'],
            'web_service' => ['required', 'string', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'web_port' => ['required', 'integer', 'between:1,65535'],
            'bootstrap_file' => ['present', 'nullable', 'string'],
            'readiness' => ['required', 'array', 'min:1'],
            'readiness.*.path' => ['required', 'string', 'starts_with:/'],
            'readiness.*.expected_status' => ['required', 'integer', 'between:200,399'],
            'readiness.*.body_contains' => ['present', 'nullable', 'string'],
            'actors' => ['present', 'array'],
            'actors.*.role' => ['required', 'string'],
            'actors.*.username' => ['required', 'string'],
            'actors.*.password_ref' => ['required', 'string', 'regex:/^[A-Z_][A-Z0-9_]*$/'],
            'environment' => ['sometimes', 'array'],
            'environment.*' => ['required', 'in:secret,base64_32'],
        ])->validate();
        if (! collect($manifest['readiness'])->contains(fn ($check) => $check['expected_status'] < 300 && trim($check['body_contains'] ?? '') !== '')) {
            throw new RuntimeException('Readiness requires at least one 2xx response with an application-specific body marker.');
        }
        $this->within($workspace.'/'.$manifest['application_root'], [$workspace]);
        $this->within($artifacts.'/'.$manifest['compose_file'], [$artifacts]);
        if ($manifest['bootstrap_file'] !== null) {
            $this->within($artifacts.'/'.$manifest['bootstrap_file'], [$artifacts]);
        }

        return $manifest;
    }

    public function environment(array $manifest, array $existing): array
    {
        $generators = $manifest['environment'] ?? [];
        foreach ($manifest['actors'] as $actor) {
            $generators[$actor['password_ref']] ??= 'secret';
        }
        foreach ($generators as $name => $generator) {
            if (! preg_match('/^[A-Z_][A-Z0-9_]*$/', $name)) {
                throw new RuntimeException('Invalid environment variable name in the recipe.');
            }
            $existing[$name] ??= $generator === 'base64_32'
                ? 'base64:'.base64_encode(random_bytes(32))
                : bin2hex(random_bytes(24));
        }

        return $existing;
    }

    public function configure(array $manifest, array $paths, string $id, array $environment): array
    {
        $files = ['--env-file', '/dev/null', '--project-directory', $paths['artifacts'], '-f', $paths['artifacts'].'/'.$manifest['compose_file']];
        $result = Process::env($this->processEnvironment($environment))->timeout(30)
            ->run(['docker', 'compose', ...$files, 'config', '--format', 'json', '--no-env-resolution']);
        if ($result->failed()) {
            throw new RuntimeException('Compose configuration failed: '.$result->errorOutput());
        }
        $config = $this->decodeConfig($result->output());
        $roots = [$paths['workspace'], $paths['artifacts']];
        $project = $this->projectName($id);
        if (! isset($config['services'][$manifest['web_service']])) {
            throw new RuntimeException('The manifest web_service is missing from the Compose configuration.');
        }
        foreach (['networks', 'volumes', 'secrets', 'configs'] as $section) {
            foreach ($config[$section] ?? [] as $name => &$definition) {
                if ($definition['external'] ?? false) {
                    throw new RuntimeException('External Docker resources are not allowed.');
                }
                if (isset($definition['file'])) {
                    $this->within($definition['file'], $roots);
                }
                if (in_array($section, ['networks', 'volumes'], true)) {
                    $definition['name'] = $project.'-'.$name;
                    $definition['labels'] = (array) ($definition['labels'] ?? []);
                    $definition['labels']['lailaps.session'] = $id;
                    $config[$section][$name] = $definition;
                }
            }
            unset($definition);
        }
        $builtImages = [];
        $imageAliases = [];

        foreach ($config['services'] as $name => &$service) {
            if (($service['privileged'] ?? false) || ($service['use_api_socket'] ?? false)
                || ! empty($service['devices']) || ! empty($service['volumes_from'])
                || ! empty($service['build']['additional_contexts']) || ! empty($service['build']['ssh'])
                || ! empty($service['build']['secrets']) || ! empty($service['build']['entitlements'])
                || ($service['build']['network'] ?? null) === 'host') {
                throw new RuntimeException("Service {$name} requests unsupported host access.");
            }
            foreach (['network_mode', 'pid', 'ipc'] as $option) {
                if (isset($service[$option]) && ($service[$option] === 'host' || str_starts_with($service[$option], 'container:'))) {
                    throw new RuntimeException("Service {$name} requests host or external container namespaces.");
                }
            }
            foreach ($service['volumes'] ?? [] as $volume) {
                if (($volume['type'] ?? null) === 'bind') {
                    $this->within($volume['source'], $roots);
                }
            }
            foreach ($service['env_file'] ?? [] as $file) {
                $this->within(is_array($file) ? $file['path'] : $file, $roots);
            }
            if (isset($service['build'])) {
                $context = $this->within($service['build']['context'], $roots);
                if (isset($service['build']['dockerfile'])) {
                    $dockerfile = $service['build']['dockerfile'];
                    $this->within(str_starts_with($dockerfile, '/') ? $dockerfile : $context.'/'.$dockerfile, $roots);
                }
                $buildHash = hash('sha256', json_encode([$service['build'], $service['platform'] ?? null], JSON_THROW_ON_ERROR));
                $image = $project.'-'.substr($buildHash, 0, 12);

                if (isset($service['image'])) {
                    $alias = $service['image'];

                    if (isset($imageAliases[$alias]) && $imageAliases[$alias] !== $image) {
                        throw new RuntimeException("Image {$alias} is declared by different builds. Use distinct image names.");
                    }

                    $imageAliases[$alias] = $image;
                }

                $service['image'] = $image;
                $service['pull_policy'] = 'never';

                if (isset($builtImages[$buildHash])) {
                    unset($service['build']);
                } else {
                    $builtImages[$buildHash] = $image;
                }
            }
            $service['container_name'] = $project.'-'.$name;
            $service['labels'] = (array) ($service['labels'] ?? []);
            $service['labels']['lailaps.session'] = $id;
            $service['restart'] = 'no';
            $service['cap_drop'] = ['ALL'];
            $service['cap_add'] = ['CHOWN', 'DAC_OVERRIDE', 'FOWNER', 'SETGID', 'SETUID', 'NET_BIND_SERVICE', 'KILL'];
            $service['security_opt'] = ['no-new-privileges:true'];
            $service['pids_limit'] = 200;
            $service['mem_limit'] = '512m';
            $service['cpus'] = '1';
            $service['ports'] = $name === $manifest['web_service']
                ? [['target' => $manifest['web_port'], 'published' => '0', 'host_ip' => '127.0.0.1', 'protocol' => 'tcp']]
                : [];
        }
        unset($service);

        foreach ($config['services'] as &$service) {
            if (isset($service['image'], $imageAliases[$service['image']])) {
                $service['image'] = $imageAliases[$service['image']];
                $service['pull_policy'] = 'never';
            }
        }
        unset($service);

        $config['name'] = $project;

        return $config;
    }

    public function start(array $config, string $artifacts, string $id, array $manifest, callable $output): string
    {
        $this->compose($config, $artifacts, $id, ['build'], config('sandbox.build_timeout'), $output)->throw();
        $this->compose($config, $artifacts, $id, ['up', '-d', '--wait', '--wait-timeout', (string) config('sandbox.start_timeout')], config('sandbox.start_timeout') + 10, $output)->throw();
        $result = $this->compose($config, $artifacts, $id, ['port', $manifest['web_service'], (string) $manifest['web_port']], 10);
        $result->throw();
        if (! preg_match('/^127\.0\.0\.1:(\d+)$/m', trim($result->output()), $matches)) {
            throw new RuntimeException('Docker did not publish the expected loopback HTTP port.');
        }

        return 'http://127.0.0.1:'.$matches[1];
    }

    public function waitUntilHealthy(string $url, array $checks): void
    {
        $deadline = microtime(true) + config('sandbox.health_timeout');
        $lastError = 'No response received.';
        do {
            try {
                foreach ($checks as $check) {
                    $remaining = max(0.1, $deadline - microtime(true));
                    $response = Http::connectTimeout(min(2, $remaining))->timeout(min(5, $remaining))
                        ->withoutRedirecting()->get($url.$check['path']);
                    if ($response->status() !== $check['expected_status']
                        || ($check['body_contains'] !== null && ! str_contains($response->body(), $check['body_contains']))) {
                        throw new RuntimeException($check['path'].' returned HTTP '.$response->status().'; expected '.$check['expected_status'].' and marker '.json_encode($check['body_contains']).'. Body: '.mb_substr($response->body(), 0, 500));
                    }
                }

                return;
            } catch (Throwable $error) {
                $lastError = $error->getMessage();
            }
            usleep(500_000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException('Application readiness failed: '.$lastError);
    }

    public function diagnosticLogs(array $config, string $artifacts, string $id): string
    {
        try {
            $status = $this->compose($config, $artifacts, $id, ['ps', '--all', '--format', 'json'], 10);
            $logs = $this->compose($config, $artifacts, $id, ['logs', '--no-color', '--tail', '80'], 10);

            return mb_substr($status->output()."\n".$logs->output().$logs->errorOutput(), -30_000);
        } catch (Throwable $error) {
            return 'Diagnostic collection failed: '.$error->getMessage();
        }
    }

    public function destroy(array $config, string $artifacts, string $id): void
    {
        $this->compose($config, $artifacts, $id, ['down', '-v', '--remove-orphans', '--timeout', '10'], 60)->throw();
    }

    public function redact(string $text, array $environment): string
    {
        foreach ($environment as $value) {
            if (is_string($value) && strlen($value) >= 8) {
                $text = str_replace($value, '[redacted]', $text);
            }
        }

        return $text;
    }

    public function decodeConfig(string $json): array
    {
        $convert = function (mixed $value) use (&$convert): mixed {
            if ($value instanceof \stdClass && get_object_vars($value) !== []) {
                return array_map($convert, get_object_vars($value));
            }

            return is_array($value) ? array_map($convert, $value) : $value;
        };

        return $convert(json_decode($json, false, 512, JSON_THROW_ON_ERROR));
    }

    private function compose(array $config, string $artifacts, string $id, array $args, int $timeout, ?callable $output = null): ProcessResult
    {
        return Process::env($this->processEnvironment([]))->input(json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
            ->timeout($timeout)->run(['docker', 'compose', '--project-directory', $artifacts, '-p', $this->projectName($id), '-f', '-', ...$args], $output);
    }

    private function processEnvironment(array $variables): array
    {
        $environment = array_fill_keys(array_keys(getenv()), false);
        foreach (['PATH', 'HOME', 'DOCKER_HOST', 'DOCKER_CONTEXT', 'DOCKER_CONFIG', 'DOCKER_TLS_VERIFY', 'DOCKER_CERT_PATH', 'HTTP_PROXY', 'HTTPS_PROXY', 'NO_PROXY'] as $key) {
            if (getenv($key) !== false) {
                $environment[$key] = getenv($key);
            }
        }

        return array_replace($environment, $variables);
    }

    private function projectName(string $id): string
    {
        return 'lailaps-'.strtolower($id);
    }

    private function within(string $path, array $roots): string
    {
        $resolved = realpath($path);
        foreach ($roots as $root) {
            $root = realpath($root);
            if ($resolved !== false && $root !== false && ($resolved === $root || str_starts_with($resolved, $root.DIRECTORY_SEPARATOR))) {
                return $resolved;
            }
        }

        throw new RuntimeException('Recipe path is missing or outside the session workspace: '.$path);
    }
}
