<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('audit_id', 64)->unique();
            $table->string('type', 24)->default('audit');
            $table->string('status', 24)->default('queued')->index();
            $table->json('parameters');
            $table->string('artifact_path')->nullable();
            $table->integer('exit_code')->nullable();
            $table->text('error')->nullable();
            $table->boolean('cancellation_requested')->default(false);
            $table->boolean('legacy')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('audit_run_id')->constrained('audit_runs')->cascadeOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->string('type', 48)->index();
            $table->string('category')->nullable();
            $table->string('role', 32)->nullable();
            $table->json('payload');
            $table->string('artifact_ref')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->unique(['audit_run_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('audit_runs');
    }
};
