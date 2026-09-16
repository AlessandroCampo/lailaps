<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('github_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('github_user_id');
            $table->string('login');
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->string('scopes')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('refresh_expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('source_revisions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 16);
            $table->string('status', 24)->default('pending')->index();
            $table->string('content_hash', 64)->nullable()->index();
            $table->string('source_path')->nullable();
            $table->string('application_subdirectory')->nullable();
            $table->string('original_filename')->nullable();
            $table->string('github_repository_id')->nullable();
            $table->string('github_full_name')->nullable();
            $table->string('github_commit_sha', 64)->nullable();
            $table->string('github_ref')->nullable();
            $table->json('provider_metadata')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'github_repository_id', 'github_commit_sha']);
        });

        Schema::create('preparations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('source_revision_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('based_on_id')->nullable()->constrained('preparations')->nullOnDelete();
            $table->string('status', 32)->default('queued')->index();
            $table->json('questions')->nullable();
            $table->text('answers')->nullable();
            $table->json('configuration')->nullable();
            $table->text('summary')->nullable();
            $table->json('actors')->nullable();
            $table->json('capabilities')->nullable();
            $table->json('doctor_usage')->nullable();
            $table->unsignedInteger('active_seconds')->default(0);
            $table->string('runtime_path')->nullable();
            $table->string('artifacts_path')->nullable();
            $table->string('fingerprint', 64)->nullable();
            $table->text('failure_reason')->nullable();
            $table->boolean('cancellation_requested')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::table('audit_runs', function (Blueprint $table): void {
            $table->foreignUlid('source_revision_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->foreignUlid('preparation_id')->nullable()->after('source_revision_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('audit_runs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('preparation_id');
            $table->dropConstrainedForeignId('source_revision_id');
        });
        Schema::dropIfExists('preparations');
        Schema::dropIfExists('source_revisions');
        Schema::dropIfExists('github_connections');
        Schema::dropIfExists('projects');
    }
};
