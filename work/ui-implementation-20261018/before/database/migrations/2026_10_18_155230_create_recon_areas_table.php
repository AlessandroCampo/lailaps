<?php

use App\Models\ProjectArtifact;
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
        Schema::create('recon_areas', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignIdFor(ProjectSource::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(ProjectArtifact::class, 'recon_artifact_id')->constrained()->cascadeOnDelete();
            $table->string('area_key')->index();
            $table->string('name')->nullable();
            $table->jsonb('payload')->nullable()->index();
            $table->timestamps();

            $table->unique(['area_key', 'recon_artifact_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recon_areas');
    }
};
