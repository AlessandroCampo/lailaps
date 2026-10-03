<?php

namespace App\Console\Commands;

use App\Models\BenchmarkStageArtifact;
use App\Services\Audit\RunStorage;
use App\Services\Pentest\BenchmarkDeduperArtifacts;
use App\Services\Pentest\BenchmarkStageArtifactRegistry;
use App\Services\Pentest\BenchmarkStageProcessRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\Process\Process;
use Throwable;

final class BenchmarkDeterministicDeduper extends Command
{
    protected $signature = 'benchmark:deduper-deterministic
        {target-id : Project key}
        {reader-run-id : ReaderRun or ReaderGlobalRun ID}
        {--reference-dedup-run= : Existing LLM deduper execution ID}
        {--identity-overlay= : Reviewed identity annotations}
        {--prepare-identities= : Write an identity review template}
        {--output= : New benchmark output directory}
        {--image= : Runtime image containing the deterministic engine}';

    protected $description = 'Run provider-free deduplication on frozen ReaderLead and compare with an LLM execution';

    public function handle(BenchmarkDeduperArtifacts $artifacts, BenchmarkStageArtifactRegistry $registry,
        BenchmarkStageProcessRunner $runner, RunStorage $storage): int
    {
        $oldImage = config('pentest.agent.image');
        try {
            $project = (string) $this->argument('target-id');
            $readerRunId = (string) $this->argument('reader-run-id');
            $output = (string) $this->option('output');
            if ($output === '' || file_exists($output) ||
                ($this->option('identity-overlay') && $this->option('prepare-identities'))) {
                throw new InvalidArgumentException('Require a new --output directory and one identity mode.');
            }
            $referenceId = (string) $this->option('reference-dedup-run');
            $referencePath = null;
            if ($referenceId !== '') {
                $reference = BenchmarkStageArtifact::query()->where('project_key', $project)
                    ->where('run_id', $referenceId)->where('role', 'deduper')
                    ->where('output_type', 'DeduperRun')->sole();
                if (data_get($reference->payload, 'reader_run_id') !== $readerRunId ||
                    data_get($reference->metrics, 'engine', 'llm') !== 'llm') {
                    throw new InvalidArgumentException('Reference must be an LLM execution for this Reader run.');
                }
                $artifacts->verifyFrozen($reference);
                $manifest = $reference->payload;
                $input = $artifacts->input($reference);
                $referencePath = (string) data_get($reference->metrics, 'directory').'/dedup.json';
                if (! is_file($referencePath)) {
                    throw new InvalidArgumentException('Reference ledger missing.');
                }
            } else {
                $manifest = $artifacts->corpus($project, $readerRunId);
                $input = $manifest['input'];
            }
            File::makeDirectory($output, 0775, true);
            $this->write($output.'/frozen-input.json', $input);
            if ($referencePath) {
                File::copy($referencePath, $output.'/reference.json');
            }
            $overlay = (string) $this->option('identity-overlay');
            if ($overlay !== '') {
                if (! is_file($overlay)) {
                    throw new InvalidArgumentException('Identity overlay missing.');
                }
                File::copy($overlay, $output.'/identity-overlay.json');
            }
            $prepare = (string) $this->option('prepare-identities');
            if ($prepare !== '' && file_exists($prepare)) {
                throw new InvalidArgumentException('Identity template already exists.');
            }
            $image = (string) ($this->option('image') ?: config('pentest.agent.image'));
            $imageDigest = null;
            if ($runner->containerized()) {
                $inspect = new Process(['docker', 'image', 'inspect', $image, '--format', '{{.Id}}'], base_path());
                if ($inspect->run() !== 0) {
                    throw new InvalidArgumentException('Runtime image unavailable: '.$image);
                }
                $imageDigest = trim($inspect->getOutput());
            }
            config()->set('pentest.agent.image', $image);
            $container = $runner->containerized();
            $base = $container ? '/artifacts' : $output;
            $args = ['deduper-deterministic', '--input', $base.'/frozen-input.json',
                '--output', $base.'/engine'];
            if ($referencePath) {
                array_push($args, '--reference', $base.'/reference.json');
            }
            if ($overlay !== '') {
                array_push($args, '--identity-overlay', $base.'/identity-overlay.json');
            }
            if ($prepare !== '') {
                // The template is written outside the Docker mount only after successful execution.
                array_push($args, '--prepare-identities', $base.'/identity-template.json');
            }
            $executionId = 'deduper-deterministic-'.strtolower((string) Str::ulid());
            $code = $runner->run($output, $output, $executionId, $args, [], $storage,
                fn ($type, $buffer) => $this->output->write($buffer), offline: true);
            if ($code !== 0) {
                return $code;
            }
            foreach (File::files($output.'/engine') as $file) {
                File::copy($file->getPathname(), $output.'/'.$file->getFilename());
            }
            $runManifest = json_decode(File::get($output.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
            $runManifest['runtime_image'] = $image;
            $runManifest['runtime_image_digest'] = $imageDigest;
            $this->write($output.'/manifest.json', $runManifest);
            if ($prepare !== '') {
                File::ensureDirectoryExists(dirname($prepare));
                File::move($output.'/identity-template.json', $prepare);
                $this->info('Identity review template: '.$prepare);
                return self::SUCCESS;
            }
            $result = json_decode(File::get($output.'/decisions.json'), true, flags: JSON_THROW_ON_ERROR);
            $summary = $result['summary'];
            $inputHash = BenchmarkDeduperArtifacts::hash($input);
            $manifest['input_hash'] = $inputHash;
            $manifest['frozen_input_sha256'] = hash_file('sha256', $output.'/frozen-input.json');
            unset($manifest['input']['items']);
            $manifest['engine'] = 'deterministic';
            $manifest['runtime_image_digest'] = $imageDigest;
            $manifest['identity_overlay_sha256'] = $overlay !== '' ? hash_file('sha256', $output.'/identity-overlay.json') : null;
            unset($manifest['manifest_hash']);
            $manifest['manifest_hash'] = BenchmarkDeduperArtifacts::hash($manifest);
            DB::transaction(function () use ($registry, $manifest, $result, $input, $summary, $project, $executionId, $output, $image): void {
                $base = ['project_key' => $project, 'category' => 'global',
                    'source_commit' => $input['snapshot'], 'role' => 'deduper', 'run_id' => $executionId,
                    'model' => null, 'reasoning_effort' => null];
                $parent = $registry->record([...$base, 'output_type' => 'DeduperRun',
                    'parent_artifact_id' => $manifest['reader_parent_artifact_id'],
                    'status' => 'completed', 'accepted' => false, 'is_canonical' => false,
                    'payload' => $manifest, 'usage' => ['requests' => 0, 'economic_points' => 0,
                        'input_tokens' => 0, 'output_tokens' => 0, 'usage_complete' => true],
                    'metrics' => ['engine' => 'deterministic', 'contract' => ['version' => $result['version']],
                        'directory' => $output, 'runtime_image' => $image, 'complete' => true,
                        'states' => ['completed' => count($result['decisions'])],
                        'canonical_products' => count($result['canonical_ids']), 'summary' => $summary]]);
                $items = array_column($input['items'], null, 'id');
                foreach ($result['decisions'] as $decision) {
                    $id = $decision['proposal_id'];
                    $registry->record([...$base, 'output_type' => 'DeduperDecision',
                        'parent_artifact_id' => $parent->id, 'status' => 'completed',
                        'accepted' => false, 'is_canonical' => false,
                        'payload' => ['decision' => ['decision' => $decision['decision'],
                            'related_ids' => $decision['duplicate_of'] ? [$decision['duplicate_of']] : []]],
                        'metrics' => [...$decision, 'current' => true,
                            'original_artifact_id' => $manifest['origins'][$id]['artifact_id']],
                        'configuration_signature' => BenchmarkDeduperArtifacts::hash([$executionId, 'decision', $id])]);
                }
                foreach ($result['canonical_ids'] as $id) {
                    $item = $items[$id];
                    $payload = ['output' => $item['payload'], 'source_refs' => array_map(
                        fn ($refId, $ref) => [...$ref, 'source_ref_id' => $refId],
                        array_keys((array) $item['source_refs']), array_values((array) $item['source_refs']))];
                    $registry->record([...$base, 'output_type' => 'ReaderLead',
                        'parent_artifact_id' => $parent->id, 'status' => 'valid',
                        'accepted' => true, 'is_canonical' => true, 'payload' => $payload,
                        'metrics' => ['canonical_id' => $id, 'original_ids' => $result['groups'][$id],
                            'original_artifact_ids' => array_map(fn ($member) => $manifest['origins'][$member]['artifact_id'], $result['groups'][$id]),
                            'relation' => 'pass', 'adjudication_status' => 'completed',
                            'original_category' => $manifest['origins'][$id]['category'], 'dedup_complete' => true],
                        'configuration_signature' => BenchmarkDeduperArtifacts::hash([$executionId, 'canonical', $id])]);
                }
            });
            $this->line(json_encode(['execution_id' => $executionId, 'summary' => $summary, 'output' => $output], JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error->getMessage());
            return self::FAILURE;
        } finally {
            config()->set('pentest.agent.image', $oldImage);
        }
    }

    private function write(string $path, array $value): void
    {
        File::put($path, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
