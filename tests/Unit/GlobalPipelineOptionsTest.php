<?php

use App\Services\Pentest\GlobalPipelineOptions;

it('rejects incompatible global options before runtime startup', function (array $override): void {
    $options = array_replace([
        'reader-concurrency' => 4, 'lead-concurrency' => 2,
        'reader-points' => null, 'worker-points' => null,
    ], $override);
    expect(fn () => GlobalPipelineOptions::validate($options))->toThrow(InvalidArgumentException::class);
})->with([
    [['reader-concurrency' => 5]], [['reader-concurrency' => 0]], [['confirmer-concurrency' => '1.5']],
    [['worker-concurrency' => 0]], [['reader-points' => -1]], [['worker-points' => 'INF']],
    [['worker-points' => '1e999']],
    [['lead-concurrency' => 0]], [['lead-model' => 'one', 'confirmer-model' => 'two']],
]);

it('accepts one global lead model with matching overrides', function (): void {
    GlobalPipelineOptions::validate(['reader-concurrency' => 4, 'lead-concurrency' => 2,
        'lead-model' => 'acme/lead', 'worker-model' => 'acme/lead', 'confirmer-model' => 'acme/lead']);
    expect(true)->toBeTrue();
});
