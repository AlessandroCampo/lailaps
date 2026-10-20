<?php

use App\Models\AuditRun;
use App\Models\ReconArea;
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
        Schema::create('audit_run_areas', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(AuditRun::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(ReconArea::class)->nullable()->constrained()->nullOnDelete();
            $table->string('pass_key')->default('pass-001');
            $table->string('area_key');
            $table->string('status')->default('pending');
            $table->text('reason')->nullable();
            $table->jsonb('payload')->nullable();
            $table->timestamps();

            $table->unique(['audit_run_id', 'pass_key', 'area_key']);
            $table->index(['audit_run_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_run_areas');
    }
};
