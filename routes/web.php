<?php

use App\Http\Controllers\AuditRunController;
use App\Http\Controllers\BenchmarkComparisonController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
    Route::get('audits', [AuditRunController::class, 'index'])->name('audits.index');
    Route::get('audits/create', [AuditRunController::class, 'create'])->name('audits.create');
    Route::post('audits', [AuditRunController::class, 'store'])->name('audits.store');
    Route::get('benchmarks/compare', BenchmarkComparisonController::class)->name('benchmarks.compare');
    Route::get('audits/{audit}', [AuditRunController::class, 'show'])->name('audits.show');
    Route::post('audits/{audit}/cancel', [AuditRunController::class, 'cancel'])->name('audits.cancel');
    Route::post('audits/{audit}/rerun', [AuditRunController::class, 'rerun'])->name('audits.rerun');
    Route::delete('audits/{audit}', [AuditRunController::class, 'destroy'])->name('audits.destroy');
    Route::get('audits/{audit}/events', [AuditRunController::class, 'events'])->name('audits.events');
    Route::get('audits/{audit}/artifacts/{path}', [AuditRunController::class, 'artifact'])->where('path', '.*')->name('audits.artifact');
});

require __DIR__.'/settings.php';
