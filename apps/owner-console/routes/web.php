<?php

use App\Filament\Resources\Projects\ProjectResource;
use App\Http\Controllers\FunctionInvokeController;
use App\Http\Controllers\NodeAgentController;
use App\Http\Controllers\ProjectCsvExportController;
use App\Http\Controllers\ProjectErdController;
use App\Http\Controllers\ProjectFileDownloadController;
use App\Http\Controllers\ProjectOpenApiController;
use App\Http\Controllers\ProjectSqlController;
use App\Http\Controllers\Setup\SetupController;
use App\Http\Controllers\SignedDownloadController;
use App\Http\Controllers\WebhookFixtureController;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Services\ControlPlane\EnvironmentContext;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// ── 0.4.0-rc.5 (Phase 41) — language switcher ────────────────────────
// Public on purpose: setup and login must be usable in Arabic before any
// account exists. Persists session + cookie (+ user preference when authed).
Route::post('/locale', [App\Http\Controllers\LocaleController::class, 'update'])
    ->middleware(['web'])
    ->name('locale.update');

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
    Route::post('/heartbeat', [NodeAgentController::class, 'heartbeat'])->name('control-plane.node-heartbeat');
    Route::get('/config', [NodeAgentController::class, 'agentConfig'])->name('control-plane.node-config');
});
// Phase 21A ERD graph JSON backend (read-only metadata, layout persist).
Route::prefix('/cp-erd/{project}')->middleware(['web', 'auth'])->group(function () {
    Route::get('/', [ProjectErdController::class, 'show'])->name('control-plane.erd');
    Route::get('/schemas', [ProjectErdController::class, 'schemas'])->name('control-plane.erd-schemas');
    Route::put('/layout', [ProjectErdController::class, 'saveLayout'])->name('control-plane.erd-layout');
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
Route::get('/cp-environments/{project}/switch/{environment}', function (Project $project, ProjectEnvironment $environment) {
    EnvironmentContext::switch($project, $environment->id);

    return redirect()->to(request()->header('referer') ?: ProjectResource::getUrl('overview', ['record' => $project]));
})->middleware(['web', 'auth'])->name('control-plane.environment-switch');

// ── Phase 26D — first-run setup wizard ──────────────────────────────
// Reachable only while the platform is uninitialized (EnsurePlatformInitialized
// gate); locked once bootstrap completes. Rate-limited; server-side checks at
// every step; admin is created exactly once (SetupState::complete).
Route::prefix('setup')->middleware(['throttle:30,1'])->group(function () {
    Route::get('/', [SetupController::class, 'index'])->name('setup.index');
    Route::get('/step/{step}', [SetupController::class, 'show'])->name('setup.show');
    Route::post('/step/{step}', [SetupController::class, 'store'])->name('setup.store');
});

// ── 0.4.0 — redirects for admin paths that never existed ─────────────
// The UX audit found two dead links in the wild: /admin/team-management and
// /admin/project-switcher both returned 404 because the real routes are
// /admin/team and /admin/switcher. They are redirected rather than left dead,
// so an operator's bookmark or an old runbook link keeps working.
//
// Deliberately 302 (temporary): the canonical URLs are the ones above, and this
// is a compatibility shim, not a rename.
Route::middleware(['web'])->prefix('admin')->group(function () {
    Route::redirect('team-management', '/admin/team', 302);
    Route::redirect('project-switcher', '/admin/switcher', 302);

    // 0.4.0 Phase F: the catalogue moved from the internal "connector-catalog"
    // path to the product path "connectors". The old URL keeps working so an
    // operator's bookmark does not 404.
    Route::redirect('connector-catalog', '/admin/connectors', 302);
});
