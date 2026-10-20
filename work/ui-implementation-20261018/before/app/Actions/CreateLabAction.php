<?php

namespace App\Actions;

use App\Enums\LabStatus;
use App\Enums\ProjectSourceStatus;
use App\Models\Lab;
use App\Models\ProjectSource;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class CreateLabAction
{
    public function handle(ProjectSource $source): Lab
    {
        if ($source->status !== ProjectSourceStatus::READY
            || ! Storage::disk('projects')->directoryExists($source->sourcePath())) {
            throw ValidationException::withMessages([
                'source' => 'Select a ready source with an available snapshot.',
            ]);
        }

        return Lab::create([
            'project_id' => $source->project_id,
            'project_source_id' => $source->id,
            'status' => LabStatus::PENDING,
        ]);
    }
}
