<?php

use App\Services\Pentest\BrowserSidecar;
use Tests\TestCase;

uses(TestCase::class);

it('reports docker failures without exposing the gateway token', function (string $output, string $expected): void {
    $command = [PHP_BINARY, '-r', 'fwrite(STDERR, '.var_export($output, true).'); exit(1);',
        'BROWSER_GATEWAY_TOKEN=test-private-gateway-token'];
    $method = new ReflectionMethod(BrowserSidecar::class, 'mustRun');

    try {
        $method->invoke(new BrowserSidecar, $command, 'creazione rete browser');
        $this->fail('Expected a Docker failure.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())
            ->toContain('Fallita creazione rete browser (codice 1)', $expected)
            ->not->toContain('test-private-gateway-token');
    }
})->with([
    'address pools exhausted' => ['all predefined address pools have been fully subnetted',
        'all predefined address pools have been fully subnetted'],
    'secret redacted' => ['failure BROWSER_GATEWAY_TOKEN=test-private-gateway-token',
        'BROWSER_GATEWAY_TOKEN=[redacted]'],
]);
