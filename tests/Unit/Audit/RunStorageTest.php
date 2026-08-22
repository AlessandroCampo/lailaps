<?php

use App\Services\Audit\RunStorage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

it('creates one readable run directory containing exactly log and outcome', function (): void {
    $storage = app(RunStorage::class);
    $location = $storage->create('YesWiki', ['A05:2025 Injection'], Carbon::create(2026, 8, 22, 17, 39, 0));

    try {
        expect($location['run_id'])->toStartWith('yeswiki-injection-20260822-173900')
            ->and(str_replace('\\', '/', $location['directory']))
            ->toContain('/storage/app/runs/yeswiki/injection/'.$location['run_id']);

        $files = collect(File::files($location['directory']))->map->getFilename()->sort()->values()->all();
        expect($files)->toBe([
            $location['run_id'].'-logs.php',
            $location['run_id'].'-outcome.json',
        ]);

        $outcome = $storage->outcome($location['directory'], $location['run_id']);
        expect(array_keys($outcome))->toBe(['run_id', 'report', 'benchmark'])
            ->and($outcome['run_id'])->toBe($location['run_id']);

        $storage->writeOutcome($location['directory'], $location['run_id'], ['confirmed' => []], ['score' => 10]);
        expect($storage->outcome($location['directory'], $location['run_id']))
            ->toMatchArray(['run_id' => $location['run_id'], 'report' => ['confirmed' => []], 'benchmark' => ['score' => 10]]);
    } finally {
        File::deleteDirectory($location['directory']);
    }
});
