<?php

namespace App\Services;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

final class DockerService
{
    public function createDoctorContainer(string $workspacePath, string $artifactsPath): string
    {
        $containerName = 'lailaps-doctor-'.Str::uuid();

        try {
            $result = Process::timeout(30)->run([
                'docker', 'run', '-d',
                '--name', $containerName,
                '--init',
                '--user', posix_geteuid().':'.posix_getegid(),
                '--env', 'HOME=/tmp',
                '--workdir', '/workspace',
                '--mount', "type=bind,src={$workspacePath},dst=/workspace",
                '--mount', "type=bind,src={$artifactsPath},dst=/artifacts",
                '--read-only',
                '--tmpfs', '/tmp:rw,nosuid,nodev,size=64m',
                '--network', 'none',
                '--cap-drop', 'ALL',
                '--security-opt', 'no-new-privileges',
                '--memory', '512m',
                '--cpus', '1',
                '--pids-limit', '64',
                'lailaps-toolbox:dev',
            ]);

            $result->throw();

            return trim($result->output());
        } catch (Throwable $error) {
            try {
                Process::timeout(10)->run(['docker', 'rm', '--force', $containerName]);
            } catch (Throwable $cleanupError) {
                report($cleanupError);
            }

            throw $error;
        }
    }

    public function runDoctor(
        string $containerId,
        string $sessionUrl,
        ?callable $output = null,
        bool $decorated = false,
    ): ProcessResult {
        return Process::path(base_path('agent'))->env([
            'OPENROUTER_API_KEY' => config('agent.openrouter_api_key'),
            'CONTAINER_ID' => $containerId,
            'AGENT_SESSION_URL' => $sessionUrl,
            'AGENT_TIMEOUT_MS' => (string) ((config('agent.doctor_timeout_seconds') - 10) * 1000),
            'FORCE_COLOR' => $decorated ? '1' : '0',
        ])->timeout(config('agent.doctor_timeout_seconds'))->run([config('agent.node_binary'), 'dist/doctor.js'], $output);
    }

    public function runSemgrep(string $semgrepImage, string $sourcePath, string $rulesPath): ProcessResult
    {
        return $this->run(
            image: $semgrepImage,
            command: [
                'semgrep', 'scan',
                '--config', '/rules/security-surfaces.yml',
                '--config', '/rules/community/default.yml',
                '--json',
                '--metrics', 'off',
                '--disable-version-check',
                '--no-rewrite-rule-ids',
                '--no-git-ignore',
                '--exclude', '.git/**',
                '--timeout', '10',
                '--max-target-bytes', '1000000',
                '.',
            ],
            mounts: [
                "type=bind,src={$sourcePath},dst=/workspace,readonly",
                "type=bind,src={$rulesPath},dst=/rules,readonly",
            ],
        );
    }

    public function runCodebaseMemory(string $cbmImage, string $sourcePath, string $indexPath): ProcessResult
    {
        return $this->run(
            image: $cbmImage,
            command: [
                'cli', 'index_repository',
                '--repo-path', '/workspace/source',
                '--persistence', 'false',
            ],
            mounts: [
                "type=bind,src={$sourcePath},dst=/workspace/source,readonly",
                "type=bind,src={$indexPath},dst=/index",
            ],
            environment: [
                'CBM_ALLOWED_ROOT' => '/workspace/source',
                'CBM_CACHE_DIR' => '/index',
            ],
        );
    }

    public function runDependencyAnalysis(string $image, string $sourcePath): ProcessResult
    {
        return $this->run(
            image: $image,
            command: [
                'scan', 'source',
                '--recursive',
                '--no-ignore',
                '--no-resolve',
                '--all-packages',
                '--format', 'json',
                '--config', '/dev/null',
                '--x-no-default-plugins',
                '--x-plugins', 'lockfile',
                '/workspace/source',
            ],
            mounts: [
                "type=bind,src={$sourcePath},dst=/workspace/source,readonly",
            ],
            timeout: 300,
            network: 'bridge',
        );
    }

