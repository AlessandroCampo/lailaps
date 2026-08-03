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
