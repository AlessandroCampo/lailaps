<?php

namespace App\Console\Commands;

use App\Models\BenchmarkStageArtifact;
use App\Services\Audit\RunStorage;
use App\Services\Pentest\BenchmarkCatalog;
use App\Services\Pentest\BenchmarkManifest;
use App\Services\Pentest\BenchmarkStageArtifactRegistry;
use App\Services\Pentest\BenchmarkTechnicalFailure;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

final class BenchmarkConfirmer extends Command
{
    protected $signature = 'benchmark:confirmer
        {target-id : Project key del target benchmark}
        {--artifact=* : ReaderLead artifact; default tutte le lead canoniche classificate}
        {--path= : Path target; default targets/<target-id>}
        {--category= : Limita alla benchmark category}
        {--confirmer-model= : Modello Confirmer}
        {--envelope-points=350000 : Hard cap per singola decisione}
        {--repetitions=3 : Ripetizioni per lead, da 1 a 20}
        {--tool-output : Mostra output tool compatti}
        {--test : Ricostruisce immagine agente}';

    protected $description = 'Valuta Confirmer su ReaderLead project-scoped congelate, senza Worker';

    public function handle(
        BenchmarkCatalog $catalog,
        BenchmarkStageArtifactRegistry $registry,
        BenchmarkTechnicalFailure $technicalFailure,
        RunStorage $storage,
    ): int {
        $projectKey = (string) $this->argument('target-id');
        $source = $this->option('path') ?: base_path('targets/'.$projectKey);
        $resolved = realpath((string) $source);
        if ($resolved === false || ! is_dir($resolved)) {
            throw new InvalidArgumentException("Path target inesistente: {$source}");
        }
        $source = str_replace('\\', '/', $resolved);
        $envelope = $this->option('envelope-points');
        if (! is_numeric($envelope) || (float) $envelope <= 0) {
            throw new InvalidArgumentException('--envelope-points deve essere positivo.');
        }
        $artifacts = $this->artifacts($projectKey);
        if ($artifacts->isEmpty()) {
            $this->warn('Nessuna ReaderLead canonica e classificata da valutare.');

            return self::SUCCESS;
        }
        $manifests = $catalog->forTarget($projectKey);
        $repetitions = max(1, min(20, (int) $this->option('repetitions')));
        $failed = false;
        foreach ($artifacts as $leadArtifact) {
            foreach (range(1, $repetitions) as $repetition) {
                $location = $storage->create($projectKey, ['confirmer-'.$leadArtifact->category]);
                $runId = $location['run_id'];
                $directory = $location['directory'];
                $workDirectory = storage_path("framework/lailaps-confirmer/{$runId}");
                File::ensureDirectoryExists($workDirectory);
                $subjectPath = $workDirectory.'/lead-subject.json';
                File::put($subjectPath, json_encode([
                    'schema' => 'lailaps.benchmark-stage-input',
                    'version' => 1,
                    'role' => 'reader',
                    'artifact_id' => $leadArtifact->id,
                    'project_key' => $projectKey,
                    'category' => $this->auditCategory($manifests, $leadArtifact->category),
                    'source_commit' => $leadArtifact->source_commit,
                    'output' => (array) data_get($leadArtifact->payload, 'output', []),
                    'source_refs' => (array) data_get($leadArtifact->payload, 'source_refs', []),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

                $oldDirectory = getenv('LAILAPS_RUN_DIRECTORY');
                $oldRunId = getenv('LAILAPS_RUN_ID');
                $oldOutcome = getenv('LAILAPS_OUTCOME_FILE');
                putenv('LAILAPS_RUN_DIRECTORY='.$directory);
                putenv('LAILAPS_RUN_ID='.$runId);
                putenv('LAILAPS_OUTCOME_FILE='.$storage->outcomePath($directory, $runId));
                try {
                    $exit = Artisan::call('pentest:run', [
                        '--path' => $source,
                        '--audit-id' => $runId,
                        '--project-name' => $projectKey,
                        '--category' => [$this->auditCategory($manifests, $leadArtifact->category)],
                        '--lead-subject' => $subjectPath,
                        '--envelope-points' => $envelope,
                        '--confirmer-model' => $this->option('confirmer-model'),
                        '--tool-output' => (bool) $this->option('tool-output'),
                        '--keep' => false,
                        '--test' => (bool) $this->option('test'),
                        '--assume-authorized' => true,
                    ], $this->output);
                    $outcome = $storage->outcome($directory, $runId);
                    $report = is_array($outcome['report'] ?? null) ? $outcome['report'] : [];
                    $technical = $technicalFailure->failed($report, $exit);
                    $summary = $this->persistOutputs(
                        $registry,
                        $leadArtifact,
                        $report,
                        $projectKey,
                        $runId,
                        $repetition,
                        $technical,
                        $technicalFailure->summary($report, $exit),
                    );
                    $storage->writeOutcome($directory, $runId, $report, $summary);
                    $failed = $failed || $technical;
                    $this->line(sprintf(
                        '%s [%d/%d] decision=%s | correct=%s | punti=%.0f | %s',
                        $leadArtifact->id,
                        $repetition,
                        $repetitions,
                        $summary['decision'] ?? 'missing',
                        ($summary['correct'] ?? false) ? 'yes' : 'no',
                        (float) ($summary['economic_points'] ?? 0),
                        $technical ? 'TECHNICAL FAILURE' : 'valid',
                    ));
                } finally {
                    File::deleteDirectory($workDirectory);
                    $this->restoreEnv('LAILAPS_RUN_DIRECTORY', $oldDirectory);
                    $this->restoreEnv('LAILAPS_RUN_ID', $oldRunId);
                    $this->restoreEnv('LAILAPS_OUTCOME_FILE', $oldOutcome);
                }
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @return Collection<int, BenchmarkStageArtifact> */
    private function artifacts(string $projectKey): Collection
    {
        $ids = array_values(array_filter((array) $this->option('artifact')));
        $query = BenchmarkStageArtifact::query()
            ->where('project_key', $projectKey)
            ->where('role', 'reader')
            ->where('output_type', 'ReaderLead')
            ->where('accepted', true);
        if ($ids !== []) {
            $query->whereIn('id', $ids);
        } else {
            $query->where('is_canonical', true)
                ->whereIn('label', ['benchmark_positive', 'novel_valid', 'false_positive']);
        }
        if ($this->option('category')) {
            $query->where('category', strtolower((string) $this->option('category')));
        }

        return $query->orderBy('created_at')->get();
    }

    /** @param list<BenchmarkManifest> $manifests */
    private function auditCategory(array $manifests, string $benchmarkCategory): string
    {
        foreach ($manifests as $manifest) {
            if ($manifest->benchmarkCategory() === $benchmarkCategory
                || strtolower($manifest->categoryId()) === strtolower($benchmarkCategory)) {
                return $manifest->auditCategory();
            }
        }

        throw new InvalidArgumentException("Manifest non trovato per la categoria {$benchmarkCategory}.");
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function persistOutputs(
        BenchmarkStageArtifactRegistry $registry,
        BenchmarkStageArtifact $leadArtifact,
        array $report,
        string $projectKey,
        string $runId,
        int $repetition,
        bool $technical,
        ?string $technicalError,
    ): array {
        $outputs = collect((array) ($report['structured_outputs'] ?? []))
            ->filter(fn ($row): bool => is_array($row) && ($row['role'] ?? null) === 'confirmer')
            ->values();
        $expectedCandidate = in_array($leadArtifact->label, ['benchmark_positive', 'novel_valid'], true);
        $decision = null;
        $correct = false;
        foreach ($outputs as $output) {
            $decision = (string) ($output['output_type'] ?? 'unknown');
            $isCandidate = $decision === 'CandidateHandoff';
            $isClosure = $decision === 'LeadClosure';
            $correct = $expectedCandidate ? $isCandidate : $isClosure;
            $registry->record([
                'project_key' => $projectKey,
                'category' => $leadArtifact->category,
                'source_commit' => $leadArtifact->source_commit,
                'parent_artifact_id' => $leadArtifact->id,
                'run_id' => $runId,
                'repetition' => $repetition,
                'role' => 'confirmer',
                'output_type' => $decision,
                'model' => data_get($report, 'telemetry.role_usage.confirmer.model'),
                'status' => $technical ? 'technical_failure' : 'valid',
                'accepted' => (bool) ($output['accepted'] ?? true),
                'payload' => [
                    'output' => (array) ($output['payload'] ?? []),
                    'source_refs' => (array) ($output['source_refs'] ?? []),
                    'lead_id' => $output['lead_id'] ?? null,
                ],
                'usage' => (array) ($output['usage'] ?? []),
                'metrics' => [
                    'expected' => $expectedCandidate ? 'CandidateHandoff' : 'LeadClosure',
                    'correct' => $correct,
                    'score' => $correct ? 100.0 : 0.0,
                    'economic_points' => (float) data_get($output, 'usage.economic_points', 0),
                ],
                'label' => $correct ? $leadArtifact->label : 'unresolved',
                'matched_case_id' => $leadArtifact->matched_case_id,
                'label_set_version' => $leadArtifact->label_set_version,
                'technical_error' => $technicalError,
            ]);
        }
        if ($outputs->isEmpty()) {
            $registry->record([
                'project_key' => $projectKey,
                'category' => $leadArtifact->category,
                'source_commit' => $leadArtifact->source_commit,
                'parent_artifact_id' => $leadArtifact->id,
                'run_id' => $runId,
                'repetition' => $repetition,
                'role' => 'confirmer',
                'output_type' => 'ConfirmerRun',
                'status' => $technical ? 'technical_failure' : 'valid',
                'accepted' => false,
                'metrics' => ['expected' => $expectedCandidate ? 'CandidateHandoff' : 'LeadClosure', 'correct' => false],
                'technical_error' => $technicalError,
            ]);
        }
        $points = (float) data_get($report, 'telemetry.role_usage.confirmer.economic_points', 0);

        return [
            'schema' => 'lailaps.confirmer-stage-result',
            'version' => 1,
            'project_key' => $projectKey,
            'parent_artifact_id' => $leadArtifact->id,
            'status' => $technical ? 'technical_failure' : 'valid',
            'technical_failure' => $technical,
            'decision' => $decision,
            'correct' => $correct,
            'economic_points' => $points,
        ];
    }

    private function restoreEnv(string $name, string|false $value): void
    {
        putenv($value === false ? $name : $name.'='.$value);
    }
}
