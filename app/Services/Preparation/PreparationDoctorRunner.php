<?php

namespace App\Services\Preparation;

use App\Models\Preparation;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;

final class PreparationDoctorRunner
{
    /** @param array<string, mixed> $state
     *  @return array<string, mixed>
     */
    public function run(Preparation $preparation, string $sourceRoot, string $artifactsRoot, array $state, int $timeout): array
    {
        File::ensureDirectoryExists($artifactsRoot);
        $statePath = $artifactsRoot.'/doctor-state.json';
        $outputPath = $artifactsRoot.'/doctor-output.json';
        File::put($statePath, json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        @chmod($statePath, 0600);

        $config = (array) config('pentest.agent');
        $model = config('audits.doctor.model');
        $args = [
            'doctor', '--source-root', $config['runtime'] === 'container' ? '/workspace' : $sourceRoot,
            '--artifacts-root', $config['runtime'] === 'container' ? '/doctor' : $artifactsRoot,
            '--state-file', $config['runtime'] === 'container' ? '/doctor/doctor-state.json' : $statePath,
            '--output-file', $config['runtime'] === 'container' ? '/doctor/doctor-output.json' : $outputPath,
            ...($model ? ['--model', (string) $model] : []),
        ];
        $command = $config['runtime'] === 'container'
            ? $this->containerCommand($config, $preparation, $sourceRoot, $artifactsRoot, $args)
            : [...(array) $config['command'], ...$args];
        if ($config['runtime'] !== 'container') {
            $scan = array_search('scan', $command, true);
            if ($scan !== false) {
                unset($command[$scan]);
                $command = array_values($command);
            }
        }

        $process = new Process(
            $command,
            $config['runtime'] === 'container' ? base_path() : (string) $config['path'],
            (array) ($config['env'] ?? []),
            null,
            max(1, $timeout),
        );
        $process->run();
        if (! $process->isSuccessful() || ! is_file($outputPath)) {
            $message = trim($process->getErrorOutput() ?: $process->getOutput());
            throw new RuntimeException('Environment Doctor fallito: '.mb_substr($message, 0, 4000));
        }
        $payload = json_decode((string) file_get_contents($outputPath), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($payload) || ! is_array($payload['decision'] ?? null)) {
            throw new RuntimeException('Output Environment Doctor non valido.');
        }

        return $payload;
    }

    /** @param array<string, mixed> $config
     *  @param array<int, string> $args
     *  @return array<int, string>
     */
    private function containerCommand(array $config, Preparation $preparation, string $sourceRoot, string $artifactsRoot, array $args): array
    {
        $command = [
            'docker', 'run', '--rm', '--name', 'lailaps-doctor-'.$preparation->id,
            '--read-only', '--tmpfs', '/tmp:rw,noexec,nosuid,size=128m',
            '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges:true',
            '--memory', '1g', '--pids-limit', '128',
            '--volume', str_replace('\\', '/', $sourceRoot).':/workspace:ro',
            '--volume', str_replace('\\', '/', $artifactsRoot).':/doctor:rw',
        ];
        $modelsFile = realpath((string) $config['models_file']);
        if ($modelsFile !== false) {
            array_push($command, '--volume', str_replace('\\', '/', $modelsFile).':/models.json:ro', '--env', 'MODELS_CONFIG_FILE=/models.json');
        }
        if (is_file((string) $config['env_file'])) {
            array_push($command, '--env-file', (string) realpath((string) $config['env_file']));
        }

        return [...$command, (string) $config['image'], ...$args];
    }
}
