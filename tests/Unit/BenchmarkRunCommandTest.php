<?php

use App\Console\Commands\BenchmarkRun;
use Tests\TestCase;

uses(TestCase::class);

it('exposes independent worker and operative model options', function (): void {
    $definition = app(BenchmarkRun::class)->getDefinition();

    expect($definition->hasOption('operative-model'))->toBeTrue()
        ->and($definition->hasOption('worker-model'))->toBeTrue();
});
