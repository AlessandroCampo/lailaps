<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    use HasUlids, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['lab_manifest' => 'array'];
    }

    public function storageDirectory(): string
    {
        return (string) $this->id;
    }

    public function artifactsPath(): string
    {
        return $this->storageDirectory().'/artifacts';
    }

    public function workspacePath(): string
    {
        return $this->storageDirectory().'/workspace';
    }

    public function latestSource(): HasOne
    {
        return $this->hasOne(ProjectSource::class)->latestOfMany();
    }

    public function sources(): HasMany
    {
        return $this->hasMany(ProjectSource::class);
    }

    public function labs(): HasMany
    {
        return $this->hasMany(Lab::class);
    }

    public function auditRuns(): HasMany
    {
        return $this->hasMany(AuditRun::class);
    }

    public function activeLab(): HasOne
    {
        return $this->hasOne(Lab::class)->latestOfMany();
    }
}
