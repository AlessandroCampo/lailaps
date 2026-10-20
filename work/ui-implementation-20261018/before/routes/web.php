<?php

use App\Http\Controllers\AuditRunController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectSourceController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', fn () => to_route('projects.index'))->name('dashboard');
    Route::resource('projects', ProjectController::class)->only(['index', 'create', 'store', 'show']);
    Route::post('projects/{project}/sources', [ProjectSourceController::class, 'store'])->name('projects.sources.store');
    Route::get('projects/{project}/sources/{source}', [ProjectSourceController::class, 'show'])->name('projects.sources.show');
    Route::post('projects/{project}/sources/{source}/retry-setup', [ProjectSourceController::class, 'retrySetup'])->name('projects.sources.retry_setup');
    Route::post('projects/{project}/sources/{source}/audit-runs', [AuditRunController::class, 'store'])->name('projects.sources.audit_runs.store');
    Route::get('audit-runs/{auditRun}/progress', [AuditRunController::class, 'progress'])->name('audit_runs.progress');
});

require __DIR__.'/settings.php';
