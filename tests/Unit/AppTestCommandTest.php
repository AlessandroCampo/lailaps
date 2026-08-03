<?php

use App\Console\Commands\AppTest;
use Symfony\Component\Console\Input\ArrayInput;

function exposedAppTest(): AppTest
{
    return new class extends AppTest
    {
        /** @return array<string, mixed> */
        public function parametersForProject(string $project, array $options = []): array
        {
            $this->input = new ArrayInput($options, $this->getDefinition());

            return $this->parametersFor($project);
        }
    };
}

it('builds the Juice Shop shortcut parameters', function (): void {
    expect(exposedAppTest()->parametersForProject('juice-shop'))
        ->toMatchArray([
            '--path' => base_path('targets/juice-shop'),
            '--image' => 'bkimminich/juice-shop:latest',
            '--port' => 3000,
            '--category' => ['A01:2025 Broken Access Control'],
            '--dual-agent' => true,
            '--ttl' => 1800,
            '--test' => true,
        ]);
});

it('builds the DVWA shortcut parameters without an image or dual-agent', function (): void {
    $parameters = exposedAppTest()->parametersForProject('dvwa');

    expect($parameters)
        ->toMatchArray([
            '--path' => base_path('targets/dvwa'),
            '--category' => ['A01:2025 Broken Access Control'],
            '--dual-agent' => false,
            '--ttl' => 1800,
            '--test' => true,
        ])
        ->not->toHaveKey('--image')
        ->not->toHaveKey('--port');
});

it('supports the remaining audit targets and option overrides', function (): void {
    $command = exposedAppTest();

    expect($command->parametersForProject('mutillidae-source')['--path'])
        ->toBe(base_path('targets/mutillidae-source'))
        ->and($command->parametersForProject('owasp-benchmark-java')['--path'])
        ->toBe(base_path('targets/owasp-benchmark-java'));

    expect($command->parametersForProject('dvwa', [
        '--category' => 'A07:2025 Authentication Failures',
        '--dual-agent' => true,
        '--no-test' => true,
        '--ttl' => '900',
        '--keep' => true,
    ]))->toMatchArray([
        '--category' => ['A07:2025 Authentication Failures'],
        '--dual-agent' => true,
        '--test' => false,
        '--ttl' => 900,
        '--keep' => true,
    ]);
});

it('rejects an unknown project', function (): void {
    expect(fn () => exposedAppTest()->parametersForProject('unknown'))
        ->toThrow(InvalidArgumentException::class, 'non supportato');
});
