<?php

namespace App\Console\Commands;

use App\Services\Audit\RunStorage;
use App\Services\Pentest\BenchmarkAuditSource;
use App\Services\Pentest\BenchmarkCatalog;
use App\Services\Pentest\BenchmarkEvaluator;
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

final class BenchmarkReconReader extends Command
{
    protected $signature = 'benchmark:recon-reader
        {target-id : Target benchmark con manifest dello stesso snapshot}
        {--path= : Sorgente; default targets/<target-id>}
        {--recon-model= : Modello Recon}
        {--reader-model= : Modello Reader}
        {--reviewer-model= : Reviewer operativo}
        {--reader-checkpoint-strategy=reviewer : reviewer oppure reader_checkpoint}
        {--budget-category= : Preset condiviso della discovery}
        {--reuse-sandbox= : Audit ID di una sandbox Lailaps pronta da collegare}
        {--repetitions=1 : Run congiunte indipendenti, da 1 a 20}
        {--timeout=3600 : Timeout processo agente, bootstrap CBM incluso}
        {--tool-output : Mostra output tool compatti}';

    protected $description = 'Testa Recon funzionale e Reader seriale insieme, senza avviare il downstream';

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
        if (! in_array($readerCheckpointStrategy, ['reviewer', 'reader_checkpoint'], true)) {
            throw new InvalidArgumentException(
                'reader-checkpoint-strategy deve essere reviewer oppure reader_checkpoint.'
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
                ];
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
                foreach (['recon-model', 'reader-model', 'reviewer-model', 'budget-category'] as $option) {
                    if ($this->option($option)) {
                        array_push($args, '--'.$option, (string) $this->option($option));
                    }
                }
                if ($this->option('tool-output')) {
                    $args[] = '--tool-output';
                }
                $this->line("[{$repetition}/{$repetitions}] {$runId}: Recon -> Reader, concorrenza 1");
                try {
                    $exit = $runner->run(
                        $agentSource, $directory, $runId, $args, [], $storage,
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
                    'target_id' => $target, 'source_snapshot' => $snapshot,
                    'repetition' => $repetition, 'reader_concurrency' => 1,
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
