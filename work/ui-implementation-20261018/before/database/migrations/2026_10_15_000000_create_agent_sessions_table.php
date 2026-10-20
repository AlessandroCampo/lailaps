<?php

use App\Models\Project;
use App\Models\ProjectSource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignIdFor(Project::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(ProjectSource::class)->constrained()->cascadeOnDelete();
            $table->string('agent');
            $table->string('status')->default('running');
            $table->string('model');
            $table->text('system_prompt')->nullable();
            $table->longText('messages')->default('[]');
            $table->json('paths');
            $table->json('output')->nullable();
            $table->text('environment')->nullable();
            $table->text('runtime_config')->nullable();
            $table->text('failure_reason')->nullable();
            $table->unsignedInteger('attempt')->default(0);
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_sessions');
    }
};
