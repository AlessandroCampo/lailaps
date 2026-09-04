<?php

namespace App\Console\Commands;

use App\Models\BenchmarkStageArtifact;
use App\Services\Pentest\BenchmarkStageArtifactRegistry;
use Illuminate\Console\Command;

final class BenchmarkArtifactLabel extends Command
{
    protected $signature = 'benchmark:artifact:label
        {artifact-id : Artifact ReaderLead da classificare}
        {label : benchmark_positive, novel_valid, duplicate, false_positive, unresolved, out_of_scope}
        {--case-id= : Case benchmark associata}
        {--canonical= : Artifact canonico per una duplicate}
        {--notes= : Nota manuale}
        {--label-set=manual-v1 : Versione del set di label}';

    protected $description = 'Classifica manualmente una ReaderLead benchmark senza alterare il payload originale';

    public function handle(BenchmarkStageArtifactRegistry $registry): int
    {
        $artifact = BenchmarkStageArtifact::query()->findOrFail((string) $this->argument('artifact-id'));
        $canonical = $this->option('canonical')
            ? BenchmarkStageArtifact::query()->findOrFail((string) $this->option('canonical'))
            : null;
        $updated = $registry->label(
            $artifact,
            (string) $this->argument('label'),
            $this->option('case-id') ? (string) $this->option('case-id') : null,
            $this->option('notes') ? (string) $this->option('notes') : null,
            $canonical,
            (string) $this->option('label-set'),
        );
        $this->info("Artifact {$updated->id}: label={$updated->label}, canonical=".($updated->is_canonical ? 'yes' : 'no'));

        return self::SUCCESS;
    }
}
