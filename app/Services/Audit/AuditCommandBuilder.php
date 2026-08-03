<?php

namespace App\Services\Audit;

use App\Models\AuditRun;

final class AuditCommandBuilder
{
    /** @var array<string, array<string, mixed>> */
    public const PRESETS = [
        'juice-shop' => ['path' => 'targets/juice-shop', 'image' => 'bkimminich/juice-shop:latest', 'port' => 3000, 'category' => 'A01:2025 Broken Access Control', 'dual_agent' => true],
        'dvwa' => ['path' => 'targets/dvwa', 'category' => 'A01:2025 Broken Access Control', 'dual_agent' => false],
        'mutillidae-source' => ['path' => 'targets/mutillidae-source', 'category' => 'A03:2025 Injection', 'dual_agent' => false],
        'owasp-benchmark-java' => ['path' => 'targets/owasp-benchmark-java', 'category' => 'A05:2025 Injection', 'dual_agent' => false],
    ];

    /** @return array<int, string> */
    public function build(AuditRun $run): array
    {
        $p = $run->parameters;
        $argv = [PHP_BINARY, base_path('artisan')];
        if ($run->type === 'benchmark') {
            return [...$argv, 'benchmark:run', (string) $p['benchmark_id'], '--audit-id='.$run->audit_id, ...($p['keep'] ? ['--keep'] : [])];
        }

        $preset = isset($p['preset']) ? (self::PRESETS[$p['preset']] ?? []) : [];
        $argv = [...$argv, 'pentest:run', '--audit-id='.$run->audit_id];
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
        $this->value($argv, 'worker-model', $p['worker_model'] ?? null);
        $argv[] = '--ttl='.(int) $p['ttl'];

        $dual = (bool) ($p['dual_agent'] ?? ($preset['dual_agent'] ?? false));
        foreach ([
            'dual-agent' => $dual,
            'skip-health' => (bool) ($p['skip_health'] ?? false),
            'assume-authorized' => (bool) ($p['authorized'] ?? false),
            'test' => (bool) ($p['test'] ?? false),
            'keep' => (bool) ($p['keep'] ?? false),
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
