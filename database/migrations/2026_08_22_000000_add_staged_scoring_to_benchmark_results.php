<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('benchmark_evaluations', function (Blueprint $table): void {
            $table->string('artifact_state', 24)->default('unknown')->index();
            $table->string('run_state', 24)->default('unknown')->index();
            $table->string('adjudication_state', 24)->default('provisional')->index();
            $table->string('environment_state', 24)->default('unknown')->index();
            $table->json('reach')->nullable();
            $table->json('static_validation')->nullable();
            $table->float('global_score')->nullable()->index();
            $table->float('adjudicated_score')->nullable()->index();
            $table->float('file_recall')->default(0)->index();
            $table->float('anchor_recall')->default(0)->index();
            $table->float('static_validation_recall')->default(0)->index();
        });

        Schema::table('benchmark_case_results', function (Blueprint $table): void {
            $table->string('stage', 32)->default('not_reached')->index();
            $table->float('stage_score')->default(0);
            $table->float('score_contribution')->default(0);
            $table->json('reach')->nullable();
            $table->json('milestones')->nullable();
            $table->string('suspected', 32)->default('unknown');
            $table->string('static_validation', 32)->default('unknown');
            $table->string('dynamically_confirmed', 32)->default('unknown');
        });
    }

    public function down(): void
    {
        Schema::table('benchmark_case_results', function (Blueprint $table): void {
            $table->dropColumn(['stage', 'stage_score', 'score_contribution', 'reach', 'milestones', 'suspected', 'static_validation', 'dynamically_confirmed']);
        });
        Schema::table('benchmark_evaluations', function (Blueprint $table): void {
            $table->dropColumn(['artifact_state', 'run_state', 'adjudication_state', 'environment_state', 'reach', 'static_validation', 'global_score', 'adjudicated_score', 'file_recall', 'anchor_recall', 'static_validation_recall']);
        });
    }
};
