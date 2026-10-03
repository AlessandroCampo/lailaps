<?php
// Read-only lookup scoped to the exact freshly produced run; never select "latest".
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$rows = App\Models\BenchmarkStageArtifact::query()
    ->where('project_key', 'cacti')->where('run_id', $argv[1] ?? '')
    ->where('source_commit', '6482af547c204199e829b7a0df0b7a13db3e0a58')
    ->where('role', 'recon')->where('output_type', 'CategoryRecon')
    ->where('accepted', true)->get();
if ($rows->count() !== 1 || data_get($rows[0]->payload, 'status') !== 'ready') {
    fwrite(STDERR, "Expected exactly one accepted ready Recon for this run.\n");
    exit(1);
}
echo $rows[0]->id;
