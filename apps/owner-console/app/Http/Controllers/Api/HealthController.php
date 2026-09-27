<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\HealthService;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function __invoke(HealthService $health): JsonResponse
    {
        $status = $health->check();

        return response()->json($status, $status['ok'] ? 200 : 503);
    }
}
