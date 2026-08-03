<?php

use App\Services\Sandbox\Support\WebServiceResolver;

it('prefers the HTTP port when a web service also publishes HTTPS', function (): void {
    $port = (new WebServiceResolver)->publishedPort([
        'Ports' => [
            ['Type' => 'tcp', 'PrivatePort' => 443, 'PublicPort' => 51443, 'IP' => '127.0.0.1'],
            ['Type' => 'tcp', 'PrivatePort' => 80, 'PublicPort' => 51080, 'IP' => '127.0.0.1'],
        ],
    ]);

    expect($port)->toBe([
        'private' => 80,
        'public' => 51080,
        'ip' => '127.0.0.1',
    ]);
});
