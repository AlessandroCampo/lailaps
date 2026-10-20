<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\GeneratorCommand;


#[Signature('app:service-make-command')]
#[Description('Command description')]


class ServiceMakeCommand extends GeneratorCommand
{
    protected $signature = 'make:service
        {name : The name of the service}
        {--force : Overwrite the service if it exists}';

    protected $description = 'Create a new service class';

    protected $type = 'Service';

    protected function getStub()
    {
        return base_path('stubs/service.stub');
    }

    protected function getDefaultNamespace($rootNamespace)
    {
        return $rootNamespace . '\Services';
    }
}