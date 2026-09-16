<?php

namespace App\Http\Controllers;

use App\Jobs\ExecuteAuditRun;
use App\Jobs\RunPreparation;
use App\Models\Preparation;
use App\Models\Project;
use App\Models\SourceRevision;
use App\Services\Audit\AuditRunFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class PreparationController extends Controller
{
    public function store(Request $request, Project $project): RedirectResponse
    {
        $this->authorizeProject($request, $project);
        $validated = $request->validate([
            'source_revision_id' => ['required', Rule::exists('source_revisions', 'id')->where('project_id', $project->id)],
            'based_on_id' => ['nullable', Rule::exists('preparations', 'id')->where('project_id', $project->id)],
            'configuration' => ['nullable', 'array:service,health_path,compose_file,dockerfile,audit_profile'],
            'configuration.service' => ['nullable', 'string', 'max:255'],
            'configuration.health_path' => ['nullable', 'string', 'max:512'],
            'configuration.compose_file' => ['nullable', 'string', 'max:1024', 'not_regex:/\.\./'],
            'configuration.dockerfile' => ['nullable', 'string', 'max:1024', 'not_regex:/\.\./'],
            'configuration.audit_profile' => ['nullable', 'string', 'max:1024', 'not_regex:/\.\./'],
        ]);
        $revision = SourceRevision::query()->findOrFail($validated['source_revision_id']);
        abort_unless($revision->status === 'ready', 409, 'La revisione non e ancora pronta.');
        $basedOn = isset($validated['based_on_id']) ? Preparation::query()->find($validated['based_on_id']) : null;
        $preparation = $project->preparations()->create([
            'source_revision_id' => $revision->id,
            'based_on_id' => $basedOn?->id,
            'status' => 'queued',
            'configuration' => array_replace((array) ($basedOn?->configuration ?? []), (array) ($validated['configuration'] ?? [])),
            'answers' => $basedOn?->answers ?? [],
            'summary' => $basedOn?->summary,
        ]);
        RunPreparation::dispatch($preparation->id);

        return to_route('preparations.show', $preparation);
    }

    public function show(Request $request, Preparation $preparation): Response
    {
        $this->authorizePreparation($request, $preparation);

        return Inertia::render('preparations/Show', ['preparation' => $this->payload($preparation->load('revision', 'project'))]);
    }

    public function status(Request $request, Preparation $preparation): JsonResponse
    {
        $this->authorizePreparation($request, $preparation);

        return response()->json($this->payload($preparation->fresh(['revision', 'project'])), headers: ['Cache-Control' => 'no-store, private']);
    }

    public function answer(Request $request, Preparation $preparation): RedirectResponse
    {
        $this->authorizePreparation($request, $preparation);
        abort_unless($preparation->status === 'awaiting_input', 409, 'La preparazione non attende risposte.');
        $answers = $request->validate(['answers' => ['required', 'array']])['answers'];
        $accepted = [];
        $secretKeys = (array) data_get($preparation->configuration, 'secret_keys', []);
        foreach ((array) $preparation->questions as $question) {
            $key = (string) ($question['key'] ?? '');
            $value = $answers[$key] ?? null;
            if (! is_string($value) || trim($value) === '' || mb_strlen($value) > 10000) {
                return back()->withErrors(["answers.{$key}" => 'Risposta obbligatoria o non valida.']);
            }
            if (($question['kind'] ?? null) === 'choice' && ! in_array($value, (array) ($question['options'] ?? []), true)) {
                return back()->withErrors(["answers.{$key}" => 'Scelta non valida.']);
            }
            $accepted[$key] = $value;
            if (($question['kind'] ?? null) === 'secret') {
                $secretKeys[] = $key;
            }
        }
        $configuration = (array) $preparation->configuration;
        $configuration['secret_keys'] = array_values(array_unique($secretKeys));
        $preparation->update([
            'answers' => array_replace((array) $preparation->answers, $accepted),
            'configuration' => $configuration,
            'status' => 'queued',
            'failure_reason' => null,
        ]);
        RunPreparation::dispatch($preparation->id);

        return back();
    }

    public function cancel(Request $request, Preparation $preparation): RedirectResponse
    {
        $this->authorizePreparation($request, $preparation);
        abort_if(in_array($preparation->status, ['ready', 'blocked', 'failed', 'cancelled', 'timed_out'], true), 409);
        $preparation->update(['cancellation_requested' => true]);

        return back();
    }

    public function audit(Request $request, Preparation $preparation, AuditRunFactory $factory): RedirectResponse
    {
        $this->authorizePreparation($request, $preparation);
        abort_unless($preparation->status === 'ready', 409, 'La preparazione non e pronta.');
        $validated = $request->validate(['categories' => ['nullable', 'array', 'max:2'], 'categories.*' => ['string', 'max:160']]);
        $revision = $preparation->revision;
        $configuration = (array) $preparation->configuration;
        $runtimeRoot = rtrim((string) $preparation->runtime_path, '/');
        if ($revision->application_subdirectory) {
            $runtimeRoot .= '/'.trim($revision->application_subdirectory, '/');
        }
        $absolute = static fn (?string $path): ?string => $path ? $runtimeRoot.'/'.trim($path, '/\\') : null;
        $parameters = [
            'type' => 'audit', 'preset' => null, 'path' => $revision->sourceRoot(), 'runtime_path' => $runtimeRoot,
            'project_name' => $preparation->project->name, 'target_mode' => 'sandbox', 'url' => null, 'db' => null,
            'health_path' => $configuration['health_path'] ?? null, 'skip_health' => false, 'authorized' => false,
            'categories' => array_values((array) ($validated['categories'] ?? [])), 'image' => null,
            'dockerfile' => $configuration['dockerfile'] ?? null, 'compose' => $absolute($configuration['compose_file'] ?? null),
            'audit_profile' => $absolute($configuration['audit_profile'] ?? null), 'mount' => null, 'port' => null,
            'service' => $configuration['service'] ?? null, 'ttl' => (int) config('sandbox.defaults.ttl'),
            'test' => false, 'keep' => false, 'tool_output' => false, 'benchmark_id' => null,
        ];
        $run = $factory->create($request->user(), $parameters, $revision, $preparation);
        ExecuteAuditRun::dispatch($run->id);

        return to_route('audits.show', $run);
    }

    /** @return array<string, mixed> */
    private function payload(Preparation $preparation): array
    {
        $answers = (array) $preparation->answers;
        $questions = collect((array) $preparation->questions)->map(function (array $question) use ($answers): array {
            $question['answered'] = isset($answers[$question['key'] ?? '']);

            return $question;
        })->values()->all();

        return [
            'id' => $preparation->id, 'status' => $preparation->status, 'summary' => $preparation->summary,
            'questions' => $questions, 'configuration' => collect((array) $preparation->configuration)->except('secret_keys')->all(), 'actors' => $preparation->actors,
            'capabilities' => $preparation->capabilities, 'doctorUsage' => $preparation->doctor_usage,
            'activeSeconds' => $preparation->active_seconds, 'failureReason' => $preparation->failure_reason,
            'fingerprint' => $preparation->fingerprint, 'project' => ['id' => $preparation->project->id, 'name' => $preparation->project->name],
            'revision' => ['id' => $preparation->revision->id, 'provider' => $preparation->revision->provider, 'hash' => $preparation->revision->content_hash],
        ];
    }

    private function authorizeProject(Request $request, Project $project): void
    {
        abort_unless($project->user_id === $request->user()->id, 404);
    }

    private function authorizePreparation(Request $request, Preparation $preparation): void
    {
        abort_unless($preparation->project()->where('user_id', $request->user()->id)->exists(), 404);
    }
}
