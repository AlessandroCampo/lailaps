<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('benchmark_stage_artifacts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('project_key', 120)->index();
            $table->string('category', 120)->index();
            $table->string('source_commit', 64)->nullable()->index();
            $table->foreignUlid('parent_artifact_id')->nullable()
                ->constrained('benchmark_stage_artifacts')->nullOnDelete();
            $table->string('run_id', 120)->nullable()->index();
            $table->unsignedSmallInteger('repetition')->nullable();
            $table->string('role', 32)->index();
            $table->string('output_type', 64)->index();
            $table->string('model')->nullable()->index();
            $table->string('reasoning_effort', 24)->nullable();
            $table->string('status', 32)->default('valid')->index();
            $table->boolean('accepted')->default(true)->index();
            $table->boolean('is_golden')->default(false)->index();
            $table->boolean('is_canonical')->default(true)->index();
            $table->string('content_hash', 64)->index();
            $table->string('configuration_signature', 64)->nullable()->index();
            $table->json('payload')->nullable();
            $table->json('usage')->nullable();
            $table->json('metrics')->nullable();
            $table->string('evaluator_version', 32)->nullable();
            $table->string('label', 32)->nullable()->index();
            $table->string('matched_case_id')->nullable()->index();
            $table->string('label_set_version', 32)->nullable();
            $table->text('label_notes')->nullable();
            $table->text('technical_error')->nullable();
            $table->timestamps();

            $table->index(
                ['project_key', 'category', 'source_commit', 'role', 'is_golden'],
                'benchmark_stage_artifact_golden_lookup',
            );
            $table->index(
                ['project_key', 'role', 'output_type', 'label', 'is_canonical'],
                'benchmark_stage_artifact_downstream_lookup',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('benchmark_stage_artifacts');
    }
};
