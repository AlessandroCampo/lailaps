<?php

namespace App\Providers;

use App\Services\Sandbox\DockerClient;
use App\Services\Sandbox\Drivers\ComposeDriver;
use App\Services\Sandbox\Drivers\ImageDriver;
use App\Services\Sandbox\SandboxService;
use App\Services\Sandbox\Support\WebServiceResolver;
use App\Services\Target\Probes\DatabaseProbe;
use App\Services\Target\Probes\HealthEndpointProbe;
use App\Services\Target\Probes\HttpReachableProbe;
use App\Services\Target\RemoteTargetService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->registerSandbox();
        $this->registerRemoteTarget();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * L'ordine dei driver è la priorità di selezione: ImageDriver::supports()
     * risponde sempre true, quindi deve restare l'ultimo.
     */
    protected function registerSandbox(): void
    {
        $this->app->singleton(DockerClient::class, fn (): DockerClient => new DockerClient(
            baseUri: config('sandbox.docker.api'),
            timeout: (int) config('sandbox.docker.timeout'),
            buildTimeout: (int) config('sandbox.docker.build_timeout'),
            buildExclude: config('sandbox.build_exclude', []),
        ));

        $this->app->singleton(SandboxService::class, fn (Application $app): SandboxService => new SandboxService(
            resolver: $app->make(WebServiceResolver::class),
            docker: $app->make(DockerClient::class),
            drivers: [
                $app->make(ComposeDriver::class),
                $app->make(ImageDriver::class),
            ],
        ));
    }

    /**
     * L'ordine dei probe è quello in cui compaiono nel report: prima si accerta
     * che qualcuno risponda, poi che l'app si dichiari sana, poi il database.
     */
    protected function registerRemoteTarget(): void
    {
        $this->app->singleton(RemoteTargetService::class, fn (Application $app): RemoteTargetService => new RemoteTargetService(
            probes: [
                $app->make(HttpReachableProbe::class),
                $app->make(HealthEndpointProbe::class),
                $app->make(DatabaseProbe::class),
            ],
        ));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
