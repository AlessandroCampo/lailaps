<?php

namespace App\Console\Commands;

use App\Services\Audit\RunStorage;
use App\Services\Pentest\BenchmarkAuditSource;
use App\Services\Pentest\BenchmarkCatalog;
use App\Services\Pentest\BenchmarkEvaluator;
use App\Services\Pentest\BenchmarkReconInput;
use App\Services\Pentest\BenchmarkStageProcessRunner;
use App\Services\Pentest\BenchmarkTechnicalFailure;
use App\Services\Sandbox\Audit\AuditProfile;
use App\Services\Sandbox\Audit\SandboxPreparationService;
use App\Services\Sandbox\DTO\SandboxSpecDTO;
use App\Services\Sandbox\SandboxService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class BenchmarkReconReader extends Command
{
    protected $signature = 'benchmark:recon-reader
        {target-id : Target benchmark con manifest dello stesso snapshot}
        {--path= : Sorgente; default targets/<target-id>}
        {--recon-model= : Modello Recon}
        {--reader-model= : Modello Reader}
        {--cloudflare-hunting : Knowledge Cloudflare adattata a Recon e Reader}
        {--reader-points= : Cap EP aggregato del solo Reader, checkpoint inclusi}
        {--reader-concurrency=1 : Reader concorrenti sul cap aggregato, da 1 a 4}
        {--recon-run= : Riusa Recon e briefing di una run recon-reader-global; salta Recon}
        {--reader-checkpoint-strategy=reader_checkpoint : Self-checkpoint Reader}
        {--budget-category= : Preset condiviso della discovery}
        {--reuse-sandbox= : Audit ID di una sandbox Lailaps pronta da collegare}
        {--repetitions=1 : Run congiunte indipendenti, da 1 a 20}
        {--timeout=3600 : Timeout processo agente, bootstrap CBM incluso}
        {--tool-output : Mostra output tool compatti}';

    protected $description = 'Testa Reader con cap condiviso e Recon nuova o riusata, senza downstream';

    public function handle(
        BenchmarkCatalog $catalog,
        BenchmarkAuditSource $auditSource,
        BenchmarkEvaluator $evaluator,
        BenchmarkStageProcessRunner $runner,
        BenchmarkTechnicalFailure $technicalFailure,
        RunStorage $storage,
        SandboxService $sandboxes,
        SandboxPreparationService $preparation,
    ): int {
        $readerPoints = $this->option('reader-points');
        $concurrency = filter_var($this->option('reader-concurrency'), FILTER_VALIDATE_INT);
        if ($concurrency === false || $concurrency < 1 || $concurrency > 4) {
            throw new InvalidArgumentException('reader-concurrency deve essere fra 1 e 4.');
        }
        if ($this->input->hasParameterOption('--recon-run') && ! $this->option('recon-run')) {
            throw new InvalidArgumentException('recon-run richiede un ID esplicito.');
        }
        if ($readerPoints !== null && (! is_numeric($readerPoints)
            || ! is_finite((float) $readerPoints) || (float) $readerPoints <= 0)) {
            throw new InvalidArgumentException('reader-points richiede EP positivi e finiti.');
        }
        $experiment = [];
        if ($this->option('cloudflare-hunting') || $readerPoints !== null
            || $this->option('recon-run') || $concurrency > 1) {
            $git = new Process(['git', 'rev-parse', 'HEAD'], base_path());
            $git->run();
            $status = new Process(['git', 'status', '--porcelain', '--untracked-files=no'], base_path());
            $status->run();
            $experiment = [
                'cloudflare_hunting' => (bool) $this->option('cloudflare-hunting'),
                'reader_points_limit' => $readerPoints === null ? null : (float) $readerPoints,
                'reader_concurrency' => $concurrency,
                'engine_commit' => $git->isSuccessful() ? trim($git->getOutput()) : null,
                'engine_worktree_dirty' => $status->isSuccessful() ? trim($status->getOutput()) !== '' : null,
            ];
        }
        $target = (string) $this->argument('target-id');
        $manifests = $catalog->forTarget($target);
        if ($manifests === []) {
            throw new InvalidArgumentException('Il test congiunto richiede almeno un manifest.');
        }
        $identity = static fn ($manifest): string => (string) (data_get($manifest->data, 'source.commit')
            ?: data_get($manifest->data, 'source.snapshot'));
        $snapshot = $identity($manifests[0]);
        foreach ($manifests as $manifest) {
            if ($manifest->targetId() !== $target || $identity($manifest) !== $snapshot) {
                throw new InvalidArgumentException('I manifest devono riferirsi allo stesso target e snapshot.');
            }
        }
        $reconInput = $this->option('recon-run')
            ? app(BenchmarkReconInput::class)->resolveReaderRun($target, $snapshot, (string) $this->option('recon-run'))
            : null;
        $source = (string) ($this->option('path') ?: base_path('targets/'.$target));
        if (! preg_match('#^(?:[A-Za-z]:/|/)#', str_replace('\\', '/', $source))) {
            $source = base_path($source);
        }
        $source = realpath($source);
        if ($source === false || ! is_dir($source)) {
            throw new InvalidArgumentException('Directory sorgente inesistente.');
        }
        $timeout = (int) $this->option('timeout');
        $repetitions = (int) $this->option('repetitions');
        if ($timeout <= 0 || $repetitions < 1 || $repetitions > 20) {
            throw new InvalidArgumentException('Timeout positivo e repetitions fra 1 e 20 richiesti.');
        }
        $readerCheckpointStrategy = (string) $this->option('reader-checkpoint-strategy');
        if (! in_array($readerCheckpointStrategy, ['reader_checkpoint'], true)) {
            throw new InvalidArgumentException(
                'reader-checkpoint-strategy deve essere reader_checkpoint.'
            );
        }

        $failed = false;
        foreach (range(1, $repetitions) as $repetition) {
            $location = $storage->create($target, ['recon-reader-global']);
            $runId = $location['run_id'];
            $directory = $location['directory'];
            $workDirectory = storage_path("framework/lailaps-recon-reader/{$runId}");
            $started = microtime(true);
            $sandbox = null;
            try {
                if ($experiment !== []) {
                    $storage->writeOutcome($directory, $runId, [
                        'publication_state' => 'partial',
                        'recon_reader_experiment' => [...$experiment, 'source_snapshot' => $snapshot],
                    ], null);
                }
                $agentSource = $auditSource->materialize(
                    $source, $workDirectory.'/source', $target, $catalog->descriptor($target),
                );
                // Ground truth stays in Laravel. Only the sanitized source reaches the agent.
                $args = [
                    'recon', '--global', '--with-reader',
                    '--source-root', $runner->containerized() ? '/workspace' : $agentSource,
                    '--audit-id', $runId,
                    '--workspace-host-root', $agentSource,
                    '--reader-checkpoint-strategy', $readerCheckpointStrategy,
                    '--reader-concurrency', (string) $concurrency,
                ];
                $mounts = [];
                if ($reconInput !== null) {
                    $fixture = $workDirectory.'/recon-fixture.json';
                    File::put($fixture, json_encode($reconInput, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                    $mounts[$fixture] = '/recon-fixture.json';
                    array_push($args, '--recon-fixture', $runner->containerized() ? '/recon-fixture.json' : $fixture);
                }
                if ($this->option('reuse-sandbox')) {
                    $profile = AuditProfile::fromProject($agentSource);
                    $sandbox = $sandboxes->reuse(
                        (string) $this->option('reuse-sandbox'),
                        new SandboxSpecDTO(
                            auditId: $runId,
                            projectPath: $agentSource,
                            webService: $profile->service,
                            basePath: $profile->basePath,
                            healthPath: $profile->healthPath,
                            benchmarkTargetId: $target,
                            sourceSnapshot: $snapshot,
                        ),
                    );
                    $preparation->verifyReadiness($sandbox, $profile);
                    array_push(
                        $args,
                        '--target-audit-id', $sandbox->auditId,
                        '--target-container-id', $sandbox->containerId,
                    );
                }
                foreach (['recon-model', 'reader-model', 'budget-category', 'reader-points'] as $option) {
                    if ($this->option($option)) {
                        array_push($args, '--'.$option, (string) $this->option($option));
                    }
                }
                if ($this->option('cloudflare-hunting')) {
                    $args[] = '--cloudflare-hunting';
                }
                if ($this->option('tool-output')) {
                    $args[] = '--tool-output';
                }
                $step = $reconInput === null ? 'Recon -> Reader' : 'Reader-only, Recon riusata';
                $this->line("[{$repetition}/{$repetitions}] {$runId}: {$step}, concorrenza {$concurrency}");
                try {
                    $exit = $runner->run(
                        $agentSource, $directory, $runId, $args, $mounts, $storage,
                        function (string $type, string $buffer) use ($storage, $directory, $runId): void {
                            $this->output->write($buffer);
                            $storage->appendLog($directory, $runId, $buffer);
                        },
                        $timeout,
                    );
                } catch (ProcessTimedOutException) {
                    $exit = 124;
                    $this->warn('Timeout: conservati gli output parziali gia acquisiti.');
                }
                $outcome = $storage->outcome($directory, $runId);
                $report = (array) ($outcome['report'] ?? []);
                $technical = $report === [] || $technicalFailure->failed($report, $exit);
                $failed = $failed || $technical;
                // The old evaluator remains a diagnostic, not a semantic packet judge.
                // Recon reads must never become fallback Reader coverage.
                $readerReport = $report;
                $readerReport['files_seen'] = array_values(array_unique(array_column(array_filter(
                    (array) ($report['source_observations'] ?? []),
                    fn (array $row): bool => ($row['role'] ?? null) === 'reader',
                ), 'file')));
                $diagnostics = [];
                foreach ($manifests as $manifest) {
                    $diagnostics[] = $evaluator->evaluate(
                        $directory, $manifest, $source,
                        ['run_state' => $technical ? 'failed' : 'completed'], $readerReport,
                    );
                }
                $storage->writeOutcome($directory, $runId, $report, [
                    'schema' => 'lailaps.recon-reader-benchmark', 'version' => 1,
                    'strategy' => 'functional_assignments_v1',
                    'reader_checkpoint_strategy' => $readerCheckpointStrategy,
                    ...($experiment === [] ? [] : [
                        'recon_reader_experiment' => $report['recon_reader_experiment'] ?? $experiment,
                    ]),
                    'target_id' => $target, 'source_snapshot' => $snapshot,
                    'repetition' => $repetition, 'reader_concurrency' => $concurrency,
                    ...($reconInput === null ? [] : ['recon_reuse' => $reconInput['origin']]),
                    'status' => $technical ? 'technical_failure' : 'pending_semantic_review',
                    'target_runtime' => $sandbox === null ? 'workspace_only' : 'reused_sandbox',
                    'target_audit_id' => $sandbox?->auditId,
                    'exit_code' => $exit,
                    'wall_duration_seconds' => round(microtime(true) - $started, 3),
                    'economic_points' => data_get($report, 'telemetry.economic_points_used', 0),
                    'diagnostics_by_manifest' => $diagnostics,
                    'evaluation_note' => 'Match automatici diagnostici; revisionare le domande delle lead '
                        .'e contare casi distinti. Nessun punteggio Recon isolato o conferma statica. '
                        .'I costi nei diagnostici si riferiscono alla stessa run e non vanno sommati.',
                ]);
                $this->line('Outcome: '.$storage->outcomePath($directory, $runId));
            } finally {
                if (is_dir($workDirectory)) {
                    File::deleteDirectory($workDirectory);
                }
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
