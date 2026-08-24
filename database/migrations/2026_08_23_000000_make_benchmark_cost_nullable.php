<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('benchmark_evaluations', function (Blueprint $table): void {
            $table->decimal('provider_cost_usd', 12, 6)->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('benchmark_evaluations', function (Blueprint $table): void {
            $table->decimal('provider_cost_usd', 12, 6)->default(0)->nullable(false)->change();
        });
    }
};
