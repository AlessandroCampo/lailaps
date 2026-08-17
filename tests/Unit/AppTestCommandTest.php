<?php

use App\Console\Commands\AppTest;
use Symfony\Component\Console\Input\ArrayInput;
use Tests\TestCase;

uses(TestCase::class);

/** @param array<string, mixed> $options */
function appTestParameters(string $project, array $options = []): array
{
    $command = new AppTest;
    $input = new ReflectionProperty($command, 'input');
    $input->setValue(
        $command,
        new ArrayInput(['project' => $project, ...$options], $command->getDefinition()),
    );

    $method = new ReflectionMethod($command, 'parametersFor');

    /** @var array<string, mixed> */
    return $method->invoke($command, $project);
}

it('builds the Juice Shop shortcut parameters', function (): void {
    expect(appTestParameters('juice-shop'))
        ->toMatchArray([
            '--path' => base_path('targets/juice-shop'),
            '--image' => 'bkimminich/juice-shop:latest',
            '--port' => 3000,
            '--category' => ['A01:2025 Broken Access Control'],
            '--ttl' => 1800,
            '--test' => true,
        ]);
});

it('builds the DVWA shortcut parameters without an image', function (): void {
    $parameters = appTestParameters('dvwa');

    expect($parameters)
        ->toMatchArray([
            '--path' => base_path('targets/dvwa'),
            '--category' => ['A01:2025 Broken Access Control'],
            '--ttl' => 1800,
            '--test' => true,
        ])
        ->not->toHaveKey('--image')
        ->not->toHaveKey('--port');
});

it('supports the remaining audit targets and option overrides', function (): void {
    expect(appTestParameters('mutillidae-source')['--path'])
        ->toBe(base_path('targets/mutillidae-source'))
        ->and(appTestParameters('owasp-benchmark-java')['--path'])
        ->toBe(base_path('targets/owasp-benchmark-java'));

    expect(appTestParameters('dvwa', [
        '--category' => 'A07:2025 Authentication Failures',
        '--no-test' => true,
        '--ttl' => '900',
        '--keep' => true,
    ]))->toMatchArray([
        '--category' => ['A07:2025 Authentication Failures'],
        '--test' => false,
        '--ttl' => 900,
        '--keep' => true,
    ]);
});

it('rejects an unknown project', function (): void {
    expect(fn () => appTestParameters('unknown'))
        ->toThrow(InvalidArgumentException::class, 'non supportato');
});
