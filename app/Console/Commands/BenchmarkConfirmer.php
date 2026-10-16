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
        {--unified-lead : Verifica statica e dinamica nella stessa sessione}
        {--isolated-leads : Controllo unificato con sandbox separata per lead}
        {--lead-concurrency=2 : Investigatori concorrenti nel replay globale}
        {--lead-model= : Modello unico; default Worker configurato}
        {--replay-manifest= : Manifest evaluator congelato con subject ReaderLead separati}
        {--scope=* : Scope del replay manifest; ripetibile}
        {--runtime-preflight : Prepara il target e verifica fixture senza modelli}
        {--dataset= : Dataset congelato; se assente usa gli artifact validi dal DB}
        {--envelope-points= : Cap totale; default 350000 statico, 3000000 unificato}
        {--repetitions=1 : Ripetizioni per lead, da 1 a 20}
        {--preflight-only : Validate the selected canonical leads without starting Confirmer}
        {--tool-output : Mostra output tool compatti}
        {--test : Ricostruisce immagine agente}';

    protected $description = 'Valuta ReaderLead frozen o valide nel DB con Confirmer o investigatore unificato';

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
        $globalReplay = $this->option('unified-lead') && $this->option('replay-manifest') && ! $this->option('isolated-leads');
        if ($this->option('isolated-leads') && ! $this->option('unified-lead')) {
            throw new InvalidArgumentException('--isolated-leads richiede --unified-lead.');
        }
        if ($this->option('unified-lead')) {
            \App\Services\Pentest\GlobalPipelineOptions::validate($this->options());
        }
        $envelope = $this->option('envelope-points') ?: ($globalReplay ? 100000000 : ($this->option('unified-lead') ? 3000000 : 350000));
        if (! is_numeric($envelope) || ! is_finite((float) $envelope) || (float) $envelope <= 0) {
            throw new InvalidArgumentException('--envelope-points deve essere positivo.');
        }
        $manifests = $catalog->forTarget($projectKey);
        $artifacts = $this->option('replay-manifest')
            ? $this->replayArtifacts($registry, $projectKey, $source, (string) data_get($descriptor->data, 'source.commit'))
            : ($this->option('dedup-run')
            ? $deduperArtifacts->promoted($projectKey, (string) $this->option('dedup-run'))
            : $this->artifacts($subjectSelector, $projectKey));
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
        $context = $globalReplay ? $this->replayContext() : null;
        if ($this->option('preflight-only') && ! $this->option('runtime-preflight')) {
            $this->line(json_encode(['count' => $artifacts->count(),
                'canonical_ids' => $artifacts->map(fn ($row) => data_get($row->metrics, 'canonical_id'))->values()->all(),
                'categories' => $artifacts->countBy('category')->all(),
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }
        $repetitions = max(1, min(20, (int) $this->option('repetitions')));
        $failed = false;
        $batchStarted = microtime(true);
        $batchId = (string) \Illuminate\Support\Str::ulid();
        $groups = $globalReplay ? [$artifacts] : $artifacts->map(fn ($artifact) => new Collection([$artifact]));
        foreach ($groups as $leadGroup) {
            $leadArtifact = $leadGroup->first();
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
                $runtimeSource = $agentSource;
                $runtimeOverlay = dirname($descriptor->path).DIRECTORY_SEPARATOR.'runtime';
                if ($this->option('unified-lead') && is_dir($runtimeOverlay)) {
                    $runtimeSource = $workDirectory.'/runtime';
                    if (! File::copyDirectory($source, $runtimeSource) || ! File::copyDirectory($runtimeOverlay, $runtimeSource)) {
                        throw new InvalidArgumentException('Materializzazione runtime benchmark fallita.');
                    }
                }
                $runtimeCompose = collect(['compose.yml', 'compose.yaml', 'docker-compose.yml', 'docker-compose.yaml'])
                    ->first(fn ($name) => is_file($runtimeOverlay.'/'.$name) && is_file($runtimeSource.'/'.$name));
                $probePath = $workDirectory.'/fixture-probes.json';
                $fixtureProbes = collect($manifests)->flatMap(fn ($manifest) => $manifest->fixtureProbes())
                    ->unique(fn ($probe) => json_encode($probe))->values()->all();
                File::put($probePath, json_encode($fixtureProbes, JSON_THROW_ON_ERROR));
                $subjectPath = $workDirectory.'/lead-subject.json';
                $subject = $globalReplay ? [
                    'schema' => 'lailaps.global-lead-replay-input', 'version' => 1,
                    'project_key' => $projectKey, 'source_commit' => $descriptor->commit(),
                    'recon' => $context['recon'],
                    'subjects' => $leadGroup->map(fn ($artifact) => [
                        ...$dataset->modelSubject($artifact), 'replay_scope' => data_get($artifact->metrics, 'replay_scope'),
                    ])->values()->all(),
                ] : $dataset->modelSubject($leadArtifact);
                File::put($subjectPath, json_encode($subject, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

                $oldDirectory = getenv('LAILAPS_RUN_DIRECTORY');
                $oldRunId = getenv('LAILAPS_RUN_ID');
                $oldOutcome = getenv('LAILAPS_OUTCOME_FILE');
                $oldHarnessEnvironment = [];
                putenv('LAILAPS_RUN_DIRECTORY='.$directory);
                putenv('LAILAPS_RUN_ID='.$runId);
                putenv('LAILAPS_OUTCOME_FILE='.$storage->outcomePath($directory, $runId));
                foreach ($descriptor->harnessEnvironment($runtimeSource) as $name => $value) {
                    $oldHarnessEnvironment[$name] = getenv($name);
                    putenv($name.'='.$value);
                }
                try {
                    $exit = Artisan::call('pentest:run', [
                        '--path' => $agentSource,
                        '--runtime-path' => $runtimeSource,
                        ...($runtimeCompose ? ['--compose' => $runtimeSource.'/'.$runtimeCompose] : []),
                        '--fixture-probes' => $probePath,
                        '--benchmark-target-id' => $projectKey,
                        '--source-snapshot' => $descriptor->commit(),
                        '--audit-id' => $runId,
                        '--project-name' => $projectKey,
                        '--category' => [$this->auditCategory($manifests, $leadArtifact->category)],
                        '--lead-subject' => $subjectPath,
                        '--envelope-points' => $envelope,
                        '--confirmer-model' => $this->option('confirmer-model'),
                        '--unified-lead' => (bool) $this->option('unified-lead'),
                        '--lead-model' => $this->option('lead-model'),
                        '--lead-concurrency' => $this->option('lead-concurrency'),
                        '--preflight-only' => (bool) $this->option('runtime-preflight'),
                        '--tool-output' => (bool) $this->option('tool-output'),
                        '--keep' => false,
                        '--test' => (bool) $this->option('test'),
                        '--assume-authorized' => true,
                    ], $this->output);
                    if ($this->option('runtime-preflight')) {
                        $failed = $failed || $exit !== self::SUCCESS;
                        continue;
                    }
                    $outcome = $storage->outcome($directory, $runId);
                    $report = is_array($outcome['report'] ?? null) ? $outcome['report'] : [];
                    $technical = $technicalFailure->failed($report, $exit);
                    $summaries = [];
                    foreach ($leadGroup as $leadArtifact) {
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
                        $summary['batch_id'] = $batchId;
                        $summary['batch_elapsed_seconds'] = round(microtime(true) - $batchStarted, 3);
                        $summaries[] = $summary;
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
                    }
                    $storage->writeOutcome($directory, $runId, $report, $globalReplay ? [
                        'lead_policy' => 'unified-v1', 'replay_mode' => 'global_pipeline',
                        'batch_id' => $batchId, 'batch_elapsed_seconds' => round(microtime(true) - $batchStarted, 3),
                        'technical_failure' => $technical, 'leads' => $summaries,
                    ] : $summaries[0]);
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

    private function replayContext(): array
    {
        $manifestPath = realpath((string) $this->option('replay-manifest'));
        $manifest = json_decode(File::get($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        $path = realpath(dirname($manifestPath).'/'.($manifest['context_file'] ?? ''));
        if ($path === false || ! is_file($path) || ! str_starts_with($path, dirname($manifestPath).DIRECTORY_SEPARATOR)
            || hash_file('sha256', $path) !== ($manifest['context_sha256'] ?? null)) {
            throw new InvalidArgumentException('Replay Recon context/checksum non valido; rigenerare il manifest.');
        }
        $context = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
        if (empty($context['recon']['areas']) || ($context['recon']['status'] ?? null) !== 'ready') {
            throw new InvalidArgumentException('Replay richiede un Recon frozen ready.');
        }

        return $context;
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

    private function replayArtifacts(BenchmarkStageArtifactRegistry $registry, string $project, string $source, string $commit): Collection
    {
        if ($this->option('dataset') || $this->option('artifact') !== [] || $this->option('dedup-run') || $this->option('dedup-artifact')) {
            throw new InvalidArgumentException('--replay-manifest non si combina con altri selettori.');
        }
        $path = realpath((string) $this->option('replay-manifest'));
        if ($path === false) {
            throw new InvalidArgumentException('Replay manifest inesistente.');
        }
        $manifest = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
        if (($manifest['schema'] ?? null) !== 'lailaps.unified-replay' || ($manifest['project_key'] ?? null) !== $project || ($manifest['source_commit'] ?? null) !== $commit) {
            throw new InvalidArgumentException('Replay project/source incompatibile con il catalogo.');
        }
        $selected = (array) $this->option('scope');
        $entries = (array) ($manifest['leads'] ?? []);
        if ($entries === [] || count(array_unique(array_column($entries, 'scope'))) !== count($entries)
            || count(array_unique(array_column($entries, 'reader_output_id'))) !== count($entries)) {
            throw new InvalidArgumentException('Replay lineage non univoca.');
        }
        if ($selected !== [] && array_diff($selected, array_column($entries, 'scope')) !== []) {
            throw new InvalidArgumentException('Scope replay sconosciuto.');
        }
        $artifacts = new Collection;
        foreach ($entries as $entry) {
            if ($selected !== [] && ! in_array($entry['scope'], $selected, true)) {
                continue;
            }
            $subjectPath = realpath(dirname($path).'/'.$entry['subject_file']);
            if ($subjectPath === false || ! str_starts_with($subjectPath, dirname($path).DIRECTORY_SEPARATOR) || hash_file('sha256', $subjectPath) !== $entry['subject_sha256']) {
                throw new InvalidArgumentException('Replay subject/checksum non valido.');
            }
            $subject = json_decode(File::get($subjectPath), true, flags: JSON_THROW_ON_ERROR);
            if (($subject['schema'] ?? null) !== 'lailaps.benchmark-stage-input' || ($subject['version'] ?? null) !== 1
                || ($subject['role'] ?? null) !== 'reader' || ($subject['source_commit'] ?? null) !== $commit || empty($subject['source_refs'])) {
                throw new InvalidArgumentException('Replay richiede ReaderLead e source refs pinned.');
            }
            foreach ($subject['source_refs'] as $ref) {
                $file = realpath($source.'/'.$ref['file']);
                if ($file === false || ! str_starts_with(str_replace('\\', '/', $file), $source.'/')) {
                    throw new InvalidArgumentException('Replay source ref fuori perimetro.');
                }
                $lines = preg_split('/\r\n|\n|\r/', File::get($file));
                if ($ref['start_line'] < 1 || $ref['end_line'] < $ref['start_line'] || $ref['end_line'] > count($lines)) {
                    throw new InvalidArgumentException('Replay source range non valido.');
                }
                $snippet = implode("\n", array_slice($lines, $ref['start_line'] - 1, $ref['end_line'] - $ref['start_line'] + 1));
                if (hash('sha256', $snippet) !== ($ref['sha256'] ?? null)) {
                    throw new InvalidArgumentException('Replay source hash mismatch.');
                }
            }
            $signature = hash('sha256', $manifest['baseline_sha256'].$entry['reader_output_id'].$entry['subject_sha256']);
            $artifact = BenchmarkStageArtifact::query()->where('configuration_signature', $signature)->first();
            $artifact ??= $registry->record([
                'project_key' => $project, 'category' => $subject['category'], 'source_commit' => $commit,
                'role' => 'reader', 'output_type' => 'ReaderLead', 'status' => 'valid',
                'configuration_signature' => $signature, 'run_id' => $manifest['baseline_run_id'],
                'payload' => ['output' => $subject['output'], 'source_refs' => $subject['source_refs']],
                'metrics' => ['replay_scope' => $entry['scope'], 'origin_reader_output_id' => $entry['reader_output_id'],
                    'origin_area_id' => $subject['origin_area_id'] ?? null,
                    'baseline_sha256' => $manifest['baseline_sha256'], 'dedup_complete' => true],
            ]);
            $artifacts->push($artifact);
        }

        return $artifacts;
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
        if ($benchmarkCategory === 'global') {
            return 'Global security surfaces';
        }
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
        $replayEntry = collect((array) ($report['global_lead_replay']['leads'] ?? []))
            ->firstWhere('artifact_id', $leadArtifact->id);
        $finding = $replayEntry['finding'] ?? null;
        $globalReplay = isset($report['global_lead_replay']);
        $outputs = collect((array) ($report['structured_outputs'] ?? []))
            ->filter(fn ($row): bool => is_array($row) && in_array($row['role'] ?? null,
                $this->option('unified-lead') ? ['confirmer', 'worker'] : ['confirmer'], true))
            ->filter(fn ($row): bool => ! $globalReplay || ($finding !== null
                && data_get($row, 'payload.lead_id', $row['lead_id'] ?? null) === $finding['lead_id']))
            ->values();
        $oracle = (array) data_get($leadArtifact->metrics, 'oracle', []);
        $decision = null;
        $correct = $oracle === [] ? null : false;
        foreach ($outputs as $output) {
            $decision = (string) ($output['output_type'] ?? 'unknown');
            $evaluation = $oracle === [] ? ['classification_correct' => null, 'quality_score' => null, 'scorable' => false]
                : $oracleEvaluator->evaluate($oracle, $decision, (array) ($output['payload'] ?? []));
            if ($technical && $oracle !== [] && ! $this->option('unified-lead')) {
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
                'role' => $output['role'],
                'output_type' => $decision,
                'model' => data_get($report, 'telemetry.role_usage.'.$output['role'].'.model'),
                'status' => $technical && ! $this->option('unified-lead') ? 'technical_failure' : 'valid',
                'accepted' => (bool) ($output['accepted'] ?? true),
                'payload' => [
                    'output' => (array) ($output['payload'] ?? []),
                    'source_refs' => (array) ($output['source_refs'] ?? []),
                    'lead_id' => $output['lead_id'] ?? null,
                ],
                'usage' => (array) ($output['usage'] ?? []),
                'metrics' => [
                    ...$evaluation,
                    'lead_policy' => $this->option('unified-lead') ? 'unified-v1' : 'confirmer-only',
                    'execution_technical_failure' => $technical,
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
        if (! $globalReplay) {
            $finding = collect([...(array) ($report['confirmed'] ?? []), ...(array) ($report['suspected'] ?? []), ...(array) ($report['rejected_inconclusive'] ?? [])])->first();
        }
        if ($this->option('unified-lead')) {
            $registry->record([
                'project_key' => $projectKey, 'category' => $leadArtifact->category,
                'source_commit' => $leadArtifact->source_commit, 'parent_artifact_id' => $leadArtifact->id,
                'run_id' => $runId, 'repetition' => $repetition, 'role' => 'worker',
                'output_type' => 'UnifiedLeadRun', 'status' => $technical ? 'technical_failure' : 'valid',
                'payload' => ['finding' => $finding], 'usage' => $report['telemetry'] ?? [],
                'metrics' => ['lead_policy' => 'unified-v1', 'static_validation' => (bool) data_get($finding, 'milestones.statically_validated'),
                    'replay_scope' => data_get($leadArtifact->metrics, 'replay_scope')],
                'technical_error' => $technicalError,
            ]);
        }
        $points = (float) ($globalReplay ? ($report['telemetry']['lead_usage'][$finding['lead_id'] ?? '']['economic_points'] ?? 0)
            : data_get($report, 'telemetry.economic_points_used', 0));

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
            'lead_policy' => $this->option('unified-lead') ? 'unified-v1' : 'confirmer-only',
            'static_validation' => (bool) data_get($finding, 'milestones.statically_validated'),
            'finding' => $this->option('unified-lead') ? $finding : null,
            'replay_scope' => data_get($leadArtifact->metrics, 'replay_scope'),
        ];
    }

    private function restoreEnv(string $name, string|false $value): void
    {
        putenv($value === false ? $name : $name.'='.$value);
    }
}
