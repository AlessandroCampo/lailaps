<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('audit_events');

        if (! Schema::hasTable('audit_runs')) {
            return;
        }

        DB::table('audit_runs')->delete();

        if (Schema::hasColumn('audit_runs', 'artifact_path')
            && ! Schema::hasColumn('audit_runs', 'run_path')) {
            Schema::table('audit_runs', function (Blueprint $table): void {
                $table->renameColumn('artifact_path', 'run_path');
            });
        } elseif (! Schema::hasColumn('audit_runs', 'run_path')) {
            Schema::table('audit_runs', function (Blueprint $table): void {
                $table->string('run_path')->nullable();
            });
        }

        if (Schema::hasColumn('audit_runs', 'artifact_path')) {
            Schema::table('audit_runs', function (Blueprint $table): void {
                $table->dropColumn('artifact_path');
            });
        }

        if (Schema::hasColumn('audit_runs', 'legacy')) {
            Schema::table('audit_runs', function (Blueprint $table): void {
                $table->dropColumn('legacy');
            });
        }
    }

    public function down(): void
    {
        // Intentionally irreversible: the previous run contracts and data are discarded.
    }
};
