<?php

namespace App\Console\Commands;

use App\Models\BenchmarkStageArtifact;
use Illuminate\Console\Command;

final class BenchmarkArtifactList extends Command
{
    protected $signature = 'benchmark:artifact:list
        {project-key : Project key degli artifact}
        {--role= : recon, reader o confirmer}
        {--category= : Benchmark category}
        {--unlabelled : Mostra solo artifact senza classificazione}
        {--golden : Mostra solo la Golden Recon}';

    protected $description = 'Elenca gli artifact benchmark project-scoped e il relativo lineage';

    public function handle(): int
    {
        $query = BenchmarkStageArtifact::query()
            ->where('project_key', (string) $this->argument('project-key'));
        if ($this->option('role')) {
            $query->where('role', (string) $this->option('role'));
        }
        if ($this->option('category')) {
            $query->where('category', (string) $this->option('category'));
        }
        if ($this->option('unlabelled')) {
            $query->whereNull('label');
        }
        if ($this->option('golden')) {
            $query->where('is_golden', true);
        }
        $rows = $query->latest()->limit(200)->get()->map(fn (BenchmarkStageArtifact $artifact): array => [
            $artifact->id,
            $artifact->category,
            $artifact->role,
            $artifact->output_type,
            $artifact->status,
            $artifact->accepted ? 'yes' : 'no',
            $artifact->is_golden ? 'yes' : 'no',
            $artifact->label ?? '-',
            $artifact->matched_case_id ?? '-',
            $artifact->parent_artifact_id ?? '-',
        ])->all();
        $this->table(
            ['Artifact', 'Category', 'Role', 'Type', 'Status', 'Accepted', 'Golden', 'Label', 'Case', 'Parent'],
            $rows,
        );

        return self::SUCCESS;
    }
}
