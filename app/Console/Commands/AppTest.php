<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use InvalidArgumentException;

final class AppTest extends Command
{
    protected $signature = 'app:test
                            {project : Target da testare (dvwa, juice-shop, mutillidae-source, owasp-benchmark-java)}
                            {--category= : Sovrascrive la categoria OWASP predefinita}
                            {--dual-agent : Abilita il reader/worker dual-agent}
                            {--no-dual-agent : Disabilita il dual-agent predefinito del progetto}
                            {--test : Mantiene la modalità test (attiva per default)}
                            {--no-test : Disabilita la modalità test}
                            {--ttl=1800 : Durata massima della sandbox in secondi}
                            {--keep=true : Non smontare la sandbox a fine run}';

    protected $description = 'Esegue il pentest sul progetto selezionato usando pentest:run';

    /** @var array<string, array<string, mixed>> */
    private const PROJECTS = [
        'juice-shop' => [
            'path' => 'targets/juice-shop',
            'image' => 'bkimminich/juice-shop:latest',
            'port' => 3000,
            'category' => 'A01:2025 Broken Access Control',
            'dual-agent' => true,
        ],
        'dvwa' => [
            'path' => 'targets/dvwa',
            'category' => 'A01:2025 Broken Access Control',
            'dual-agent' => false,
        ],
        'mutillidae-source' => [
            'path' => 'targets/mutillidae-source',
            'category' => 'A03:2025 Injection',
            'dual-agent' => false,
        ],
        'owasp-benchmark-java' => [
            'path' => 'targets/owasp-benchmark-java',
            'category' => 'A05:2025 Injection',
            'dual-agent' => false,
        ],
    ];

    public function handle(): int
    {
        $project = (string) $this->argument('project');

        if ($project === 'owasp-benchmark-java') {
            $category = $this->option('category');
            $this->info($category
                ? "Avvio OWASP Java benchmark sulla sotto-categoria {$category}..."
                : 'Avvio OWASP Java su tutte le sotto-categorie...');

            $parameters = [
                'target-id' => $project,
                '--path' => base_path('targets/'.$project),
                '--keep' => $this->enabledOption('keep'),
                '--test' => ! $this->option('no-test'),
            ];
            if ($category) {
                $parameters['--category'] = $category;
            }

            return Artisan::call('benchmark:run', $parameters, $this->output);
        }

        try {
            $parameters = $this->parametersFor($project);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }

        $this->info("Avvio pentest per {$project}...");

        return Artisan::call('pentest:run', $parameters, $this->output);
    }

    /** @return array<string, mixed> */
    protected function parametersFor(string $project): array
    {
        if (! isset(self::PROJECTS[$project])) {
            throw new InvalidArgumentException(sprintf(
                "Progetto '%s' non supportato. Usa uno tra: %s.",
                $project,
                implode(', ', array_keys(self::PROJECTS)),
            ));
        }

        $config = self::PROJECTS[$project];
        $parameters = [
            '--path' => base_path($config['path']),
            '--category' => [$this->option('category') ?: $config['category']],
            '--ttl' => (int) ($this->option('ttl') ?: 1800),
            '--test' => ! $this->option('no-test'),
            '--keep' => $this->enabledOption('keep'),
        ];

        if (isset($config['image'])) {
            $parameters['--image'] = $config['image'];
        }
        if (isset($config['port'])) {
            $parameters['--port'] = $config['port'];
        }

        $dualAgent = (bool) $config['dual-agent'];
        if ($this->option('dual-agent')) {
            $dualAgent = true;
        }
        if ($this->option('no-dual-agent')) {
            $dualAgent = false;
        }
        $parameters['--dual-agent'] = $dualAgent;

        return $parameters;
    }

    private function enabledOption(string $name): bool
    {
        $value = $this->option($name);
        if ($value === null) {
            return true;
        }
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL);
    }
}
