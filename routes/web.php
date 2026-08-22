<?php

use App\Http\Controllers\AuditRunController;
use App\Http\Controllers\BenchmarkAdjudicationController;
use App\Http\Controllers\BenchmarkComparisonController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
    Route::get('audits', [AuditRunController::class, 'index'])->name('audits.index');
    Route::get('audits/create', [AuditRunController::class, 'create'])->name('audits.create');
    Route::post('audits', [AuditRunController::class, 'store'])->name('audits.store');
    Route::get('audit-console', [AuditRunController::class, 'consoleIndex'])->name('audits.console.index');
    Route::get('audit-console/new', [AuditRunController::class, 'console'])->name('audits.console');
    Route::post('audit-console/runs', [AuditRunController::class, 'launchFromConsole'])->name('audits.console.launch');
    Route::get('audit-console/runs/{audit}', [AuditRunController::class, 'showConsole'])->name('audits.console.show');
    Route::delete('audit-console/runs/{audit}', [AuditRunController::class, 'destroyConsole'])->name('audits.console.destroy');
    Route::get('benchmarks', BenchmarkComparisonController::class)->name('benchmarks.index');
    Route::get('benchmarks/compare', BenchmarkComparisonController::class)->name('benchmarks.compare');
    Route::post('benchmarks/evaluations/{evaluation}/adjudications', BenchmarkAdjudicationController::class)->name('benchmarks.adjudications.store');
    Route::get('audits/{audit}', [AuditRunController::class, 'show'])->name('audits.show');
    Route::post('audits/{audit}/cancel', [AuditRunController::class, 'cancel'])->name('audits.cancel');
    Route::post('audits/{audit}/rerun', [AuditRunController::class, 'rerun'])->name('audits.rerun');
    Route::post('audit-console/runs/{audit}/retry', [AuditRunController::class, 'retry'])->name('audits.console.retry');
    Route::delete('audits/{audit}', [AuditRunController::class, 'destroy'])->name('audits.destroy');
    Route::get('audits/{audit}/events', [AuditRunController::class, 'events'])->name('audits.events');
    Route::get('audits/{audit}/log-chunk', [AuditRunController::class, 'logChunk'])->name('audits.log-chunk');
    Route::get('audits/{audit}/snapshot', [AuditRunController::class, 'snapshot'])->name('audits.snapshot');
    Route::get('audits/{audit}/artifacts/{path}', [AuditRunController::class, 'artifact'])->where('path', '.*')->name('audits.artifact');
});

require __DIR__.'/settings.php';
