<?php

namespace App\Models;

use App\Enums\AgentSessionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentSession extends Model
{
    use HasUuids, MassPrunable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'paths' => 'array',
            'output' => 'array',
            'environment' => 'encrypted:array',
            'runtime_config' => 'encrypted',
            'expires_at' => 'datetime',
            'status' => AgentSessionStatus::class,
        ];
    }

    public function appendMessage(string $text): void
    {
        $message = json_encode(['role' => 'user', 'content' => $text], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $existing = rtrim(trim($this->messages ?? '[]'), ']');
        $this->update(['messages' => $existing.($existing === '[' ? '' : ',').$message.']']);
    }

    public function prunable(): Builder
    {
        return static::where('expires_at', '<', now())->where('status', '!=', AgentSessionStatus::RUNNING)->whereNull('runtime_config')->whereDoesntHave('labs');
    }

    public function labs(): HasMany
    {
        return $this->hasMany(Lab::class, 'doctor_session_id')->withTrashed();
    }
}
