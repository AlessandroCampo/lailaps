<?php

namespace App\Http\Requests;

use App\Services\Audit\AuditCommandBuilder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreAuditRunRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['audit', 'benchmark'])],
            'preset' => ['nullable', Rule::in(array_keys(AuditCommandBuilder::PRESETS))],
            'path' => ['nullable', 'string', 'max:2048'],
            'target_mode' => ['nullable', Rule::in(['sandbox', 'remote'])],
            'url' => ['nullable', 'url:http,https', 'max:2048'],
            'db' => ['nullable', 'string', 'max:2048'],
            'health_path' => ['nullable', 'string', 'max:512'],
            'skip_health' => ['boolean'],
            'authorized' => ['boolean'],
            'categories' => ['array', 'max:2'],
            'categories.*' => ['string', 'max:160', 'distinct'],
            'reader_model' => ['nullable', 'string', 'max:255'],
            'reviewer_model' => ['nullable', 'string', 'max:255'],
            'confirmer_model' => ['nullable', 'string', 'max:255'],
            'worker_model' => ['nullable', 'string', 'max:255'],
            'image' => ['nullable', 'string', 'max:255'],
            'dockerfile' => ['nullable', 'string', 'max:2048'],
            'compose' => ['nullable', 'string', 'max:2048'],
            'mount' => ['nullable', 'string', 'max:1024'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'service' => ['nullable', 'string', 'max:255'],
            'ttl' => ['required', 'integer', 'between:60,86400'],
            'test' => ['boolean'],
            'keep' => ['boolean'],
            'tool_output' => ['boolean'],
            'benchmark_id' => ['nullable', 'string', 'regex:/^[a-z0-9][a-z0-9._-]+$/'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ((string) $this->input('type') === 'benchmark') {
                if (! $this->filled('benchmark_id')) {
                    $validator->errors()->add('benchmark_id', 'Seleziona un benchmark.');
                }
                if (count((array) $this->input('categories', [])) > 1) {
                    $validator->errors()->add('categories', 'Puoi selezionare una sola sotto-categoria per benchmark.');
                }

                return;
            }
            if (! $this->filled('preset') && ! $this->filled('path')) {
                $validator->errors()->add('path', 'Indica un preset o un path sorgente.');
            }
            if ((string) $this->input('target_mode') === 'remote') {
                if (! $this->filled('url')) {
                    $validator->errors()->add('url', 'L URL remoto è obbligatorio.');
                }
                if (! $this->boolean('authorized')) {
                    $validator->errors()->add('authorized', 'Devi attestare di essere autorizzato.');
                }
            }
            if ($this->filled('path') && ! $this->sourcePathAllowed((string) $this->input('path'))) {
                $validator->errors()->add('path', 'Il path non esiste o non rientra nelle radici consentite.');
            }
        }];
    }

    /** @return array<string, mixed> */
    public function normalized(): array
    {
        return array_replace([
            'preset' => null, 'path' => null, 'target_mode' => 'sandbox', 'url' => null,
            'db' => null, 'health_path' => null, 'skip_health' => false, 'authorized' => false,
            'categories' => [], 'reader_model' => null, 'reviewer_model' => null,
            'confirmer_model' => null,
            'worker_model' => null, 'image' => null, 'dockerfile' => null, 'compose' => null,
            'mount' => null, 'port' => null, 'service' => null, 'ttl' => 9000,
            'test' => true, 'keep' => false, 'tool_output' => false, 'benchmark_id' => null,
        ], $this->validated());
    }

    private function sourcePathAllowed(string $path): bool
    {
        $candidate = realpath($path) ?: realpath(base_path($path));
        if ($candidate === false || ! is_dir($candidate)) {
            return false;
        }
        $roots = (array) config('audits.allowed_source_roots', []);
        if ($roots === []) {
            $roots = [base_path('targets')];
        }
        foreach ($roots as $root) {
            $resolved = realpath((string) $root);
            if ($resolved !== false && ($candidate === $resolved || str_starts_with($candidate, $resolved.DIRECTORY_SEPARATOR))) {
                return true;
            }
        }

        return false;
    }
}
