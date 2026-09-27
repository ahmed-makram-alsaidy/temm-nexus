<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectFunction;
use App\Services\ControlPlane\ApiKeyService;
use App\Services\ControlPlane\CpAccess;
use App\Services\ControlPlane\FunctionRunner;
use App\Services\ControlPlane\ProjectDatabaseExplorer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Phase 20H public invocation surface: /f/{project}/{function}.
 * Auth modes: public | key (project API key, functions:invoke) |
 * user (project Sanctum token) | internal (owner session + permission).
 * Stateless, rate-limited per function config, fully logged.
 */
class FunctionInvokeController extends Controller
{
    public function __invoke(Request $request, string $projectSlug, string $functionSlug)
    {
        $project = Project::query()->where('slug', $projectSlug)->first();
        abort_unless($project, 404);
        $function = ProjectFunction::query()
            ->where('project_id', $project->id)->where('slug', $functionSlug)->first();
        abort_unless($function, 404);

        $actor = $this->authenticate($request, $project, $function);

        $body = $request->getContent();
        $decoded = $body !== '' ? json_decode($body, true) : null;
        // Correlation: callers (tester, API studio, cron) may supply their own
        // request ID; otherwise the runner mints one. Always echoed back.
        $requestId = $request->header('X-Request-ID') ?: null;
        if ($requestId !== null && ! preg_match('/^[A-Za-z0-9\-_:.]{1,128}$/', $requestId)) {
            $requestId = null;
        }
        $result = FunctionRunner::invoke($function, [
            'method' => $request->method(),
            'query' => $request->query(),
            'headers' => ['content-type' => $request->header('content-type')],
            'body' => json_last_error() === JSON_ERROR_NONE ? $decoded : $body,
        ], $actor, $requestId);

        return response()->json($result['body'], $result['status'], ['X-Request-ID' => $result['request_id']]);
    }

    protected function authenticate(Request $request, Project $project, ProjectFunction $function): string
    {
        return match ($function->auth_mode) {
            'public' => 'anonymous',
            'key' => $this->viaKey($request, $project),
            'user' => $this->viaUserToken($request, $project),
            'internal' => $this->viaInternal($request),
            default => abort(403, 'Unknown auth mode.'),
        };
    }

    protected function presentedKey(Request $request): ?string
    {
        $header = (string) $request->header('Authorization', '');
        if (str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }

        return $request->query('key') ?: ($request->header('X-API-Key') ?: null);
    }

    protected function viaKey(Request $request, Project $project): string
    {
        $presented = $this->presentedKey($request);
        abort_unless($presented, 401, 'API key required.');
        $key = ApiKeyService::validate($project, $presented, 'functions:invoke');
        abort_unless($key, 401, 'Invalid API key.');

        return 'key:'.$key->prefix;
    }

    protected function viaUserToken(Request $request, Project $project): string
    {
        $presented = $this->presentedKey($request);
        abort_unless($presented && str_contains($presented, '|'), 401, 'User token required.');
        [$id, $plain] = explode('|', $presented, 2);
        abort_unless(ctype_digit($id), 401, 'Invalid user token.');
        try {
            $explorer = ProjectDatabaseExplorer::for($project);
            $explorer->assertTable('personal_access_tokens');
            $conn = $explorer->connectionName();
            $row = DB::connection($conn)->table('personal_access_tokens')->where('id', $id)->first();
        } catch (\Throwable) {
            abort(401, 'User tokens unavailable for this project.');
        }
        abort_unless($row && hash_equals((string) $row->token, hash('sha256', $plain)), 401, 'Invalid user token.');

        return 'user:'.$id;
    }

    protected function viaInternal(Request $request): string
    {
        $user = $request->user();
        abort_unless($user, 401, 'Owner session required.');
        CpAccess::require($user, 'functions.invoke');

        return 'owner:'.$user->id;
    }
}
