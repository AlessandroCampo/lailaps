<?php

use App\Services\Sandbox\DockerClient;
use PharData;

it('includes the selected Dockerfile even when dockerignore excludes it', function (): void {
    $project = sys_get_temp_dir().'/lailaps-docker-context-'.bin2hex(random_bytes(4));
    mkdir($project, 0777, true);

    file_put_contents("{$project}/Dockerfile", 'FROM scratch');
    file_put_contents("{$project}/.dockerignore", "Dockerfile\n");
    file_put_contents("{$project}/app.txt", 'included');

    $tarPath = null;

    try {
        $client = new DockerClient;
        $tarPath = (fn (): string => $this->packContext($project, 'Dockerfile'))->call($client);
        $archive = new PharData($tarPath);

        expect(isset($archive['Dockerfile']))->toBeTrue()
            ->and(isset($archive['app.txt']))->toBeTrue();
    } finally {
        unset($archive);

        if (is_string($tarPath)) {
            @unlink($tarPath);
        }

        @unlink("{$project}/Dockerfile");
        @unlink("{$project}/.dockerignore");
        @unlink("{$project}/app.txt");
        @rmdir($project);
    }
});


function bootstrapExecClient(array $states, int $timeout = 1): DockerClient
{
    return new class($states, $timeout) extends DockerClient
    {
        public array $calls = [];

        public function __construct(private array $states, int $timeout)
        {
            parent::__construct(timeout: $timeout);
        }

        protected function request(string $method, string $uri, ?array $json = null): array
        {
            $this->calls[] = [$method, $uri, $json];
            return match ($uri) {
                '/containers/target/exec' => ['Id' => 'same-exec'],
                '/exec/same-exec/start' => [],
                '/exec/same-exec/json' => array_shift($this->states),
                default => throw new RuntimeException('Unexpected Docker request'),
            };
        }
    };
}

it('waits for a bootstrap exec result without starting the command again', function (): void {
    $client = bootstrapExecClient([
        ['Running' => true, 'ExitCode' => null],
        ['Running' => false, 'ExitCode' => null],
        ['Running' => false, 'ExitCode' => 0],
    ]);
    expect($client->execContainer('target', ['php', 'artisan', 'config:cache']))->toBe(0)
        ->and(array_column($client->calls, 1))->toBe([
            '/containers/target/exec', '/exec/same-exec/start',
            '/exec/same-exec/json', '/exec/same-exec/json', '/exec/same-exec/json',
        ]);
});

it('preserves the real nonzero bootstrap exit code', function (int $exit): void {
    $client = bootstrapExecClient([['Running' => false, 'ExitCode' => $exit]]);
    expect($client->execContainer('target', ['php', 'failing.php']))->toBe($exit);
})->with([1, 137]);

it('reports an unknown bootstrap outcome on timeout without rerunning it', function (): void {
    $client = bootstrapExecClient([['Running' => true, 'ExitCode' => null]], timeout: 0);
    expect(fn () => $client->execContainer('target', ['php', 'slow.php']))
        ->toThrow(RuntimeException::class, 'comando non ripetuto')
        ->and(array_column($client->calls, 1))->toBe([
            '/containers/target/exec', '/exec/same-exec/start', '/exec/same-exec/json',
        ]);
});
