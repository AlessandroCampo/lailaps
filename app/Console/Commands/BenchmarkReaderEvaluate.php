<?php

namespace App\Console\Commands;

use App\Services\Audit\RunStorage;
use App\Services\Pentest\BenchmarkCatalog;
use App\Services\Pentest\BenchmarkStageProcessRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

final class BenchmarkReaderEvaluate extends Command
{
    protected $signature = 'benchmark:reader-evaluate
        {target-id : Project key}
        {--parent= : Explicit parent outcome JSON}
        {--output= : New evaluation directory}
        {--fixtures= : Surviving fixture directory belonging to this parent}
        {--children= : Child outcome root; default runs/<target>/reader-area}
        {--mode=metrics : metrics, dedup, full or evaluate}
        {--dedup-artifact= : Existing dedup.json for evaluation only}
        {--roles-config= : Full shared role configuration; incompatible with individual role overrides}
        {--collection= : Frozen collection.json; recovery without rediscovery}
        {--resume-failed : Retry technical failures and pending work with remaining budget}
        {--preflight-only : Verify artifacts, context sizing and source with zero inference}
        {--deduper-model= : Explicit deduper model}
        {--deduper-provider= : Explicit serving provider}
        {--deduper-points= : Total deduper EP for this series}
        {--evaluator-model= : Explicit semantic evaluator model}
        {--evaluator-provider= : Explicit serving provider}
        {--evaluator-points= : Total semantic phase EP}
        {--source= : Optional source directory matching parent snapshot}
        {--source-snapshot= : Explicit snapshot of optional source}';

    protected $description = 'Post-processing artifact Reader: metriche, deduplica e giudizi semantici separati';

