<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('benchmark_role_evaluations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('benchmark_evaluation_id')->constrained('benchmark_evaluations')->cascadeOnDelete();
            $table->foreignUlid('audit_run_id')->constrained('audit_runs')->cascadeOnDelete();
            $table->string('role', 32)->index();
            $table->string('model')->nullable()->index();
            $table->string('reasoning_effort', 24)->nullable();
            $table->string('status', 24)->index();
            $table->string('attribution', 32)->default('observational')->index();
            $table->string('evaluator_version', 32);
            $table->string('objective_version', 32);
            $table->string('objective_signature', 64)->index();
            $table->string('comparison_signature', 64)->index();
            $table->float('score')->nullable()->index();
            $table->unsignedInteger('sample_size')->default(0);
            $table->unsignedInteger('true_positives')->default(0);
            $table->unsignedInteger('false_positives')->default(0);
            $table->unsignedInteger('false_negatives')->default(0);
            $table->unsignedInteger('model_requests')->default(0);
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('cached_input_tokens')->default(0);
            $table->unsignedBigInteger('uncached_input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('total_tokens')->default(0)->index();
            $table->float('cache_hit_rate')->default(0);
            $table->decimal('provider_cost_usd', 16, 10)->nullable();
            $table->decimal('estimated_cost_usd', 16, 10)->nullable();
            $table->float('true_positives_per_1k_tokens')->nullable();
            $table->json('objective');
            $table->json('metrics');
            $table->json('usage');
            $table->json('pricing')->nullable();
            $table->timestamps();

            $table->unique(
                ['benchmark_evaluation_id', 'role', 'evaluator_version'],
                'benchmark_role_evaluation_identity',
            );
        });

        Schema::create('benchmark_role_case_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('benchmark_role_evaluation_id')
                ->constrained('benchmark_role_evaluations')->cascadeOnDelete();
            $table->string('case_id');
            $table->boolean('expected_positive');
            $table->boolean('reached');
            $table->float('score')->default(0);
            $table->json('payload')->nullable();
            $table->timestamps();
            $table->unique(['benchmark_role_evaluation_id', 'case_id'], 'benchmark_role_case_identity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('benchmark_role_case_results');
        Schema::dropIfExists('benchmark_role_evaluations');
    }
};
