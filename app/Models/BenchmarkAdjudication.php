<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class BenchmarkAdjudication extends Model
{
    protected $fillable = ['benchmark_evaluation_id', 'user_id', 'finding_fingerprint', 'decision', 'case_id', 'comment'];

    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(BenchmarkEvaluation::class);
    }
}
