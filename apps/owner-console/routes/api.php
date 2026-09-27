<?php

use App\Http\Controllers\Api\HealthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Unauthenticated service probe (load-balancer / monitoring). Rate-limited.
Route::get('/health', HealthController::class)->middleware('throttle:60,1');

// Versioned API surface. Auth-ready via Sanctum; roles/permissions attach here.
Route::prefix('v1')->middleware('throttle:api')->group(function () {
    Route::get('/user', function (Request $request) {
        return new \App\Http\Resources\UserResource($request->user());
    })->middleware('auth:sanctum');

    // Auth endpoints (token issue/revoke) are implemented per project, e.g.:
    // Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    // Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
});
