<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\GeneratorCommand;


#[Signature('app:enum-make-command')]
#[Description('Command description')]


class EnumMakeCommand extends GeneratorCommand
{
    protected $signature = 'make:enum
        {name : The name of the enum}
        {--force : Overwrite the enum if it exists}';

    protected $description = 'Create a new enum class';

    protected $type = 'Enum';

    protected function getStub()
    {
        return base_path('stubs/enum.stub');
    }

    protected function getDefaultNamespace($rootNamespace)
    {
        return $rootNamespace . '\Enums';
    }
}