<?php

use App\Models\Project;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('project_sources', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignIdFor(Project::class)->constrained()->cascadeOnDelete();
            $table->string('source_type');
            $table->string('status')->default('pending')->index();
            $table->string('content_hash')->nullable()->index();
            $table->json('error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_sources');
    }
};
