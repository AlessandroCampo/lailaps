<?php

use App\Models\Lab;
use App\Models\Project;
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
        Schema::create('audit_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignIdFor(Project::class)->constrained();
            $table->foreignIdFor(ProjectSource::class)->constrained();
            $table->foreignIdFor(Lab::class)->constrained();
            $table->string('status')->default('pending');
            $table->jsonb('configs')->nullable();
            $table->jsonb('outcome')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_runs');
    }
};
