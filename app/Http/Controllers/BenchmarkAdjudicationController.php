<?php

namespace App\Http\Controllers;

use App\Models\BenchmarkEvaluation;
use App\Services\Pentest\BenchmarkAdjudicator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class BenchmarkAdjudicationController extends Controller
{
    public function __invoke(Request $request, BenchmarkEvaluation $evaluation, BenchmarkAdjudicator $adjudicator): RedirectResponse
    {
        abort_unless($evaluation->run->user_id === $request->user()->id, 404);
        $data = $request->validate([
            'fingerprint' => ['required', 'string', 'size:64'],
            'decision' => ['required', Rule::in(BenchmarkAdjudicator::DECISIONS)],
            'case_id' => ['nullable', 'string', 'max:255'],
            'comment' => ['nullable', 'string', 'max:4000'],
        ]);
        $adjudicator->adjudicate($evaluation, $request->user(), $data['fingerprint'], $data['decision'], $data['case_id'] ?? null, $data['comment'] ?? null);

        return back();
    }
}
