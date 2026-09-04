<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('benchmark_experiments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('status', 24)->default('queued')->index();
            $table->json('definition');
            $table->string('harness_revision', 64)->nullable()->index();
            $table->boolean('reproducible')->default(true)->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::table('audit_runs', function (Blueprint $table): void {
            $table->foreignUlid('benchmark_experiment_id')->nullable()->after('user_id')
                ->constrained('benchmark_experiments')->nullOnDelete();
            $table->unsignedSmallInteger('repetition')->nullable()->after('type');
            $table->string('harness_revision', 64)->nullable()->after('parameters')->index();
            $table->string('target_commit', 40)->nullable()->after('harness_revision')->index();
            $table->boolean('reproducible')->default(true)->after('target_commit')->index();
        });

        Schema::create('audit_run_models', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('audit_run_id')->constrained('audit_runs')->cascadeOnDelete();
            $table->string('role', 32);
            $table->string('requested_model')->nullable();
            $table->string('effective_model')->nullable()->index();
            $table->string('provider')->nullable();
            $table->string('reasoning_effort', 24)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['audit_run_id', 'role']);
        });

        Schema::create('benchmark_evaluations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('audit_run_id')->constrained('audit_runs')->cascadeOnDelete();
            $table->string('benchmark_id')->index();
            $table->string('target_id')->index();
            $table->string('category', 64)->index();
            $table->string('status', 24)->index();
            $table->string('evaluator_version', 32);
            $table->string('oracle_version', 64)->nullable();
            $table->string('artifact_checksum', 64);
            $table->json('detection');
            $table->json('confirmation');
            $table->json('cost');
            $table->json('payload');
            $table->unsignedInteger('pending_adjudications')->default(0);
            $table->float('detection_recall')->default(0)->index();
            $table->float('confirmation_recall')->default(0)->index();
            $table->float('confirmation_f1')->default(0)->index();
            $table->unsignedBigInteger('total_tokens')->default(0)->index();
            $table->decimal('provider_cost_usd', 12, 6)->default(0);
            $table->timestamp('evaluated_at');
            $table->timestamps();
            $table->unique(['audit_run_id', 'benchmark_id', 'evaluator_version', 'artifact_checksum'], 'benchmark_evaluation_identity');
        });

        Schema::create('benchmark_case_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('benchmark_evaluation_id')->constrained('benchmark_evaluations')->cascadeOnDelete();
            $table->string('case_id');
            $table->boolean('expected_vulnerable');
            $table->string('detection', 32);
            $table->string('confirmation', 32);
            $table->string('match_mode', 32)->default('none');
            $table->json('matched_findings');
            $table->json('payload')->nullable();
            $table->timestamps();
            $table->unique(['benchmark_evaluation_id', 'case_id']);
        });

        Schema::create('benchmark_adjudications', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('benchmark_evaluation_id')->constrained('benchmark_evaluations')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('finding_fingerprint', 64);
            $table->string('decision', 32);
            $table->string('case_id')->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();
            $table->unique(
                ['benchmark_evaluation_id', 'finding_fingerprint'],
                'benchmark_adjudications_eval_fingerprint_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('benchmark_adjudications');
        Schema::dropIfExists('benchmark_case_results');
        Schema::dropIfExists('benchmark_evaluations');
        Schema::dropIfExists('audit_run_models');
        Schema::table('audit_runs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('benchmark_experiment_id');
            $table->dropColumn(['repetition', 'harness_revision', 'target_commit', 'reproducible']);
        });
        Schema::dropIfExists('benchmark_experiments');
    }
};
