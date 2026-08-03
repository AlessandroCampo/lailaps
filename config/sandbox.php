<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Docker Engine API
    |--------------------------------------------------------------------------
    |
    | Endpoint dell'API Docker. Di default l'engine NON espone TCP: va
    | abilitato ("Expose daemon on tcp://localhost:2375" su Docker Desktop)
    | oppure va puntato un socket proxy.
    |
    */

    'docker' => [
        'api' => env('SANDBOX_DOCKER_API', 'http://localhost:2375'),
        'timeout' => (int) env('SANDBOX_DOCKER_TIMEOUT', 30),
        'build_timeout' => (int) env('SANDBOX_BUILD_TIMEOUT', 600),
    ],

    'defaults' => [
        'ttl' => (int) env('SANDBOX_TTL', 1800),
        'health_timeout' => (int) env('SANDBOX_HEALTH_TIMEOUT', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Timezone di visualizzazione
    |--------------------------------------------------------------------------
    |
    | Le scadenze si confrontano sempre su timestamp Unix: questo valore tocca
    | solo l'output della CLI. Mettilo alla tua timezone locale (es. Europe/Rome)
    | per non dover convertire a mente quello che stampa pentest:run.
    |
    */

    'display_timezone' => env('SANDBOX_DISPLAY_TZ', 'UTC'),

    /*
    |--------------------------------------------------------------------------
    | Limiti applicati a ogni container
    |--------------------------------------------------------------------------
    */

    'limits' => [
        'memory' => 512 * 1024 * 1024,
        'nano_cpus' => 1_000_000_000,
        'pids' => 200,
    ],

    /*
    |--------------------------------------------------------------------------
    | Immagine di fallback
    |--------------------------------------------------------------------------
    |
    | Usata solo quando lo spec non indica un'immagine e nel progetto non c'è
    | un Dockerfile. Metti a null per fallire invece che indovinare.
    |
    */

    'fallback' => [
        'tag' => env('SANDBOX_FALLBACK_TAG', 'lailaps-sandbox-php8.3'),
        'context' => base_path('sandboxes/php8.3-laravel'),
        'build_args' => ['PHP_VERSION' => '8.3'],
        'mount_path' => '/var/www/html',
        'port' => 8000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Build context
    |--------------------------------------------------------------------------
    |
    | Pattern sempre esclusi dal tar inviato a Docker, in aggiunta a quanto
    | dichiarato nel .dockerignore del progetto.
    |
    */

    'build_exclude' => ['.git', 'node_modules', '.idea', '.vscode', '.DS_Store'],

];
