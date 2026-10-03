<?php

namespace App\Console\Commands;

use App\Models\BenchmarkStageArtifact;
use App\Services\Audit\RunStorage;
use App\Services\Pentest\BenchmarkDeduperArtifacts;
use App\Services\Pentest\BenchmarkStageArtifactRegistry;
use App\Services\Pentest\BenchmarkStageProcessRunner;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class BenchmarkDeduper extends Command
{
    private const EMPTY_USAGE = ['requests' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'cached_input_tokens' => 0,
        'economic_points' => 0, 'unknown_reserved_points' => 0, 'usd_estimated' => 0, 'usd_observed' => 0,
        'unknown_reserved_usd' => 0,
        'usage_complete' => true, 'usd_complete' => true, 'attempts' => []];

    protected $signature = 'benchmark:deduper
        {target-id : Project key}
        {reader-run-id : ReaderRun or ReaderGlobalRun persisted run ID}
        {--roles-config= : Shared role configuration; only deduper is used}
        {--deduper-model= : Default z-ai/glm-5.3-flash}
        {--deduper-provider= : Default auto for new GLM 5.3 Flash executions; InferenceNet remains available}
        {--deduper-points= : Explicit total EP for a new execution}
        {--dedup-run= : Existing deduper execution ID, preserving input and budget}
        {--resume-failed : Retry technical failures/pending, preserving semantic uncertainty}
        {--preflight-only : Freeze and size the entire lead corpus, zero inference}
        {--result-json= : Write execution ID and status to this JSON path}
        {--image= : Separate semantic recovery image; does not change active runs}';

    protected $description = 'Test isolated deduper on DB ReaderLead; persist decisions and final canonicals';

    public function handle(BenchmarkDeduperArtifacts $artifacts, BenchmarkStageArtifactRegistry $registry,
        BenchmarkStageProcessRunner $runner, RunStorage $storage): int
    {
        $execution = null;
        $lock = null;
        $ownsLock = false;
        $oldImage = config('pentest.agent.image');
        try {
            $project = (string) $this->argument('target-id');
            $readerRunId = (string) $this->argument('reader-run-id');
            $existingId = (string) $this->option('dedup-run');
            if ($this->option('resume-failed') && $existingId === '') {
                throw new InvalidArgumentException('--resume-failed requires --dedup-run.');
            }
            $config = $this->configuration();
            if ($existingId !== '') {
                $execution = BenchmarkStageArtifact::query()->where('project_key', $project)->where('run_id', $existingId)
                    ->where('role', 'deduper')->where('output_type', 'DeduperRun')->sole();
                if (data_get($execution->payload, 'reader_run_id') !== $readerRunId) {
                    throw new InvalidArgumentException('Deduper run belongs to a different Reader run.');
                }
                $artifacts->verifyFrozen($execution);
                $directory = data_get($execution->metrics, 'directory');
                if (! is_string($directory) || ! is_dir($directory)) {
                    throw new InvalidArgumentException('Deduper ledger directory unavailable; cannot reset its budget.');
                }
                if ($config !== null) {
                    $stored = data_get($execution->payload, 'config');
                    foreach ($config['deduper'] as $field => $value) {
                        if (! array_key_exists($field, $stored['deduper']) || $stored['deduper'][$field] != $value) {
                            throw new InvalidArgumentException('Resume role configuration incompatible; no refunding.');
                        }
                    }
                    foreach (['project' => $project, 'snapshot' => $execution->source_commit] as $field => $value) {
                        if (isset($config[$field]) && $config[$field] !== $value) {
                            throw new InvalidArgumentException('Resume configuration scope incompatible.');
                        }
                    }
                }
            } else {
                if ($config === null) {
                    throw new InvalidArgumentException('New execution requires --roles-config or --deduper-points.');
                }
                $manifest = $artifacts->corpus($project, $readerRunId);
                foreach (['project' => $project, 'snapshot' => $manifest['input']['snapshot']] as $field => $value) {
                    if (isset($config[$field]) && $config[$field] !== $value) {
                        throw new InvalidArgumentException('Role configuration scope incompatible.');
                    }
                }
                $manifest['config'] = ['deduper' => $config['deduper']];
                $newId = 'deduper-'.strtolower((string) Str::ulid());
                $location = ['run_id' => $newId, 'directory' => $storage->forId($project, ['deduper'], $newId)];
                $directory = $location['directory'];
                $this->write($directory.'/frozen-input.json', $manifest['input']);
                $manifest['input_hash'] = BenchmarkDeduperArtifacts::hash($manifest['input']);
                $manifest['frozen_input_sha256'] = hash_file('sha256', $directory.'/frozen-input.json');
                unset($manifest['input']['items']);
                $manifest['manifest_hash'] = BenchmarkDeduperArtifacts::hash($manifest);
                $execution = $registry->record(['project_key' => $project, 'category' => 'global',
                    'source_commit' => $manifest['input']['snapshot'], 'parent_artifact_id' => $manifest['reader_parent_artifact_id'],
                    'run_id' => $location['run_id'], 'role' => 'deduper', 'output_type' => 'DeduperRun',
                    'status' => 'pending', 'accepted' => false, 'is_canonical' => false,
                    'usage' => self::EMPTY_USAGE,
                    'model' => $config['deduper']['model'], 'reasoning_effort' => 'low', 'payload' => $manifest,
                    'metrics' => ['directory' => $directory, 'complete' => false, 'states' => ['pending' => count($manifest['origins'])],
                        'funding_basis' => 'explicit_new_deduper_envelope; Reader_costs_unchanged',
                        'runtime_image' => $this->option('image') ?: 'lailaps-pentest-agent:deduper-details-p0-20261001-v3']]);
                $artifacts->pending($execution);
            }
            $lock = fopen($directory.'/invocation.lock', 'c');
            if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
                throw new InvalidArgumentException('Another invocation owns this deduper execution.');
            }
            $ownsLock = true;
            $this->line('Deduper execution: '.$execution->run_id.' | Reader: '.$readerRunId.' | leads: '.count($execution->payload['origins'])
                .' | envelope EP: '.data_get($execution->payload, 'config.deduper.total_points'));
            config()->set('pentest.agent.image', $this->option('image') ?: data_get($execution->metrics, 'runtime_image'));
            // Mounted only to satisfy the process runner; deduper has no repository tool.
            $input = $artifacts->input($execution);
            foreach ($input['items'] as &$item) {
                $item['source_refs'] = (object) $item['source_refs'];
            }
            unset($item);
            $this->write($directory.'/dedup-input.json', $input);
            $this->write($directory.'/dedup-roles.json', $execution->payload['config']);
            $container = $runner->containerized();
            $args = ['reader-normalize', '--input', $container ? '/artifacts/dedup-input.json' : $directory.'/dedup-input.json',
                '--roles-config', $container ? '/artifacts/dedup-roles.json' : $directory.'/dedup-roles.json',
                '--output', $container ? '/artifacts' : $directory];
            // First reconcile a durable ledger whose previous DB import was interrupted.
            $this->reconcile($execution, $artifacts);
            $preflightDirectory = $directory.'/preflight';
            File::ensureDirectoryExists($preflightDirectory);
            $preflightArgs = $args;
            $preflightArgs[array_search('--output', $args, true) + 1] = $container ? '/artifacts/preflight' : $preflightDirectory;
            $code = $runner->run($directory, $directory, $execution->run_id, [...$preflightArgs, '--preflight-only'], [], $storage,
                fn ($type, $buffer) => $this->output->write($buffer), offline: true);
            $phase = $this->phase($preflightDirectory, $code);
            $preflight = json_decode(File::get($preflightDirectory.'/preflight.json'), true, flags: JSON_THROW_ON_ERROR);
            if (! isset($preflight['role_config'], $preflight['prompt_hash'], $preflight['schema_hashes']['lead'])
                || ! BenchmarkDeduperArtifacts::supportedContract($preflight['version'] ?? null, $preflight['card_version'] ?? null)) {
                throw new InvalidArgumentException('Preflight runtime incompatible; rebuild the recovery image.');
            }
            $contract = array_intersect_key($preflight, array_flip(['version', 'card_version', 'prompt_hash', 'schema_hashes']));
            if (data_get($execution->metrics, 'contract') && BenchmarkDeduperArtifacts::hash($contract) !== BenchmarkDeduperArtifacts::hash($execution->metrics['contract'])) {
                throw new InvalidArgumentException('Runtime contract changed; use a new execution, not the old cache.');
            }
            $manifest = $execution->payload;
            $manifest['config']['deduper'] = $preflight['role_config'];
            $manifest['manifest_hash'] = BenchmarkDeduperArtifacts::hash(array_diff_key($manifest, ['manifest_hash' => true]));
            $execution->update(['payload' => $manifest, 'content_hash' => BenchmarkDeduperArtifacts::hash($manifest),
                'usage' => $execution->usage ?? (is_file($directory.'/dedup.json') ? [] : self::EMPTY_USAGE),
                'metrics' => [...(array) $execution->metrics, 'contract' => $contract, 'preflight' => $preflight]]);
            $this->write($directory.'/dedup-roles.json', $manifest['config']);
            $localContextLimit = $code === 2 && ($phase['stop_reason'] ?? null) === 'context_limit'
                && in_array($preflight['version'] ?? null, ['3.0.0', '3.0.1', '3.0.2'], true);
            if (($code !== 0 && ! $localContextLimit) || $this->option('preflight-only')) {
                if ($execution->status !== 'completed' || $code !== 0) {
                    $execution->update(['status' => $code === 1 ? 'fatal' : ($code === 0 ? 'preflight_complete' : 'incomplete'),
                        'metrics' => [...(array) $execution->metrics, 'complete' => false, 'phase' => $phase, 'stop_reason' => $phase['stop_reason'] ?? null]]);
                }
                return $code;
            }
            // Hide prior promotions while a replay may change their context.
            $execution->update(['status' => 'running', 'metrics' => [...(array) $execution->metrics, 'complete' => false]]);
            $execution->childArtifacts()->where('output_type', 'ReaderLead')->update(['accepted' => false]);
            if ($this->option('resume-failed')) {
                $args[] = '--resume-failed';
            }
            $progress = '';
            $code = $runner->run($directory, $directory, $execution->run_id, $args, [], $storage,
                function ($type, $buffer) use ($execution, $artifacts, &$progress): void {
                    $this->output->write($buffer);
                    // Python saves its ledger atomically before publishing progress.
                    $progress .= $buffer;
                    while (($newline = strpos($progress, "\n")) !== false) {
                        $line = substr($progress, 0, $newline);
                        $progress = substr($progress, $newline + 1);
                        if (str_contains($line, 'outcome=')) {
                            $this->reconcile($execution, $artifacts, false);
                        }
                    }
                    $progress = substr($progress, -8192);
                });
            $phase = $this->phase($directory, $code);
            $this->reconcile($execution, $artifacts, true, $phase);
            return $code;
        } catch (Throwable $error) {
            // QueryException embeds every binding, including whole evidence payloads.
            $message = $error instanceof QueryException ? 'Database error: '.($error->errorInfo[2] ?? $error->getCode()) : $error->getMessage();
            $message = substr($message, 0, 1000);
            $this->error($message);
            if ($execution && $ownsLock && isset($directory)) {
                try {
                    $this->reconcile($execution, $artifacts, false);
                } catch (Throwable $importError) {
                    $importMessage = $importError instanceof QueryException
                        ? 'Database error: '.($importError->errorInfo[2] ?? $importError->getCode()) : $importError->getMessage();
                    $this->error('Ledger retained; reconciliation failed: '.substr($importMessage, 0, 1000));
                }
                $execution->refresh()->update(['status' => 'fatal', 'technical_error' => $message,
                    'metrics' => [...(array) $execution->metrics, 'complete' => false, 'stop_reason' => 'fatal_input_or_process']]);
                $this->write($directory.'/phase-status.json', ['schema' => 'lailaps.phase-status', 'version' => 1,
                    'phase' => 'dedup', 'status' => 'fatal', 'complete' => false, 'exit_code' => 1, 'stop_reason' => 'fatal_input_or_process']);
            }
            return self::FAILURE;
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
            config()->set('pentest.agent.image', $oldImage);
            if ($execution) {
                $execution->refresh();
                $ledger = isset($directory) && is_file($directory.'/dedup.json')
                    ? json_decode(File::get($directory.'/dedup.json'), true) : null;
                $currentDecisions = array_values(array_filter((array) ($ledger['decisions'] ?? []),
                    fn ($row) => ($row['current'] ?? true) === true));
                $summary = ['execution_id' => $execution->run_id, 'status' => $execution->status,
                    'raw_leads' => count(data_get($execution->payload, 'origins', [])), 'verdicts' => data_get($execution->metrics, 'verdicts'),
                    'canonical_products' => data_get($execution->metrics, 'canonical_products'),
                    'promoted_leads' => $execution->childArtifacts()->where('output_type', 'ReaderLead')->where('accepted', true)->count(),
                    'peak_input_tokens' => data_get($execution->metrics, 'preflight.peak_estimated_input_tokens'),
                    'prudent_passes' => count(array_filter($currentDecisions,
                        fn ($row) => ($row['status'] ?? null) === 'completed'
                            && data_get($row, 'decision.decision') === 'pass'
                            && data_get($row, 'decision.uncertain') === true)),
                    'mean_input_tokens_per_verdict' => count($currentDecisions) > 0
                        ? round(array_sum(array_map(fn ($row) => (int) data_get($row, 'usage.input_tokens', 0), $currentDecisions)) / count($currentDecisions), 1)
                        : null,
                    'scope' => data_get($execution->payload, 'scope'), 'states' => data_get($execution->metrics, 'states'),
                    'stop_reason' => data_get($execution->metrics, 'stop_reason'), 'accounting' => array_intersect_key((array) $execution->usage,
                        array_flip(['requests', 'input_tokens', 'output_tokens', 'cached_input_tokens', 'economic_points', 'unknown_reserved_points',
                            'usd_estimated', 'usd_observed', 'unknown_reserved_usd', 'usage_complete', 'usd_complete',
                            'retry_count', 'repair_count', 'retrieval_count',
                            'seconds', 'decision_seconds', 'consecutive_technical_failures', 'circuit_open'])),
                    'semantic_ground_truth' => false];
                $this->line(json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                if ($this->option('result-json')) {
                    File::put((string) $this->option('result-json'), json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                }
                if ($ownsLock && isset($directory) && is_dir($directory)) {
                    $this->write($directory.'/db-summary.json', $summary);
                    File::put($directory.'/db-report.md', "# Isolated DB deduper\n\n```json\n".json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n```\n\nVerdicts are provisional model decisions, not ground truth.\n");
                }
            }
        }
    }

    private function configuration(): ?array
    {
        if ($this->option('roles-config')) {
            foreach (['model', 'provider', 'points'] as $field) {
                if ($this->option('deduper-'.$field) !== null) {
                    throw new InvalidArgumentException('--roles-config incompatible with individual deduper overrides.');
                }
            }
            $config = json_decode(File::get((string) $this->option('roles-config')), true, flags: JSON_THROW_ON_ERROR);
        } elseif ($this->option('deduper-points') !== null) {
            $config = ['deduper' => ['model' => $this->option('deduper-model') ?: 'z-ai/glm-5.3-flash',
                'provider' => $this->option('deduper-provider') ?: 'auto', 'total_points' => (float) $this->option('deduper-points'),
                'input_cap' => 85333, 'output_cap' => 16384]];
        } else {
            if ($this->option('deduper-model') || $this->option('deduper-provider')) {
                throw new InvalidArgumentException('Role overrides require explicit --deduper-points.');
            }
            return null;
        }
        if (data_get($config, 'deduper.model') !== 'z-ai/glm-5.3-flash' || ! in_array(data_get($config, 'deduper.provider'), ['auto', 'InferenceNet'], true)
            || ! is_numeric(data_get($config, 'deduper.total_points')) || (float) data_get($config, 'deduper.total_points') <= 0) {
            throw new InvalidArgumentException('Explicit positive budget and GLM 5.3 Flash / auto or InferenceNet required.');
        }
        return $config;
    }

    private function phase(string $directory, int $code): array
    {
        $phase = json_decode(File::get($directory.'/phase-status.json'), true, flags: JSON_THROW_ON_ERROR);
        if (! in_array($code, [0, 1, 2], true) || ($phase['exit_code'] ?? null) !== $code) {
            throw new InvalidArgumentException('Process exit code and phase status disagree.');
        }
        return $phase;
    }

    private function reconcile(BenchmarkStageArtifact $execution, BenchmarkDeduperArtifacts $artifacts, bool $final = true, ?array $phase = null): void
    {
        $directory = data_get($execution->metrics, 'directory');
        if (is_file($directory.'/dedup.json')) {
            if ($final && $phase === null && is_file($directory.'/phase-status.json')) {
                $saved = json_decode(File::get($directory.'/phase-status.json'), true, flags: JSON_THROW_ON_ERROR);
                $phase = ($saved['phase'] ?? null) === 'dedup' ? $saved : null;
            }
            $artifacts->sync($execution->refresh(), json_decode(File::get($directory.'/dedup.json'), true, flags: JSON_THROW_ON_ERROR), $phase);
            $execution->refresh();
        }
    }

    private function write(string $path, mixed $value): void
    {
        File::replace($path, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
