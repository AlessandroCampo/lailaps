<?php

namespace App\Services\Audit;

use App\Models\AuditRun;

final class AuditCommandBuilder
{
    private const UNBIASED_BENCHMARK_PRESETS = ['owasp-benchmark-java', 'yeswiki'];

    /** @var array<string, array<string, mixed>> */
    public const PRESETS = [
        'juice-shop' => ['path' => 'targets/juice-shop', 'image' => 'bkimminich/juice-shop:latest', 'port' => 3000, 'category' => 'A01:2025 Broken Access Control'],
        'dvwa' => ['path' => 'targets/dvwa', 'category' => 'A01:2025 Broken Access Control'],
        'mutillidae-source' => ['path' => 'targets/mutillidae-source', 'category' => 'A03:2025 Injection'],
        'kanboard' => ['path' => 'targets/kanboard', 'port' => 80, 'category' => 'A01:2025 Broken Access Control'],
        'owasp-benchmark-java' => ['path' => 'targets/owasp-benchmark-java', 'category' => 'A05:2025 Injection'],
        'yeswiki' => ['path' => 'targets/yeswiki', 'category' => '', 'benchmark' => true],
    ];

    /** @return array<int, string> */
    public function build(AuditRun $run): array
    {
        $p = $run->parameters;
        $argv = [PHP_BINARY, base_path('artisan')];
        $benchmarkId = $p['benchmark_id'] ?? (
            in_array($p['preset'] ?? null, self::UNBIASED_BENCHMARK_PRESETS, true)
                ? $p['preset']
                : null
        );
        if ($benchmarkId !== null) {
            $command = [
                ...$argv,
                'benchmark:run',
                (string) $benchmarkId,
                '--audit-id='.$run->audit_id,
                ...($p['keep'] ? ['--keep'] : []),
                ...($p['test'] ? ['--test'] : []),
                // The web transcript retains bounded result previews so the UI
                // can reveal them on demand without loading raw tool payloads.
                '--tool-output',
            ];
            foreach ((array) ($p['categories'] ?? []) as $category) {
                if ($category) {
                    $command[] = '--category='.$category;
                }
            }
            $this->value($command, 'url', $p['url'] ?? null);
            $this->value($command, 'db', $p['db'] ?? null);
            $this->value($command, 'health-path', $p['health_path'] ?? null);
            $this->value($command, 'reader-model', $p['reader_model'] ?? null);
            $this->value($command, 'reviewer-model', $p['reviewer_model'] ?? null);
            $this->value($command, 'confirmer-model', $p['confirmer_model'] ?? null);
            $this->value($command, 'worker-model', $p['worker_model'] ?? null);
            if ((bool) ($p['skip_health'] ?? false)) {
                $command[] = '--skip-health';
            }
            if ((bool) ($p['authorized'] ?? false)) {
                $command[] = '--assume-authorized';
            }

            return $command;
        }

        $preset = isset($p['preset']) ? (self::PRESETS[$p['preset']] ?? []) : [];
        $argv = [...$argv, 'pentest:run', '--audit-id='.$run->audit_id];
        if (isset($p['project_name']) && $p['project_name'] !== '') {
            $argv[] = '--project-name='.(string) $p['project_name'];
        }
        $this->value($argv, 'path', $p['path'] ?? (isset($preset['path']) ? base_path($preset['path']) : null));
        foreach (($p['categories'] ?: [($preset['category'] ?? null)]) as $category) {
            if ($category) {
                $argv[] = '--category='.$category;
            }
        }
        $this->value($argv, 'url', $p['url'] ?? null);
        $this->value($argv, 'db', $p['db'] ?? null);
        $this->value($argv, 'health-path', $p['health_path'] ?? null);
        $this->value($argv, 'image', $p['image'] ?? ($preset['image'] ?? null));
        $this->value($argv, 'dockerfile', $p['dockerfile'] ?? null);
        $this->value($argv, 'mount', $p['mount'] ?? null);
        $this->value($argv, 'port', $p['port'] ?? ($preset['port'] ?? null));
        $this->value($argv, 'service', $p['service'] ?? null);
        $this->value($argv, 'compose', $p['compose'] ?? null);
        $this->value($argv, 'reader-model', $p['reader_model'] ?? null);
        $this->value($argv, 'reviewer-model', $p['reviewer_model'] ?? null);
        $this->value($argv, 'confirmer-model', $p['confirmer_model'] ?? null);
        $this->value($argv, 'worker-model', $p['worker_model'] ?? null);
        $argv[] = '--ttl='.(int) $p['ttl'];

        foreach ([
            'skip-health' => (bool) ($p['skip_health'] ?? false),
            'assume-authorized' => (bool) ($p['authorized'] ?? false),
            'test' => (bool) ($p['test'] ?? false),
            'keep' => (bool) ($p['keep'] ?? false),
            // Web runs always retain the compact preview; visibility is a
            // client-side preference and defaults to off.
            'tool-output' => true,
        ] as $option => $enabled) {
            if ($enabled) {
                $argv[] = '--'.$option;
            }
        }

        return $argv;
    }

    /** @param array<int, string> $argv */
    private function value(array &$argv, string $name, mixed $value): void
    {
        if ($value !== null && $value !== '') {
            $argv[] = '--'.$name.'='.(string) $value;
        }
    }
}
