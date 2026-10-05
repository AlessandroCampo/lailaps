<?php

use App\Console\Commands\BenchmarkExperimentRun;
use App\Console\Commands\BenchmarkRun;
use App\Jobs\ExecuteAuditRun;
use App\Services\Pentest\BenchmarkResultAggregator;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Input\ArrayInput;
use Tests\TestCase;

uses(TestCase::class);

it('exposes independent worker and operative model options', function (): void {
    $definition = app(BenchmarkRun::class)->getDefinition();

    expect($definition->hasOption('operative-model'))->toBeTrue()
        ->and($definition->hasOption('model'))->toBeTrue()
        ->and($definition->hasOption('recon-model'))->toBeTrue()
        ->and($definition->hasOption('worker-model'))->toBeTrue()
        ->and($definition->getOption('reader-checkpoint-strategy')->getDefault())->toBeNull()
        ->and($definition->hasOption('test-area'))->toBeTrue()
        ->and($definition->hasOption('reuse-sandbox'))->toBeTrue()
        ->and($definition->hasOption('global'))->toBeTrue()
        ->and($definition->hasOption('depth'))->toBeTrue()
        ->and($definition->hasOption('ttl'))->toBeTrue();
});

it('passes benchmark identity to pentest sandbox creation', function (): void {
    $command = app(BenchmarkRun::class);
    $input = new ArrayInput([
        'target-id' => 'fixture',
        '--reader-checkpoint-strategy' => 'reader_checkpoint',
    ], $command->getDefinition());
    $command->setInput($input);
    $method = new ReflectionMethod(BenchmarkRun::class, 'pentestParameters');

    $parameters = $method->invoke(
        $command, base_path(), 'report-run', 'fixture', null, [], false, null, 'fixture-snapshot',
    );

    expect($parameters['--audit-id'])->toBe('report-run')
        ->and($parameters['--benchmark-target-id'])->toBe('fixture')
        ->and($parameters['--reader-checkpoint-strategy'])->toBe('reader_checkpoint')
        ->and($parameters['--source-snapshot'])->toBe('fixture-snapshot');
});

it('builds a one-variable-at-a-time role model matrix', function (): void {
    $method = new ReflectionMethod(BenchmarkExperimentRun::class, 'modelMatrix');
    $matrix = $method->invoke(app(BenchmarkExperimentRun::class), [
        'subject_role' => 'reader',
        'subject_models' => ['acme/sol', 'acme/glm'],
        'control_models' => [
            'reviewer' => 'acme/reviewer', 'confirmer' => 'acme/confirmer',
            'worker' => 'acme/worker',
        ],
    ]);

    expect($matrix)->toHaveCount(2)
        ->and($matrix[0])->toMatchArray(['reader' => 'acme/sol', 'worker' => 'acme/worker'])
        ->and($matrix[1])->toMatchArray(['reader' => 'acme/glm', 'worker' => 'acme/worker']);
});

it('passes the harness compose explicitly when upstream has a development compose', function (): void {
    $directory = storage_path('framework/testing/benchmark-compose-'.uniqid());
    File::ensureDirectoryExists($directory.'/overlay');
    File::ensureDirectoryExists($directory.'/runtime');
    try {
        File::put($directory.'/overlay/compose.yml', "services:\n  bookstack-web:\n    image: benchmark\n");
        File::put($directory.'/runtime/compose.yml', File::get($directory.'/overlay/compose.yml'));
        File::put($directory.'/runtime/docker-compose.yml', "services:\n  app:\n    image: development\n");
        $command = app(BenchmarkRun::class);
        $command->setInput(new ArrayInput(['target-id' => 'bookstack'], $command->getDefinition()));
        $compose = (new ReflectionMethod(BenchmarkRun::class, 'runtimeComposeFile'))->invoke(
            $command, $directory.'/overlay', $directory.'/runtime',
        );
        $parameters = (new ReflectionMethod(BenchmarkRun::class, 'pentestParameters'))->invoke(
            $command, base_path(), 'fixture-run', 'bookstack', null, [], true,
            $directory.'/runtime', 'snapshot', $compose,
        );
        expect($parameters['--compose'])->toBe($directory.'/runtime/compose.yml');
    } finally {
        File::deleteDirectory($directory);
    }
});

it('keeps source compose discovery when the runtime overlay has no compose', function (): void {
    $command = app(BenchmarkRun::class);
    $command->setInput(new ArrayInput(['target-id' => 'fixture'], $command->getDefinition()));
    $compose = (new ReflectionMethod(BenchmarkRun::class, 'runtimeComposeFile'))->invoke(
        $command, __DIR__, base_path(),
    );
    $parameters = (new ReflectionMethod(BenchmarkRun::class, 'pentestParameters'))->invoke(
        $command, base_path(), 'fixture-run', 'fixture', null, [], false, base_path(), 'snapshot', $compose,
    );
    expect($compose)->toBeNull()->and($parameters)->not->toHaveKey('--compose');
});

