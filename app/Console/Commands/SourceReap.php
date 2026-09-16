<?php

namespace App\Console\Commands;

use App\Models\SourceRevision;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class SourceReap extends Command
{
    protected $signature = 'source:reap';

    protected $description = 'Rimuove i sorgenti importati scaduti senza toccare preparazioni o audit attivi';

    public function handle(): int
    {
        $activePreparations = ['queued', 'discovering', 'awaiting_input', 'preparing', 'diagnosing'];
        $activeAudits = ['queued', 'preparing', 'running', 'finalizing'];
        $revisions = SourceRevision::query()
            ->where('status', 'ready')
            ->where('imported_at', '<', now()->subDays((int) config('audits.imports.retention_days', 7)))
            ->whereDoesntHave('preparations', fn ($query) => $query->whereIn('status', $activePreparations))
            ->whereDoesntHave('audits', fn ($query) => $query->whereIn('status', $activeAudits))
            ->get();
        foreach ($revisions as $revision) {
            if (is_string($revision->source_path)) {
                File::deleteDirectory($revision->source_path);
            }
            $revision->update(['status' => 'expired', 'source_path' => null]);
        }
        $this->info($revisions->count().' revisioni sorgente scadute rimosse.');

        return self::SUCCESS;
    }
}