    public function runRecon(
        string $image,
        string $sourcePath,
        string $inputsPath,
        string $indexPath,
        string $outputPath,
    ): ProcessResult {
        $key = config('services.openrouter.key');

        if (! $key) {
            throw new \RuntimeException('OpenRouter API key is not configured.');
        }

        return $this->run(
            image: $image,
            command: [
                '--prepared-inputs', '/inputs/prepared.json',
                '--output', '/output/outcome.json',
            ],
            mounts: [
                "type=bind,src={$sourcePath},dst=/workspace/source,readonly",
                "type=bind,src={$inputsPath},dst=/inputs,readonly",
                "type=bind,src={$indexPath},dst=/index",
                "type=bind,src={$outputPath},dst=/output",
                'type=bind,src=/var/run/docker.sock,dst=/var/run/docker.sock',
            ],
            environment: [
                'TOOLBOX_IMAGE' => $image,
            ],
            timeout: 1800,
            network: 'bridge',
            dockerOptions: [
                '--init',
                '--group-add', (string) filegroup('/var/run/docker.sock'),
                '--read-only',
                '--tmpfs', '/tmp:rw,nosuid,nodev,size=128m',
            ],
            secrets: [
                'OPENROUTER_API_KEY' => $key,
            ],
        );
    }

    public function runAudit(string $image, array $paths, string $network, ?callable $output = null): ProcessResult
    {
        $key = config('agent.openrouter_api_key');

        if (! $key) {
            throw new \RuntimeException('OpenRouter API key is not configured.');
        }

        return $this->run(
            image: $image,
            command: [
                '-m', 'pentest_agent.prepared_run',
                '--prepared-inputs', '/inputs/run.json',
                '--output', '/output/outcome.json',
            ],
            mounts: [
                "type=bind,src={$paths['source']},dst=/workspace/source,readonly",
                "type=bind,src={$paths['inputs']},dst=/inputs,readonly",
                "type=bind,src={$paths['index']},dst=/index",
                "type=bind,src={$paths['private']},dst=/private,readonly",
                "type=bind,src={$paths['output']},dst=/output",
                'type=bind,src=/var/run/docker.sock,dst=/var/run/docker.sock',
            ],
            environment: ['TOOLBOX_IMAGE' => $image],
            timeout: 3600,
            network: $network,
            dockerOptions: [
                '--init',
                '--group-add', (string) filegroup('/var/run/docker.sock'),
                '--read-only',
                '--tmpfs', '/tmp:rw,nosuid,nodev,size=128m',
                '--entrypoint', 'python',
            ],
            secrets: ['OPENROUTER_API_KEY' => $key],
            output: $output,
        );
    }

    public function run(
        string $image,
        array $command,
        array $mounts,
        array $environment = [],
        int $timeout = 600,
        ?string $user = null,
        string $network = 'none',
        array $dockerOptions = [],
        array $secrets = [],
        ?callable $output = null,
    ): ProcessResult {
        $containerName = 'lailaps-run-'.Str::uuid();

        $arguments = [
            'docker', 'run', '--rm',
            '--name', $containerName,
            '--network', $network,
            '--workdir', '/workspace',
        ];

        foreach ($mounts as $mount) {
            array_push($arguments, '--mount', $mount);
        }

        foreach (array_replace(['HOME' => '/tmp'], $environment) as $name => $value) {
            array_push($arguments, '--env', $name.'='.$value);
        }

        foreach (array_keys($secrets) as $name) {
            array_push($arguments, '--env', $name);
        }

        array_push($arguments, '--user', $user ?? posix_geteuid().':'.posix_getegid());

        try {
            return Process::timeout($timeout)
                ->env($secrets)
                ->run([...$arguments, ...$dockerOptions, $image, ...$command], $output);
        } catch (Throwable $error) {
            try {
                $this->removeContainer($containerName);
            } catch (Throwable $cleanupError) {
                report($cleanupError);
            }

            throw $error;
        }
    }

    public function removeContainer(string $containerId): void
    {
        $result = Process::timeout(10)->run(['docker', 'rm', '--force', $containerId]);

        if ($result->failed() && ! str_contains($result->errorOutput(), 'No such container:')) {
            $result->throw();
        }
    }
}
