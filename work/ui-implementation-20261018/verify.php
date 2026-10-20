<?php
require '/home/alessandro/projects/lailaps-2/vendor/autoload.php';
$app = require '/home/alessandro/projects/lailaps-2/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\{User, Project, ProjectSource, Lab, AuditRun, ProjectArtifact};
use App\Services\{OutcomeService, AuditRunPresenter, DockerService};
use App\Actions\{CreateAuditRunAction, CreateLabAction};
use App\DTOs\AuditRun\RunOutcome;
use App\Http\Controllers\{AuditRunController, ProjectSourceController};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Storage, Bus, Process};

$disk = Storage::disk('projects');
$owner = User::where('email', 'test@example.com')->firstOrFail();
$request = Request::create('/');
$request->setUserResolver(fn () => $owner);
$outcomes = app(OutcomeService::class);
$presenter = app(AuditRunPresenter::class);
$metaPath = __DIR__.'/fixtures.json';
$created = [];
function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS $message\n";
}
function projectFixture(string $name): array {
    global $owner, $disk, $created;
    $project = Project::create(['user_id' => $owner->id, 'name' => 'UI verification · '.$name]);
    $created[] = $project->id;
    $source = ProjectSource::create(['project_id' => $project->id, 'source_type' => 'zip', 'status' => 'ready', 'content_hash' => str_repeat('a', 64), 'uploaded_at' => now()]);
    $disk->put($source->sourcePath().'/app/Http/Controllers/InvoiceController.php', "<?php\n\nclass InvoiceController\n{\n    public function show(\$invoice)\n    {\n        return Invoice::find(\$invoice);\n    }\n}\n".str_repeat("\n", 55));
    $lab = Lab::create(['project_id' => $project->id, 'project_source_id' => $source->id, 'status' => 'ready']);
    foreach (['static_analysis', 'code_index', 'dependency_analysis', 'recon'] as $type) {
        ProjectArtifact::create(['project_source_id' => $source->id, 'type' => $type, 'status' => 'ready', 'payload' => []]);
    }
    return [$project, $source, $lab];
}
function scanFixture(array $project, string $status = 'running'): AuditRun {
    return AuditRun::create(['project_id' => $project[0]->id, 'project_source_id' => $project[1]->id, 'lab_id' => $project[2]->id, 'status' => $status, 'started_at' => $status === 'pending' ? null : now()->subMinutes(6)]);
}
function rawFixture(AuditRun $run, string $status = 'suspected'): array {
    $row = ['lead_id' => 'lead-1', 'title' => 'Invoice accessible across accounts', 'status' => $status, 'lifecycle_status' => $status === 'suspected' ? 'refining' : $status, 'severity' => $status === 'suspected' ? null : 'high', 'description' => 'The invoice lookup does not apply an account ownership filter.', 'investigator_output' => ['narrative' => 'A second account can access an invoice outside its account.'], 'recommendation' => 'Enforce ownership before returning an invoice.', 'owasp_category' => 'Broken access control', 'cwe_ids' => ['CWE-639'], 'primary_location' => ['file' => 'app/Http/Controllers/InvoiceController.php', 'line' => 5, 'end_line' => 60]];
    return ['run_id' => $run->id, 'report' => ['confirmed' => $status === 'confirmed' ? [$row] : [], 'suspected' => $status === 'suspected' ? [$row] : [], 'rejected_inconclusive' => [], 'telemetry' => ['economic_points_used' => 120000, 'economic_point_limit' => 1000000, 'model_requests' => 8, 'total_model_input_tokens' => 24000, 'total_model_output_tokens' => 3000], 'coverage' => ['complete' => false], 'evidence_ledger' => ['http_transactions' => [['lead_id' => 'lead-1', 'request_id' => 'req-1', 'response_id' => 'res-1', 'method' => 'GET', 'path' => '/invoices/42', 'status' => 200, 'request' => ['actor' => 'second-account'], 'body_excerpt' => '{"id":42,"account_id":7}', 'body_truncated' => true]]]]];
}
function writeRaw(AuditRun $run, array $raw): void {
    global $disk;
    $disk->put($run->path().'/output/outcome.json', json_encode($raw, JSON_THROW_ON_ERROR));
}
function cleanup(array $ids): void {
    global $disk;
    foreach ($ids as $id) {
        $project = Project::withTrashed()->find($id);
        if (!$project || !str_starts_with($project->name, 'UI verification · ')) continue;
        AuditRun::where('project_id', $id)->delete();
        $project->forceDelete();
        $root = realpath($disk->path(''));
        $target = realpath($disk->path($id));
        if ($root !== false && $target !== false && dirname($target) === $root) $disk->deleteDirectory($id);
    }
}
$mode = $argv[1] ?? 'verify';
if ($mode === 'cleanup') {
    cleanup(json_decode(file_get_contents($metaPath), true)['projects']);
    unlink($metaPath);
    echo "Temporary records and snapshots removed.\n";
    exit;
}
if ($mode === 'advance') {
    $meta = json_decode(file_get_contents($metaPath), true);
    $run = AuditRun::findOrFail($meta['liveRun']);
    $raw = rawFixture($run, 'confirmed');
    $raw['report']['telemetry']['economic_points_used'] = 370000;
    writeRaw($run, $raw);
    $outcomes->captureCheckpoint($run);
    echo "Live checkpoint advanced.\n";
    exit;
}
if ($mode === 'finalize') {
    $meta = json_decode(file_get_contents($metaPath), true);
    $run = AuditRun::findOrFail($meta['liveRun']);
    $raw = rawFixture($run, 'confirmed');
    $raw += ['schema_version' => 1, 'project_source_id' => $run->project_source_id, 'source_hash' => $run->source->content_hash, 'run_status' => 'partial'];
    writeRaw($run, $raw);
    $run->update(['status' => 'partial']);
    $outcomes->persist($run, RunOutcome::fromArray($raw));
    $run->update(['finished_at' => now()]);
    $outcomes->phase($run, 'Scan finished with partial results');
    echo "Live fixture finalized.\n";
    exit;
}
DB::beginTransaction();
try {
    $fixture = projectFixture('offline replay');
    $run = scanFixture($fixture);
    check($presenter->summary($run, $request)['lead_counts'] === null, 'counts unknown before checkpoint');
    $raw = rawFixture($run);
    writeRaw($run, $raw);
    check($outcomes->captureCheckpoint($run), 'intermediate envelope imported without final-only keys');
    check($run->leads()->first()->status->value === 'running', 'admitted active lead is investigating');
    $version = $run->progress_version;
    check(!$outcomes->captureCheckpoint($run) && $run->progress_version === $version && $run->leads()->count() === 1, 'checkpoint replay is idempotent');
    $raw = rawFixture($run, 'confirmed');
    $raw['report']['telemetry']['economic_points_used'] = 300000;
    writeRaw($run, $raw);
    $outcomes->captureCheckpoint($run);
    $summary = $presenter->summary($run, $request);
    check($summary['lead_counts']['confirmed'] === 1 && $summary['usage']['points_used'] === 300000 && !$summary['has_report'], 'verdict and absolute usage update before completion');
    $lead = $run->leads()->first();
    $detail = $outcomes->presentStoredLead($run, $lead);
    check($detail['locations'][0]['snippet']['available'] && $detail['locations'][0]['snippet']['truncated'], 'bounded source preview available and marked truncated');
    check($detail['evidence']['http_transactions'][0]['available'], 'HTTP evidence reconstructed on demand');
    $fixture[1]->delete();
    $run->unsetRelation('source');
    check($outcomes->presentStoredLead($run, $lead)['locations'][0]['snippet']['available'], 'archived source stays pinned to scan');
    $raw['source_hash'] = 'wrong-source';
    writeRaw($run, $raw);
    try { $outcomes->captureCheckpoint($run); throw new LogicException('identity accepted'); } catch (RuntimeException $error) { check($error->getMessage() !== 'identity accepted', 'mismatched checkpoint identity rejected'); }
    $raw = rawFixture($run, 'confirmed');
    $raw += ['schema_version' => 1, 'project_source_id' => $run->project_source_id, 'source_hash' => $run->source->content_hash, 'run_status' => 'partial'];
    $raw['report']['confirmed'] = [];
    $raw['report']['rejected_inconclusive'] = [['lead_id' => 'lead-1', 'title' => 'Rejected hypothesis', 'status' => 'rejected']];
    writeRaw($run, $raw);
    $run->update(['status' => 'partial']);
    $outcomes->persist($run, RunOutcome::fromArray($raw));
    check($run->leads()->count() === 1 && !$run->leads()->first()->is_current && $presenter->summary($run, $request)['findings_count'] === 0, 'final withdrawal retained but excluded from counts');
    check(!$presenter->summary($run, $request)['has_report'], 'report hidden while finalizing');
    $run->update(['finished_at' => now()]);
    check($presenter->summary($run, $request)['has_report'], 'report available after finalization');
    $run->update(['progress' => null]);
    check($presenter->summary($run, $request)['usage']['points_used'] === 120000, 'older final reports expose usage without checkpoint metadata');
    $controller = app(AuditRunController::class);
    $response = $controller->download($request, $fixture[0], $run->refresh(), $presenter);
    check(json_decode($response->getContent(), true)['rejected_leads'] === 1, 'JSON export uses saved report');
    $other = projectFixture('foreign project');
    try { $controller->show($request, $other[0], $run, $presenter); throw new LogicException('foreign run accepted'); } catch (Symfony\Component\HttpKernel\Exception\HttpException $error) { check($error->getStatusCode() === 404, 'cross-project run denied'); }
    $foreignUser = new User(); $foreignUser->id = $owner->id + 100000;
    $request->setUserResolver(fn () => $foreignUser);
    try { $controller->report($request, $fixture[0], $run, $presenter); throw new LogicException('foreign owner accepted'); } catch (Symfony\Component\HttpKernel\Exception\HttpException $error) { check($error->getStatusCode() === 404, 'cross-owner report denied'); }
    $request->setUserResolver(fn () => $owner);
    Bus::fake();
    $ready = projectFixture('start');
    $createdRun = app(CreateAuditRunAction::class)->handle($ready[1], []);
    check($createdRun->status->value === 'pending', 'real create action queues scan state');
    try { app(CreateAuditRunAction::class)->handle($ready[1], []); throw new LogicException('duplicate accepted'); } catch (Illuminate\Validation\ValidationException) { check(true, 'duplicate start denied'); }
    $start = projectFixture('start redirect');
    $redirect = $controller->store($request, $start[0], $start[1], app(CreateAuditRunAction::class));
    $queued = $start[0]->auditRuns()->firstOrFail();
    check(str_ends_with($redirect->getTargetUrl(), '/audit-runs/'.$queued->id), 'real POST redirects to permanent scan page');
    Bus::assertDispatched(App\Jobs\RunAudit::class);
    $replacement = projectFixture('replacement');
    $replacement[2]->forceDelete();
    app(ProjectSourceController::class)->retrySetup($request, $replacement[0], $replacement[1], app(CreateLabAction::class));
    check($replacement[1]->lab()->exists(), 'replacement source can create missing preparation lab');
    Bus::assertDispatched(App\Jobs\LabSetup::class);
    Bus::assertDispatched(App\Jobs\PrepareProjectAnalysis::class);
    check(true, 'preparation schedules existing jobs only');
    $failed = scanFixture($other);
    writeRaw($failed, rawFixture($failed));
    $outcomes->captureCheckpoint($failed);
    $failed->update(['status' => 'failed', 'finished_at' => now()]);
    check($presenter->summary($failed, $request)['has_report'] && count($presenter->report($failed)['findings']) === 1, 'failed scan retains checkpoint and unfinished lead');
    $unfinished = scanFixture($other);
    $unfinished->update(['status' => 'completed']);
    (new App\Jobs\RunAudit($unfinished->id))->failed(null);
    check($unfinished->refresh()->finished_at !== null && $unfinished->status->value === 'failed', 'job crash during finalization does not leave an infinite live scan');
} finally {
    DB::rollBack();
    foreach ($created as $id) {
        $root = realpath($disk->path(''));
        $target = realpath($disk->path($id));
        if ($root !== false && $target !== false && dirname($target) === $root) $disk->deleteDirectory($id);
    }
}
if ($mode !== 'prepare') exit;
$created = [];
$empty = projectFixture('First scan');
$live = projectFixture('Live scan');
$liveRun = scanFixture($live);
$outcomes->phase($liveRun, 'Investigating project');
writeRaw($liveRun, rawFixture($liveRun));
$outcomes->captureCheckpoint($liveRun);
$done = projectFixture('Results');
$doneRun = scanFixture($done);
$raw = rawFixture($doneRun, 'confirmed');
$raw['report']['rejected_inconclusive'] = [
    ['lead_id' => 'lead-2', 'status' => 'blocked', 'lifecycle_status' => 'blocked', 'title' => 'Private export endpoint could not be validated', 'reason' => 'An authenticated session for this role was unavailable.', 'primary_location' => ['file' => 'missing.php', 'line' => 1]],
    ['lead_id' => 'lead-3', 'status' => 'inconclusive', 'title' => 'Upload policy requires further review', 'reason' => 'The observed response did not establish whether the upload was stored.'],
];
$raw += ['schema_version' => 1, 'project_source_id' => $doneRun->project_source_id, 'source_hash' => $doneRun->source->content_hash, 'run_status' => 'partial'];
writeRaw($doneRun, $raw);
$doneRun->update(['status' => 'partial']);
$outcomes->persist($doneRun, RunOutcome::fromArray($raw));
$doneRun->update(['finished_at' => now()]);
$outcomes->phase($doneRun, 'Scan finished with partial results');
$ids = ['projects' => $created, 'empty' => $empty[0]->id, 'live' => $live[0]->id, 'liveRun' => $liveRun->id, 'done' => $done[0]->id, 'doneRun' => $doneRun->id, 'finding' => $doneRun->leads()->where('status', 'confirmed')->first()->id, 'blocked' => $doneRun->leads()->where('status', 'blocked')->first()->id];
file_put_contents($metaPath, json_encode($ids, JSON_PRETTY_PRINT));
echo "Browser fixtures ready.\n";