    public function handle(BenchmarkCatalog $catalog, BenchmarkStageProcessRunner $runner, RunStorage $storage): int
    {
        $target = (string) $this->argument('target-id');
        $mode = (string) $this->option('mode');
        $parent = realpath((string) $this->option('parent'));
        $output = (string) $this->option('output');
        if (! $parent || ! is_file($parent) || $output === '' || ! in_array($mode, ['metrics', 'dedup', 'full', 'evaluate'], true)) {
            throw new InvalidArgumentException('Explicit parent/output and valid mode required.');
        }
        $rolesPath = $this->option('roles-config') ? realpath((string) $this->option('roles-config')) : null;
        if ($this->option('roles-config') && (! $rolesPath || ! is_file($rolesPath))) {
            throw new InvalidArgumentException('Role configuration unavailable.');
        }
        $config = $rolesPath ? json_decode(File::get($rolesPath), true, flags: JSON_THROW_ON_ERROR) : ['project' => $target];
        if (($config['project'] ?? $target) !== $target) {
            throw new InvalidArgumentException('Role configuration target mismatch.');
        }
        if ($rolesPath) {
            foreach (['deduper', 'evaluator'] as $role) {
                foreach (['model', 'provider', 'points'] as $field) {
                    if ($this->option($role.'-'.$field) !== null) {
                        throw new InvalidArgumentException('--roles-config is incompatible with individual role overrides.');
                    }
                }
            }
        }
        foreach (['deduper' => ['dedup', 'full'], 'evaluator' => ['evaluate', 'full']] as $role => $modes) {
            if (! in_array($mode, $modes, true)) {
                continue;
            }
            if ($rolesPath) {
                if (! is_array($config[$role] ?? null)) {
                    throw new InvalidArgumentException("Role configuration missing {$role}.");
                }
                continue;
            }
            $model = trim((string) $this->option($role.'-model'));
            $provider = trim((string) $this->option($role.'-provider'));
            $points = (float) $this->option($role.'-points');
            if ($model === '' || $provider === '' || $points <= 0) {
                throw new InvalidArgumentException("{$role} requires explicit model/provider/positive total points.");
            }
            $config[$role] = ['model' => $model, 'provider' => $provider, 'total_points' => $points];
        }
        $dedup = $this->option('dedup-artifact') ? realpath((string) $this->option('dedup-artifact')) : null;
        if ($mode === 'evaluate' && (! $dedup || ! is_file($dedup))) {
            throw new InvalidArgumentException('evaluate requires an existing dedup artifact.');
        }
        $fixtures = $this->option('fixtures') ? realpath((string) $this->option('fixtures')) : null;
        $children = realpath((string) ($this->option('children') ?: storage_path("app/runs/{$target}/reader-area")));
        if (! $children || ! is_dir($children) || ($this->option('fixtures') && (! $fixtures || ! is_dir($fixtures)))) {
            throw new InvalidArgumentException('Child root/fixture directory unavailable.');
        }
        $parentData = json_decode(File::get($parent), true, flags: JSON_THROW_ON_ERROR);
        $snapshot = data_get($parentData, 'report.source_snapshot') ?? data_get($parentData, 'benchmark.source_snapshot');
        if (! is_string($snapshot) || $snapshot === '') {
            throw new InvalidArgumentException('Parent source snapshot missing.');
        }
        if (isset($config['snapshot']) && $config['snapshot'] !== $snapshot) {
            throw new InvalidArgumentException('Role configuration snapshot mismatch.');
        }
        $config['snapshot'] = $snapshot;
        $source = $this->option('source') ? realpath((string) $this->option('source')) : base_path("targets/{$target}");
        if (! $source || ! is_dir($source)) {
            throw new InvalidArgumentException('Source root unavailable.');
        }
        if ($this->option('source')) {
            if ($this->option('source-snapshot') !== $snapshot) {
                throw new InvalidArgumentException('Optional source must match the selected snapshot.');
            }
            $config['source_snapshot'] = $snapshot;
        }
        $cases = [];
        if (in_array($mode, ['full', 'evaluate'], true)) {
            foreach ($catalog->forTarget($target) as $manifest) {
                if ((data_get($manifest->data, 'source.commit') ?? data_get($manifest->data, 'source.snapshot')) !== $snapshot) {
                    throw new InvalidArgumentException('Manifest catalog snapshot differs from selected parent.');
                }
                foreach ($manifest->cases() as $case) {
                    $cases[] = [...$case, 'manifest_id' => $manifest->id()];
                }
            }
            if ($cases === [] || count(array_unique(array_column($cases, 'id'))) !== count($cases)) {
                throw new InvalidArgumentException('Manifest catalog empty or has ambiguous case IDs.');
            }
        }
        File::ensureDirectoryExists($output);
        $output = realpath($output);
        $configPath = $output.'/roles.json';
        $manifestPath = $output.'/manifests.json';
        File::put($configPath, json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        File::put($manifestPath, json_encode(['source_snapshot' => $snapshot, 'cases' => $cases], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $container = $runner->containerized();
        $args = ['reader-evaluate', '--parent', $container ? '/parent.json' : $parent,
            '--target', $target, '--output', $container ? '/artifacts' : $output,
            '--mode', $mode, '--config', $container ? '/artifacts/roles.json' : $configPath,
            '--children', $container ? '/children' : $children];
        $mounts = [$parent => '/parent.json', $children => '/children'];
        if ($this->option('resume-failed')) {
            $args[] = '--resume-failed';
        }
        if ($this->option('preflight-only')) {
            $args[] = '--preflight-only';
        }
        if ($this->option('collection')) {
            $collection = realpath((string) $this->option('collection'));
            if (! $collection || ! is_file($collection)) {
                throw new InvalidArgumentException('Frozen collection unavailable.');
            }
            $mounts[dirname($collection)] = '/frozen-input';
            array_push($args, '--collection', $container ? '/frozen-input/'.basename($collection) : $collection);
        }
        if ($fixtures) {
            array_push($args, '--fixtures', $container ? '/fixtures' : $fixtures);
            $mounts[$fixtures] = '/fixtures';
        }
        if ($dedup) {
            array_push($args, '--dedup-artifact', $container ? '/dedup-input.json' : $dedup);
            $mounts[$dedup] = '/dedup-input.json';
        }
        if ($cases !== []) {
            array_push($args, '--manifests', $container ? '/artifacts/manifests.json' : $manifestPath);
        }
        if ($this->option('source')) {
            array_push($args, '--source-root', $container ? '/workspace' : $source);
        }
        $code = $runner->run($source, $output, 'reader-evaluate-'.substr(hash('sha256', $parent.$output), 0, 16),
            $args, $mounts, $storage, fn ($type, $buffer) => $this->output->write($buffer));
        $phasePath = $output.'/phase-status.json';
        $phase = is_file($phasePath) ? json_decode(File::get($phasePath), true, flags: JSON_THROW_ON_ERROR) : null;
        if (! $phase || (int) ($phase['exit_code'] ?? -1) !== $code) {
            $this->error('Postprocessor did not publish a consistent phase status.');
            return self::FAILURE;
        }
        $this->line("Artifacts: {$output}/scorecard.json and report.md; phase={$phase['status']}; reason=".($phase['stop_reason'] ?? '-'));
        return in_array($code, [0, 2], true) ? $code : self::FAILURE;
    }
}
