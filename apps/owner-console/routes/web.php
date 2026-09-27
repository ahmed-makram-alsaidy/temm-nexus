<?php

use App\Http\Controllers\FunctionInvokeController;
use App\Http\Controllers\ProjectCsvExportController;
use App\Http\Controllers\ProjectOpenApiController;
use App\Http\Controllers\SignedDownloadController;
use App\Http\Controllers\WebhookFixtureController;
use App\Http\Controllers\ProjectSqlController;
use App\Http\Controllers\ProjectFileDownloadController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Phase 20P local webhook test fixture (local env, throttled).
Route::post('/cp-webhook-fixture/{token}', WebhookFixtureController::class)
    ->middleware(['web', 'throttle:30,1'])
    ->name('control-plane.webhook-fixture');
// Phase 20L signed, expiring downloads (signature is the bearer).
Route::get('/sdl/{project}/{bucket}/{key}', SignedDownloadController::class)
    ->where('key', '.*')
    ->middleware(['web', 'throttle:120,1'])
    ->name('control-plane.signed-download');
// Phase 20I generated OpenAPI download.
Route::get('/project-openapi/{project}', ProjectOpenApiController::class)
    ->middleware(['web', 'auth'])
    ->name('control-plane.openapi');
// Phase 20E capped CSV export of a project table (owner/team-gated, audited).
Route::get('/project-csv/{project}/{table}', ProjectCsvExportController::class)
    ->middleware(['web', 'auth'])
    ->name('control-plane.csv-export');
// Phase 20F SQL Editor JSON backend (permission-gated, audited).
Route::prefix('/cp-sql/{project}')->middleware(['web', 'auth'])->group(function () {
    Route::post('/run', [ProjectSqlController::class, 'run'])->name('control-plane.sql-run');
    Route::get('/history', [ProjectSqlController::class, 'history'])->name('control-plane.sql-history');
    Route::get('/saved', [ProjectSqlController::class, 'saved'])->name('control-plane.sql-saved');
    Route::post('/save', [ProjectSqlController::class, 'save'])->name('control-plane.sql-save');
    Route::delete('/saved/{id}', [ProjectSqlController::class, 'destroySaved'])->name('control-plane.sql-unsave');
    Route::post('/write-mode', [ProjectSqlController::class, 'enableWrite'])->name('control-plane.sql-write');
    Route::get('/write-mode', [ProjectSqlController::class, 'writeStatus'])->name('control-plane.sql-write-status');
});
// Phase 21B node agent channel (token-authenticated heartbeat push only).
Route::prefix('/cp-nodes')->middleware(['web', 'throttle:120,1'])->group(function () {
    Route::post('/heartbeat', [\App\Http\Controllers\NodeAgentController::class, 'heartbeat'])->name('control-plane.node-heartbeat');
    Route::get('/config', [\App\Http\Controllers\NodeAgentController::class, 'agentConfig'])->name('control-plane.node-config');
});
// Phase 21A ERD graph JSON backend (read-only metadata, layout persist).
Route::prefix('/cp-erd/{project}')->middleware(['web', 'auth'])->group(function () {
    Route::get('/', [\App\Http\Controllers\ProjectErdController::class, 'show'])->name('control-plane.erd');
    Route::get('/schemas', [\App\Http\Controllers\ProjectErdController::class, 'schemas'])->name('control-plane.erd-schemas');
    Route::put('/layout', [\App\Http\Controllers\ProjectErdController::class, 'saveLayout'])->name('control-plane.erd-layout');
});
// Phase 20H Server Functions invocation surface (per-function auth, logged).
Route::match(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], '/f/{projectSlug}/{functionSlug}', FunctionInvokeController::class)
    ->middleware(['web', 'throttle:120,1'])
    ->name('control-plane.function-invoke');
Route::get('/project-files/{project}/{bucket}/{key}', ProjectFileDownloadController::class)
    ->where('key', '.*')
    ->middleware(['web', 'auth'])
    ->name('control-plane.download');
// Phase 24C environment switcher: updates session-scoped environment context.
Route::get('/cp-environments/{project}/switch/{environment}', function (\App\Models\Project $project, \App\Models\ProjectEnvironment $environment) {
    \App\Services\ControlPlane\EnvironmentContext::switch($project, $environment->id);

    return redirect()->to(request()->header('referer') ?: \App\Filament\Resources\Projects\ProjectResource::getUrl('overview', ['record' => $project]));
})->middleware(['web', 'auth'])->name('control-plane.environment-switch');

// ── Phase 26D — first-run setup wizard ──────────────────────────────
// Reachable only while the platform is uninitialized (EnsurePlatformInitialized
// gate); locked once bootstrap completes. Rate-limited; server-side checks at
// every step; admin is created exactly once (SetupState::complete).
Route::prefix('setup')->middleware(['throttle:30,1'])->group(function () {
    Route::get('/', [\App\Http\Controllers\Setup\SetupController::class, 'index'])->name('setup.index');
    Route::get('/step/{step}', [\App\Http\Controllers\Setup\SetupController::class, 'show'])->name('setup.show');
    Route::post('/step/{step}', [\App\Http\Controllers\Setup\SetupController::class, 'store'])->name('setup.store');
});
