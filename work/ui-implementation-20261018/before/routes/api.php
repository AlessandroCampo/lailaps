<?php

use App\Http\Controllers\AgentSessionController;
use Illuminate\Support\Facades\Route;

Route::middleware('signed')->group(function () {
    Route::get('internal/agent-sessions/{agentSession}', [AgentSessionController::class, 'show'])->name('internal.agent-sessions.show');
    Route::post('internal/agent-sessions/{agentSession}', [AgentSessionController::class, 'update']);
});
