<?php

use App\Models\AuditRun;
use App\Models\AuditRunArea;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_run_leads', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignIdFor(AuditRun::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(AuditRunArea::class)->nullable()->constrained()->nullOnDelete();
            $table->string('lead_key');
            $table->string('status')->default('pending');
            $table->text('title');
            $table->string('severity')->nullable();
            $table->jsonb('payload')->nullable();
            $table->timestamps();

            $table->unique(['audit_run_id', 'lead_key']);
            $table->index(['audit_run_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_run_leads');
    }
};
