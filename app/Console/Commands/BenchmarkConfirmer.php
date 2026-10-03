<?php

namespace App\Console\Commands;

use App\Models\BenchmarkStageArtifact;
use App\Services\Audit\RunStorage;
use App\Services\Pentest\BenchmarkAuditSource;
use App\Services\Pentest\BenchmarkCatalog;
use App\Services\Pentest\BenchmarkDeduperArtifacts;
use App\Services\Pentest\BenchmarkManifest;
use App\Services\Pentest\BenchmarkStageArtifactRegistry;
use App\Services\Pentest\BenchmarkStageSubjectSelector;
use App\Services\Pentest\BenchmarkTechnicalFailure;
use App\Services\Pentest\ConfirmerBenchmarkDataset;
use App\Services\Pentest\ConfirmerBenchmarkOracle;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

final class BenchmarkConfirmer extends Command
{
    protected $signature = 'benchmark:confirmer
        {target-id : Project key del target benchmark}
        {--artifact=* : ReaderLead artifact; override della selezione automatica}
        {--dedup-artifact= : Usa esclusivamente le lead canoniche del dedup.json scoped}
        {--dedup-run= : Completed isolated deduper execution; select final DB canonicals only}
        {--canonical-id=* : Canonical lead IDs from --dedup-run to confirm; repeatable}
        {--path= : Path target; default targets/<target-id>}
        {--category= : Limita alla benchmark category}
        {--confirmer-model= : Modello Confirmer}
        {--dataset= : Dataset congelato; se assente usa gli artifact validi dal DB}
        {--envelope-points=350000 : Hard cap per singola decisione}
        {--repetitions=1 : Ripetizioni per lead, da 1 a 20}
        {--preflight-only : Validate the selected canonical leads without starting Confirmer}
        {--tool-output : Mostra output tool compatti}
        {--test : Ricostruisce immagine agente}';

    protected $description = 'Valuta Confirmer su ReaderLead frozen o valide nel DB, senza Worker';

