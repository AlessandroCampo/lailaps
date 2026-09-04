<?php

use App\Console\Commands\BenchmarkExperimentRun;
use App\Console\Commands\BenchmarkRun;
use App\Jobs\ExecuteAuditRun;
use Tests\TestCase;

uses(TestCase::class);

it('exposes independent worker and operative model options', function (): void {
    $definition = app(BenchmarkRun::class)->getDefinition();

    expect($definition->hasOption('operative-model'))->toBeTrue()
        ->and($definition->hasOption('worker-model'))->toBeTrue()
        ->and($definition->hasOption('test-area'))->toBeTrue()
        ->and($definition->hasOption('reuse-sandbox'))->toBeTrue();
});

it('builds a one-variable-at-a-time role model matrix', function (): void {
    $method = new ReflectionMethod(BenchmarkExperimentRun::class, 'modelMatrix');
    $matrix = $method->invoke(app(BenchmarkExperimentRun::class), [
        'subject_role' => 'reader',
        'subject_models' => ['acme/sol', 'acme/glm'],
        'control_models' => [
            'reviewer' => 'acme/reviewer', 'confirmer' => 'acme/confirmer',
            'worker' => 'acme/worker', 'judge' => 'acme/judge',
        ],
    ]);

    expect($matrix)->toHaveCount(2)
        ->and($matrix[0])->toMatchArray(['reader' => 'acme/sol', 'worker' => 'acme/worker'])
        ->and($matrix[1])->toMatchArray(['reader' => 'acme/glm', 'judge' => 'acme/judge']);
});

it('supports sequential foreground experiments without a queue worker', function (): void {
    $definition = app(BenchmarkExperimentRun::class)->getDefinition();

    expect($definition->hasOption('foreground'))->toBeTrue()
        ->and((new ReflectionMethod(ExecuteAuditRun::class, 'execute'))->isPublic())->toBeTrue();
});
