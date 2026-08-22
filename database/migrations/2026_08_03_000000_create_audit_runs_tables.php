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
            $table->string('run_path')->nullable();
            $table->integer('exit_code')->nullable();
            $table->text('error')->nullable();
            $table->boolean('cancellation_requested')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('audit_runs');
    }
};