    public function handle(
        BenchmarkCatalog $catalog,
        BenchmarkAuditSource $auditSource,
        BenchmarkStageArtifactRegistry $registry,
        BenchmarkStageSubjectSelector $subjectSelector,
        BenchmarkDeduperArtifacts $deduperArtifacts,
        BenchmarkTechnicalFailure $technicalFailure,
        ConfirmerBenchmarkOracle $oracle,
        ConfirmerBenchmarkDataset $dataset,
        RunStorage $storage,
    ): int {
        $projectKey = (string) $this->argument('target-id');
        $canonicalIds = array_values((array) $this->option('canonical-id'));
        if ($canonicalIds !== [] && ! $this->option('dedup-run')) {
            throw new InvalidArgumentException('--canonical-id requires --dedup-run.');
        }
        if (count($canonicalIds) !== count(array_unique($canonicalIds))) {
            throw new InvalidArgumentException('--canonical-id contains duplicates.');
        }
        if ($this->option('dedup-run') && ($this->option('artifact') !== [] || $this->option('dataset') || $this->option('dedup-artifact'))) {
            throw new InvalidArgumentException('--dedup-run incompatible with --artifact, --dataset and --dedup-artifact.');
        }
        $source = $this->option('path') ?: base_path('targets/'.$projectKey);
        $resolved = realpath((string) $source);
        if ($resolved === false || ! is_dir($resolved)) {
            throw new InvalidArgumentException("Path target inesistente: {$source}");
        }
        $source = str_replace('\\', '/', $resolved);
        $descriptor = $catalog->descriptor($projectKey);
        if ($descriptor === null) {
            throw new InvalidArgumentException("Descriptor benchmark assente: {$projectKey}");
        }
        $envelope = $this->option('envelope-points');
        if (! is_numeric($envelope) || (float) $envelope <= 0) {
            throw new InvalidArgumentException('--envelope-points deve essere positivo.');
        }
        $manifests = $catalog->forTarget($projectKey);
        $artifacts = $this->option('dedup-run')
            ? $deduperArtifacts->promoted($projectKey, (string) $this->option('dedup-run'))
            : $this->artifacts($subjectSelector, $projectKey);
        if ($canonicalIds !== []) {
            $availableIds = $artifacts->map(fn ($row): string => (string) data_get($row->metrics, 'canonical_id'))->all();
            $missingIds = array_diff($canonicalIds, $availableIds);
            if ($missingIds !== []) {
                throw new InvalidArgumentException('Unknown canonical IDs for this dedup run: '.implode(', ', $missingIds));
            }
            $selected = array_fill_keys($canonicalIds, true);
            $artifacts = $artifacts->filter(fn ($row): bool => isset($selected[(string) data_get($row->metrics, 'canonical_id')]));
            $this->line(sprintf('Selected %d canonical leads from dedup run %s.', $artifacts->count(), $this->option('dedup-run')));
        }
        if ($this->option('dedup-run')) {
            $snapshots = array_unique(array_map(fn ($manifest) => data_get($manifest->data, 'source.commit') ?: data_get($manifest->data, 'source.snapshot'), $manifests));
            foreach ($artifacts as $artifact) {
                if (! in_array($artifact->source_commit, $snapshots, true)) {
                    throw new InvalidArgumentException('Deduper source snapshot incompatible with Confirmer catalog.');
                }
                // Route global Reader leads using their own category, never the first manifest.
                $artifact->category = $this->handoffCategory((array) data_get($artifact->payload, 'output', []), $manifests, $artifact->category);
            }
            if ($this->option('category')) {
                $artifacts = $artifacts->filter(fn ($row) => $row->category === strtolower((string) $this->option('category')));
            }
        }
        if ($this->option('dedup-artifact')) {
            $dedup = json_decode(File::get((string) $this->option('dedup-artifact')), true, flags: JSON_THROW_ON_ERROR);
            $snapshots = array_unique(array_map(fn ($manifest): string => (string) (data_get($manifest->data, 'source.commit') ?: data_get($manifest->data, 'source.snapshot')), $catalog->forTarget($projectKey)));
            if (($dedup['project'] ?? null) !== $projectKey || ! in_array($dedup['snapshot'] ?? null, $snapshots, true)) {
                throw new InvalidArgumentException('Dedup project/snapshot incompatible with Confirmer catalog.');
            }
            $artifacts = new Collection;
            $complete = BenchmarkDeduperArtifacts::ledgerComplete($dedup);
            if (! $complete) {
                throw new InvalidArgumentException('Dedup incomplete or legacy contract: no Confirmer handoff.');
            }
            foreach ($dedup['canonical_ids'] as $canonicalId) {
                $product = $dedup['products'][$canonicalId];
                if ($product['kind'] !== 'lead' || ($product['adjudication_status'] ?? null) !== 'completed') {
                    continue;
                }
                $category = $this->handoffCategory($product['payload'], $manifests);
                $refs = [];
                foreach (($product['source_refs'] ?? []) as $id => $ref) {
                    $refs[] = [...$ref, 'source_ref_id' => $id];
                }
                $signature = hash('sha256', json_encode([$canonicalId, $product, $dedup['config']], JSON_THROW_ON_ERROR));
                $artifact = BenchmarkStageArtifact::query()->where('configuration_signature', $signature)->first();
                $artifact ??= $registry->record([
                    'project_key' => $projectKey, 'category' => $category, 'source_commit' => $dedup['snapshot'],
                    'role' => 'reader', 'output_type' => 'ReaderLead', 'status' => 'valid',
                    'model' => data_get($product, 'raw_output.usage.model'), 'run_id' => $product['origin_run_id'] ?? null,
                    'configuration_signature' => $signature, 'payload' => ['output' => $product['payload'], 'source_refs' => $refs],
                    'metrics' => ['dedup_complete' => $complete, 'canonical_id' => $canonicalId, 'original_ids' => $product['original_ids'] ?? [$canonicalId]],
                ]);
                $artifacts->push($artifact);
            }
        }
        if ($artifacts->isEmpty()) {
            $this->warn('Nessuna ReaderLead valida per i filtri richiesti.');

            return self::SUCCESS;
        }
        if ($this->option('preflight-only')) {
            $this->line(json_encode(['count' => $artifacts->count(),
                'canonical_ids' => $artifacts->map(fn ($row) => data_get($row->metrics, 'canonical_id'))->values()->all(),
                'categories' => $artifacts->countBy('category')->all(),
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }
        $repetitions = max(1, min(20, (int) $this->option('repetitions')));
        $failed = false;
        foreach ($artifacts as $leadArtifact) {
            foreach (range(1, $repetitions) as $repetition) {
                $location = $storage->create($projectKey, ['confirmer-'.$leadArtifact->category]);
                $runId = $location['run_id'];
                $directory = $location['directory'];
                $workDirectory = storage_path("framework/lailaps-confirmer/{$runId}");
                $agentSource = $auditSource->materialize(
                    $source,
                    $workDirectory.'/source',
                    $projectKey,
                    $descriptor,
                );
                File::ensureDirectoryExists($workDirectory);
                $subjectPath = $workDirectory.'/lead-subject.json';
                File::put($subjectPath, json_encode($dataset->modelSubject($leadArtifact), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

                $oldDirectory = getenv('LAILAPS_RUN_DIRECTORY');
                $oldRunId = getenv('LAILAPS_RUN_ID');
                $oldOutcome = getenv('LAILAPS_OUTCOME_FILE');
                $oldHarnessEnvironment = [];
                putenv('LAILAPS_RUN_DIRECTORY='.$directory);
                putenv('LAILAPS_RUN_ID='.$runId);
                putenv('LAILAPS_OUTCOME_FILE='.$storage->outcomePath($directory, $runId));
                foreach ($descriptor->harnessEnvironment() as $name => $value) {
                    $oldHarnessEnvironment[$name] = getenv($name);
                    putenv($name.'='.$value);
                }
                try {
                    $exit = Artisan::call('pentest:run', [
                        '--path' => $agentSource,
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
                        $oracle,
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
                        $summary['correct'] === null ? 'unscored' : ($summary['correct'] ? 'yes' : 'no'),
                        (float) ($summary['economic_points'] ?? 0),
                        $technical ? 'TECHNICAL FAILURE' : 'valid',
                    ));
                } finally {
                    File::deleteDirectory($workDirectory);
                    $this->restoreEnv('LAILAPS_RUN_DIRECTORY', $oldDirectory);
                    $this->restoreEnv('LAILAPS_RUN_ID', $oldRunId);
                    $this->restoreEnv('LAILAPS_OUTCOME_FILE', $oldOutcome);
                    foreach ($oldHarnessEnvironment as $name => $value) {
                        $this->restoreEnv($name, $value);
                    }
                }
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @return Collection<int, BenchmarkStageArtifact> */
    private function artifacts(BenchmarkStageSubjectSelector $selector, string $projectKey): Collection
    {
        $ids = array_values(array_filter((array) $this->option('artifact')));

        return $selector->select(
            projectKey: $projectKey,
            role: 'reader',
            outputType: 'ReaderLead',
            category: $this->option('category') ? (string) $this->option('category') : null,
            artifactIds: $ids,
            dataset: $this->option('dataset') ? (string) $this->option('dataset') : null,
            frozenLabels: ['benchmark_positive', 'novel_valid', 'false_positive'],
        );
    }

    /** @param list<BenchmarkManifest> $manifests */
    private function handoffCategory(array $payload, array $manifests, string $original = ''): string
    {
        $kind = strtolower(str_replace('-', '_', trim((string) ($payload['weakness_kind'] ?? ''))));
        $byKind = [
            'sql_injection' => 'injection', 'sql_injection_order_by' => 'injection',
            'second_order_sql_injection' => 'injection',
            'command_injection' => 'injection', 'os_command_injection' => 'injection',
            'second_order_command_injection' => 'injection', 'code_injection' => 'injection',
            'xss' => 'xss', 'stored_xss' => 'xss', 'reflected_xss' => 'xss',
            'xss_stored' => 'xss', 'xss_reflected' => 'xss',
            'xss_reflected_dom' => 'xss', 'xss_stored_svg' => 'xss',
            'stored_cross_site_scripting' => 'xss', 'reflected_cross_site_scripting' => 'xss',
            'broken_access_control' => 'broken-access-control',
            'authorization_bypass' => 'broken-access-control',
            'missing_authorization' => 'broken-access-control',
            'missing_object_level_authorization' => 'broken-access-control',
            'idor' => 'broken-access-control', 'auth_bypass' => 'broken-access-control',
            'authentication_bypass' => 'broken-access-control',
        ];
        $candidate = $byKind[$kind] ?? null;
        if ($candidate !== null && collect($manifests)->contains(fn ($manifest) => $manifest->benchmarkCategory() === $candidate)) {
            return $candidate;
        }
        $declared = strtolower(trim((string) ($payload['owasp_category'] ?? $original)));
        $matches = [];
        foreach ($manifests as $manifest) {
            $slug = strtolower((string) $manifest->benchmarkCategory());
            $owasp = strtolower($manifest->categoryId());
            if ($slug === '') {
                continue;
            }
            if ($declared === $slug || $original === $slug) {
                return $slug;
            }
            if (preg_match('/^'.preg_quote($owasp, '/').'(?=[:\s-]|$)/', $declared)) {
                $matches[] = $slug;
            }
        }
        if (count(array_unique($matches)) === 1) {
            return $matches[0];
        }
        $override = strtolower((string) $this->option('category'));
        if ($override !== '' && in_array($override, array_map(fn ($m) => strtolower($m->benchmarkCategory()), $manifests), true)) {
            return $override;
        }
        throw new InvalidArgumentException('Global lead category unavailable; supply a supported --category explicitly.');
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
        ConfirmerBenchmarkOracle $oracleEvaluator,
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
        $oracle = (array) data_get($leadArtifact->metrics, 'oracle', []);
        $decision = null;
        $correct = $oracle === [] ? null : false;
        foreach ($outputs as $output) {
            $decision = (string) ($output['output_type'] ?? 'unknown');
            $evaluation = $oracle === [] ? ['classification_correct' => null, 'quality_score' => null, 'scorable' => false]
                : $oracleEvaluator->evaluate($oracle, $decision, (array) ($output['payload'] ?? []));
            if ($technical && $oracle !== []) {
                $evaluation['classification_correct'] = false;
                $evaluation['quality_score'] = 0.0;
            }
            $correct = $evaluation['classification_correct'] === null ? null : (bool) $evaluation['classification_correct'];
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
                    ...$evaluation,
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
                'metrics' => [
                    'expected_decision' => data_get($oracle, 'expected_decision'),
                    'classification_correct' => $oracle === [] ? null : false,
                    'quality_score' => $oracle === [] ? null : 0.0,
                    'scorable' => $oracle !== [],
                ],
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
