<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class DevSnapshot extends Command
{
    protected $signature = 'dev:snapshot {action : export, verify or restore} {snapshot? : Absolute snapshot directory}';

    protected $description = 'Back up and restore local development data and project files';

    private const array TABLES = [
        'users', 'projects', 'project_sources', 'agent_sessions', 'labs', 'project_artifacts', 'audit_runs',
        'audit_run_project_artifact', 'recon_areas', 'audit_run_areas', 'audit_run_leads',
    ];

    private const array LEGACY_TABLES = [
        'users', 'projects', 'project_sources', 'agent_sessions', 'labs', 'project_artifacts', 'audit_runs',
    ];

    private const array MAPPABLE = [
        'users', 'projects', 'project_sources', 'labs', 'project_artifacts', 'audit_runs',
        'audit_run_project_artifact', 'recon_areas', 'audit_run_areas', 'audit_run_leads',
    ];

    public function handle(): int
    {
        try {
            if (! app()->environment(['local', 'testing'])) {
                throw new RuntimeException('Development snapshots require the local environment.');
            }

            $action = $this->argument('action');
            if (! in_array($action, ['export', 'verify', 'restore'], true)) {
                throw new RuntimeException('Choose export, verify or restore.');
            }

            $path = $this->argument('snapshot');
            if ($action === 'export') {
                $path ??= storage_path('app/private/dev-snapshots/'.now()->format('Ymd-His').'-'.Str::ulid());
                $this->export($path);
            } else {
                if (! $path) {
                    throw new RuntimeException('An absolute snapshot directory is required.');
                }
                $data = $this->verify($path);
                if ($action === 'restore') {
                    $this->restore($path, $data);
                }
            }

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }

    private function export(string $path): void
    {
        $this->assertAbsolute($path);
        $this->assertIdle();
        if (file_exists($path)) {
            throw new RuntimeException('The snapshot directory already exists. Choose a new directory.');
        }
        $this->privateDirectory($path);
        $root = realpath(Storage::disk('projects')->path(''));
        if ($root === false) {
            throw new RuntimeException('Project storage is missing.');
        }
        if (str_starts_with(realpath($path).'/', $root.'/')) {
            throw new RuntimeException('Store snapshots outside the projects disk.');
        }

        $tables = DB::transaction(function (): array {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }
            $tables = [];
            foreach (self::TABLES as $table) {
                $tables[$table] = Schema::hasTable($table)
                    ? DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()
                    : [];
            }

            return $tables;
        });

        $directories = [];
        foreach ($tables['projects'] as $project) {
            $id = (string) $project['id'];
            if (! preg_match('/^[A-Za-z0-9_-]+$/', $id)) {
                throw new RuntimeException('A project has an unsupported storage identifier.');
            }
            if (is_link($root.'/'.$id)) {
                throw new RuntimeException('Project storage roots must not be symlinks.');
            }
            if (is_dir($root.'/'.$id)) {
                $directories[] = $id;
            }
        }
        foreach ($tables['project_sources'] as $source) {
            if ($source['status'] === 'ready' && ! is_dir($root.'/'.$source['project_id'].'/sources/'.$source['id'].'/files')) {
                throw new RuntimeException('Ready source '.$source['id'].' has no source directory.');
            }
        }

        $archive = $path.'/projects.tar';
        $command = ['tar', '--create', '--file', $archive, '--directory', $root];
        $command = $directories ? [...$command, '--', ...$directories] : [...$command, '--files-from', '/dev/null'];
        Process::path(base_path())->timeout(600)->run($command)->throw();
        chmod($archive, 0600);
        $this->assertIdle();

        $ulids = [];
        foreach (self::MAPPABLE as $table) {
            foreach ($tables[$table] as $row) {
                $candidate = $row['public_id'] ?? $row['id'];
                $ulids[$table][(string) $row['id']] = Str::isUlid((string) $candidate)
                    ? (string) $candidate : (string) Str::ulid();
            }
        }
        $data = [
            'schema_version' => 2,
            'created_at' => now()->toIso8601String(),
            'storage_root' => $root,
            'directories' => $directories,
            'archive_sha256' => hash_file('sha256', $archive),
            'ulids' => $ulids,
            'tables' => $tables,
        ];
        File::put($path.'/snapshot.enc', Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR)));
        chmod($path.'/snapshot.enc', 0600);
        $this->verify($path);
        $this->info('Snapshot created: '.$path);
    }

    private function verify(string $path): array
    {
        $this->assertAbsolute($path);
        try {
            $data = json_decode(Crypt::decryptString(File::get($path.'/snapshot.enc')), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException('Snapshot metadata cannot be read. Keep the original APP_KEY and snapshot.enc.');
        }
        $expectedTables = ($data['schema_version'] ?? null) === 1 ? self::LEGACY_TABLES : self::TABLES;
        if (! in_array($data['schema_version'] ?? null, [1, 2], true)
            || array_keys($data['tables'] ?? []) !== $expectedTables
            || ! is_file($path.'/projects.tar')
            || ! hash_equals($data['archive_sha256'], hash_file('sha256', $path.'/projects.tar'))) {
            throw new RuntimeException('The snapshot is incomplete or its archive checksum does not match.');
        }
        foreach (self::TABLES as $table) {
            $data['tables'][$table] ??= [];
        }
        Process::path(base_path())->timeout(120)->run(['tar', '--list', '--file', $path.'/projects.tar'])->throw();
        foreach ($data['tables'] as $table => $rows) {
            $this->line($table.': '.count($rows));
        }
        $this->info('Snapshot integrity verified.');

        return $data;
    }

    private function restore(string $path, array $data): void
    {
        $this->assertIdle();
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                if ($data['tables'][$table]) {
                    throw new RuntimeException('The target schema is missing table '.$table.'.');
                }
            } elseif (DB::table($table)->exists()) {
                throw new RuntimeException('Restore requires empty application tables; '.$table.' contains data.');
            }
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'id')) {
                throw new RuntimeException('The target table '.$table.' requires an id column. Use ulid(\'id\')->primary() for ULID keys.');
            }
        }
        $root = rtrim(Storage::disk('projects')->path(''), '/');
        $this->privateDirectory($root);
        $ids = [];
        foreach (self::MAPPABLE as $table) {
            foreach ($data['tables'][$table] as $row) {
                $old = (string) $row['id'];
                if (! Schema::hasTable($table)) {
                    throw new RuntimeException('The target schema is missing table '.$table.'.');
                }
                if (! $this->integerKey($table) && ! isset($data['ulids'][$table][$old])) {
                    throw new RuntimeException('Export a new snapshot before changing '.$table.' keys to ULID.');
                }
                $ids[$table][$old] = $this->integerKey($table) ? $row['id'] : $data['ulids'][$table][$old];
            }
        }
        $projectIds = $ids['projects'] ?? [];
        $replacements = [];
        foreach ($projectIds as $old => $new) {
            $replacements[$data['storage_root'].'/'.$old.'/'] = $root.'/'.$new.'/';
            $replacements[$old.'/'] = $new.'/';
        }
        $sources = array_column($data['tables']['project_sources'], null, 'id');
        $folders = ['project_sources' => 'sources', 'labs' => 'labs', 'project_artifacts' => 'artifacts', 'audit_runs' => 'runs'];
        foreach ($folders as $table => $folder) {
            foreach ($data['tables'][$table] as $row) {
                $oldProject = $row['project_id'] ?? $sources[$row['project_source_id']]['project_id'];
                $old = $oldProject.'/'.$folder.'/'.$row['id'].'/';
                $new = $projectIds[(string) $oldProject].'/'.$folder.'/'.$ids[$table][(string) $row['id']].'/';
                $replacements[$data['storage_root'].'/'.$old] = $root.'/'.$new;
                $replacements[$old] = $new;
            }
        }
        uksort($replacements, fn ($left, $right) => strlen($right) <=> strlen($left));

        $staging = realpath($path).'/.restore-'.Str::uuid();
        $this->privateDirectory($staging);
        try {
            Process::path(base_path())->timeout(600)->run([
                'tar', '--extract', '--file', $path.'/projects.tar', '--directory', $staging,
                '--no-same-owner', '--keep-old-files',
            ])->throw();
            foreach ($data['directories'] as $old) {
                $preparedFiles = [
                    ...glob($staging.'/'.$old.'/artifacts/*/recon/inputs/*.json'),
                    ...glob($staging.'/'.$old.'/artifacts/*/recon/output/outcome.json'),
                    ...glob($staging.'/'.$old.'/runs/*/inputs/*.json'),
                    ...glob($staging.'/'.$old.'/runs/*/output/outcome.json'),
                ];
                foreach ($preparedFiles as $file) {
                    $inputs = json_decode(File::get($file), true, 512, JSON_THROW_ON_ERROR);
                    $mapped = $this->remapEnvelope($inputs, $ids, $replacements);
                    if ($mapped !== $inputs) {
                        File::put($file, json_encode($mapped, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
                    }
                }
                foreach ($preparedFiles as $file) {
                    if (basename($file) !== 'run.json') {
                        continue;
                    }
                    $inputs = json_decode(File::get($file), true, 512, JSON_THROW_ON_ERROR);
                    $reconFile = isset($inputs['recon']['path']) ? dirname($file).'/'.basename($inputs['recon']['path']) : null;
                    if ($reconFile && is_file($reconFile)) {
                        $inputs['recon']['sha256'] = hash_file('sha256', $reconFile);
                        File::put($file, json_encode($inputs, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
                    }
                }
            }
            foreach ($folders as $table => $folder) {
                foreach ($data['tables'][$table] as $row) {
                    $oldProject = $row['project_id'] ?? $sources[$row['project_source_id']]['project_id'];
                    $from = $staging.'/'.$oldProject.'/'.$folder.'/'.$row['id'];
                    $to = $staging.'/'.$oldProject.'/'.$folder.'/'.$ids[$table][(string) $row['id']];
                    if ($from !== $to && is_dir($from) && ! File::moveDirectory($from, $to)) {
                        throw new RuntimeException('Unable to remap restored storage identifiers.');
                    }
                }
            }
            foreach ($data['directories'] as $old) {
                $new = (string) $projectIds[$old];
                $target = $root.'/'.$new;
                if (file_exists($target) || is_link($target)) {
                    if (is_link($target) || ! is_dir($target) || $this->treeHash($target) !== $this->treeHash($staging.'/'.$old)) {
                        throw new RuntimeException('Existing project files differ from the snapshot: '.$target);
                    }
                } elseif (! File::moveDirectory($staging.'/'.$old, $target)) {
                    throw new RuntimeException('Unable to publish restored project files.');
                }
                $this->privateDirectory($target);
            }

            DB::transaction(function () use ($data, $ids, $replacements): void {
                foreach (self::TABLES as $table) {
                    if (! Schema::hasTable($table)) {
                        continue;
                    }
                    $columns = array_flip(Schema::getColumnListing($table));
                    foreach ($data['tables'][$table] as $original) {
                        $row = array_intersect_key($original, $columns);
                        if ($table === 'labs' && isset($columns['doctor_session_id']) && ! array_key_exists('doctor_session_id', $original)) {
                            $session = collect($data['tables']['agent_sessions'])
                                ->where('project_source_id', $original['project_source_id'])
                                ->where('agent', 'doctor')
                                ->where('status', $original['status'] === 'ready' ? 'ready' : 'failed')
                                ->sortByDesc('updated_at')
                                ->first();
                            if ($session) {
                                $row['doctor_session_id'] = $session['id'];
                                $row['environment'] = $session['environment'];
                                $row['verified_at'] = $original['status'] === 'ready' ? $session['updated_at'] : null;
                                $row['error'] = $original['status'] === 'ready' ? null : $session['failure_reason'];
                            }
                        }
                        if (isset($ids[$table])) {
                            $row['id'] = $ids[$table][(string) $original['id']];
                        }
                        foreach ([
                            'user_id' => 'users', 'project_id' => 'projects', 'project_source_id' => 'project_sources', 'lab_id' => 'labs',
                            'audit_run_id' => 'audit_runs', 'project_artifact_id' => 'project_artifacts', 'recon_artifact_id' => 'project_artifacts',
                            'recon_area_id' => 'recon_areas', 'audit_run_area_id' => 'audit_run_areas',
                        ] as $column => $parent) {
                            if (isset($row[$column])) {
                                $row[$column] = $ids[$parent][(string) $original[$column]];
                            }
                        }
                        if ($table === 'audit_run_areas' && isset($columns['area_key']) && ! isset($row['area_key'])) {
                            $reconArea = collect($data['tables']['recon_areas'])->firstWhere('id', $original['recon_area_id'] ?? null);
                            if (! $reconArea) {
                                throw new RuntimeException('An existing audit area has no recoverable area key.');
                            }
                            $row['area_key'] = $reconArea['area_key'];
                        }
                        foreach (['paths', 'lab_manifest', 'payload', 'metadata', 'artifacts', 'configs', 'result', 'outcome'] as $column) {
                            if (isset($row[$column])) {
                                $value = json_decode($row[$column], true, 512, JSON_THROW_ON_ERROR);
                                $previous = $value;
                                if ($column === 'paths') {
                                    array_walk_recursive($value, function (&$item) use ($replacements): void {
                                        if (is_string($item)) {
                                            $item = $this->remapPath($item, $replacements);
                                        }
                                    });
                                } elseif ($column === 'artifacts') {
                                    $value = $this->remapArtifacts($value, $ids);
                                } elseif ($column !== 'payload' || ($original['type'] ?? null) === 'recon') {
                                    $value = $this->remapEnvelope($value, $ids, $replacements);
                                }
                                $row[$column] = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                                if ($column === 'payload' && $value !== $previous && array_key_exists('content_hash', $row)) {
                                    $row['content_hash'] = hash('sha256', $row[$column]);
                                }
                            }
                        }
                        foreach (['report_path', 'storage_path'] as $column) {
                            if (isset($row[$column])) {
                                $row[$column] = $this->remapPath($row[$column], $replacements);
                            }
                        }
                        DB::table($table)->insert($row);
                    }
                    $this->resetSequence($table);
                }
            });
            $this->info('Development data restored.');
            foreach ($ids as $table => $map) {
                foreach ($map as $old => $new) {
                    if ((string) $old !== (string) $new) {
                        $this->line($table.' '.$old.' => '.$new);
                    }
                }
            }
        } finally {
            if (! str_starts_with((string) realpath($staging), realpath($path).'/.restore-')) {
                throw new RuntimeException('Unexpected snapshot staging path.');
            }
            File::deleteDirectory($staging);
        }
    }

    private function assertIdle(): void
    {
        if (Schema::hasTable('jobs') && DB::table('jobs')->exists()) {
            throw new RuntimeException('Finish queued jobs before creating or restoring a snapshot.');
        }
        foreach (['agent_sessions', 'project_artifacts', 'labs', 'audit_runs'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'status')
                && DB::table($table)->where('status', 'running')->exists()) {
                throw new RuntimeException('Finish running preparations or audits before using snapshots.');
            }
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'runtime_config')
                && DB::table($table)->whereNotNull('runtime_config')->exists()) {
                throw new RuntimeException('Stop retained sandbox stacks before using snapshots.');
            }
        }
    }

    private function integerKey(string $table): bool
    {
        return str_contains(strtolower(Schema::getColumnType($table, 'id')), 'int');
    }

    private function remapPath(string $value, array $replacements): string
    {
        foreach ($replacements as $old => $new) {
            if ($value === rtrim($old, '/')) {
                return rtrim($new, '/');
            }
            if (str_starts_with($value, $old)) {
                return $new.substr($value, strlen($old));
            }
        }

        return $value;
    }

    private function remapArtifacts(array $value, array $ids): array
    {
        foreach ($value as &$artifact) {
            if (is_int($artifact) || is_string($artifact)) {
                $artifact = $ids['project_artifacts'][(string) $artifact] ?? $artifact;
            }
        }
        unset($artifact);

        return $value;
    }

    private function remapEnvelope(array $value, array $ids, array $replacements): array
    {
        foreach (['project_id' => 'projects', 'source_id' => 'project_sources', 'project_source_id' => 'project_sources', 'lab_id' => 'labs'] as $column => $table) {
            if (isset($value[$column])) {
                $value[$column] = $ids[$table][(string) $value[$column]] ?? $value[$column];
            }
        }
        if (isset($value['source_host_path'])) {
            $value['source_host_path'] = $this->remapPath($value['source_host_path'], $replacements);
        }
        if (isset($value['input_artifacts'])) {
            $value['input_artifacts'] = $this->remapArtifacts($value['input_artifacts'], $ids);
        }
        foreach (['static_analysis', 'code_index', 'dependency_analysis', 'recon'] as $name) {
            if (isset($value[$name]) && is_array($value[$name])) {
                $value[$name] = $this->remapEnvelope($value[$name], $ids, $replacements);
                if (isset($value[$name]['id'])) {
                    $value[$name]['id'] = $ids['project_artifacts'][(string) $value[$name]['id']] ?? $value[$name]['id'];
                }
            }
        }

        return $value;
    }

    private function resetSequence(string $table): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! $this->integerKey($table)) {
            return;
        }
        $sequence = DB::selectOne("SELECT pg_get_serial_sequence(?, 'id') AS name", [$table])->name;
        if ($sequence) {
            $maximum = (int) DB::table($table)->max('id');
            DB::select('SELECT setval(?::regclass, ?, '.($maximum > 0 ? 'true' : 'false').')', [$sequence, max(1, $maximum)]);
        }
    }

    private function treeHash(string $root): string
    {
        $entries = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $file) {
            $relative = substr($file->getPathname(), strlen($root) + 1);
            $entries[$relative] = $file->isLink() ? ['link', readlink($file->getPathname())]
                : ($file->isDir() ? ['directory'] : ['file', hash_file('sha256', $file->getPathname())]);
        }
        ksort($entries, SORT_STRING);

        return hash('sha256', json_encode($entries, JSON_THROW_ON_ERROR));
    }

    private function privateDirectory(string $path): void
    {
        File::ensureDirectoryExists($path, 0700);
        if (! chmod($path, 0700) || ! is_writable($path)) {
            throw new RuntimeException('Snapshot directories must be private and writable.');
        }
    }

    private function assertAbsolute(string $path): void
    {
        if (! str_starts_with($path, '/') || str_contains($path, "\0")) {
            throw new RuntimeException('Use an absolute Linux path from WSL.');
        }
    }
}