it('defaults global benchmarks to reader checkpoints and forwards pipeline overrides', function (): void {
    $command = app(BenchmarkRun::class);
    $command->setInput(new ArrayInput([
        'target-id' => 'bookstack', '--global' => true,
        '--reader-concurrency' => '3', '--confirmer-concurrency' => '2', '--worker-concurrency' => '1',
        '--reader-points' => '123000', '--worker-points' => '1000000',
    ], $command->getDefinition()));
    $parameters = (new ReflectionMethod(BenchmarkRun::class, 'pentestParameters'))->invoke(
        $command, base_path(), 'fixture-run', 'bookstack', null, [], true, null, 'snapshot',
    );
    expect($parameters)->toMatchArray([
        '--reader-checkpoint-strategy' => 'reader_checkpoint', '--reader-concurrency' => '3',
        '--confirmer-concurrency' => '2', '--worker-concurrency' => '1',
        '--reader-points' => '123000', '--worker-points' => '1000000',
    ]);
});

it('supports sequential foreground experiments without a queue worker', function (): void {
    $definition = app(BenchmarkExperimentRun::class)->getDefinition();

    expect($definition->hasOption('foreground'))->toBeTrue()
        ->and($definition->hasOption('reader-checkpoint-strategy'))->toBeTrue()
        ->and((new ReflectionMethod(ExecuteAuditRun::class, 'execute'))->isPublic())->toBeTrue();
});

it('scores global discovery once and removes suite matches from pending findings', function (): void {
    $results = app(BenchmarkResultAggregator::class)->aggregate('fixture', 'global-suite', [
        'first' => [
            'artifact_state' => 'final', 'status' => 'provisional',
            'cost' => ['total_tokens' => 100],
            'ambiguous_findings' => [],
            'unmatched_findings' => [
                ['finding_id' => 'lead-static', 'stage' => 'statically_validated'],
                ['finding_id' => 'lead-dynamic', 'stage' => 'dynamically_confirmed'],
                ['finding_id' => 'lead-suspect', 'stage' => 'suspected'],
            ],
            'pending_adjudications' => 3,
            'cases' => [],
        ],
        'second' => [
            'artifact_state' => 'final', 'status' => 'provisional',
            'cost' => ['total_tokens' => 100],
            'ambiguous_findings' => [],
            'unmatched_findings' => [
                ['finding_id' => 'lead-dynamic', 'stage' => 'dynamically_confirmed'],
                ['finding_id' => 'lead-suspect', 'stage' => 'suspected'],
            ],
            'pending_adjudications' => 2,
            'cases' => [[
                'id' => 'known-case', 'expected_vulnerable' => true, 'evaluation_mode' => 'vulnerability',
                'stage' => 'statically_validated', 'stage_score' => 3.0,
                'milestones' => ['suspected' => true, 'statically_validated' => true, 'dynamically_confirmed' => false],
                'suspected' => 'true_positive', 'static_validation' => 'true_positive',
                'dynamically_confirmed' => 'false_negative',
                'matched_findings' => [['finding_id' => 'lead-static', 'stage' => 'statically_validated']],
            ]],
        ],
    ], [
        'run_state' => 'completed',
        'shared_run' => true,
        'report' => [
            'mode' => 'global',
            'confirmed' => [[
                'finding_id' => 'lead-dynamic', 'status' => 'confirmed',
                'adjudication' => [
                    'role' => 'dynamic_judge', 'decision' => 'approve_confirmed',
                    'evidence_validation' => ['passed' => true],
                ],
            ]],
            'suspected' => [
                ['finding_id' => 'lead-static', 'milestones' => ['statically_validated' => true]],
                ['finding_id' => 'lead-suspect', 'milestones' => ['suspected' => true]],
            ],
            'rejected_inconclusive' => [[
                'finding_id' => 'lead-rejected', 'status' => 'rejected',
                'milestones' => ['statically_validated' => true],
            ]],
        ],
    ]);

    expect($results['cost']['total_tokens'])->toBe(100)
        ->and($results['pending_adjudications'])->toBe(2)
        ->and($results['by_category']['first']['pending_adjudications'])->toBe(2)
        ->and($results['by_category']['second']['pending_adjudications'])->toBe(0)
        ->and($results['discovery_yield'])->toMatchArray([
            'points' => 8.0,
            'total_findings' => 3,
            'credited_findings' => 2,
            'statically_validated' => 1,
            'dynamically_confirmed' => 1,
            'catalog_matched_credited' => 1,
            'out_of_catalog_credited' => 1,
            'pending_adjudications' => 2,
        ]);
});
