<?php

use App\Models\ProjectSource;
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
        Schema::create('project_artifacts', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(ProjectSource::class)->constrained()->cascadeOnDelete();
            $table->string('type')->index();
            $table->string('status')->default('pending');
            $table->jsonb('payload')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->string('content_hash')->nullable()->index();
            $table->string('error')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_artifacts');
    }
};
