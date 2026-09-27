<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\SavedSqlQuery;
use App\Models\SqlQueryHistory;
use App\Services\ControlPlane\AdminAudit;
use App\Services\ControlPlane\CpAccess;
use App\Services\ControlPlane\SqlGuard;
use App\Services\ControlPlane\SqlRunner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Phase 20F SQL Editor JSON backend (project-scoped, permission-gated).
 * Every execution is classified, bounded, history-recorded (redacted) and
 * audit logged. Raw SQL text is never stored — only redacted form + hash.
 */
class ProjectSqlController extends Controller
{
    public const WRITE_MODE_MINUTES = 10;

    protected function project(Request $request): Project
    {
        $project = $request->route('project');
        if ($project instanceof Project) {
            return $project;
        }

        return Project::findOrFail($project);
    }

    protected function writeModeActive(Project $project): bool
    {
        $until = session()->get("cp_sql_write_{$project->id}");

        if (! $until || now()->greaterThan($until)) {
            if ($until) {
                session()->forget("cp_sql_write_{$project->id}");
            }

            return false;
        }

        return true;
    }

    public function run(Request $request)
    {
        $project = $this->project($request);
        $user = $request->user();
        CpAccess::require($user, 'sql.execute_read');

        $data = $request->validate([
            'sql' => 'required|string|max:20000',
            'write' => 'sometimes|boolean',
            'confirm_slug' => 'nullable|string|max:120',
        ]);
        $wantWrite = (bool) ($data['write'] ?? false);
        if ($wantWrite) {
            CpAccess::require($user, 'sql.execute_write');
        }
        $writeMode = $wantWrite && $this->writeModeActive($project);
        if ($wantWrite && ! $writeMode) {
            return response()->json(['status' => 'error', 'error' => 'Write mode is not active or has expired.'], 422);
        }

        $classification = SqlGuard::classify($data['sql'], $writeMode);
        if ($classification['destructive'] && ($data['confirm_slug'] ?? '') !== $project->slug) {
            $this->record($project, $user, $data['sql'], $classification['category'], 'blocked', 0, 0, 'Destructive statement requires typing the project slug.');

            return response()->json([
                'status' => 'confirm_required',
                'error' => 'This statement is destructive. Type the project slug to confirm.',
                'category' => $classification['category'],
            ], 422);
        }

        $result = SqlRunner::run($project, $data['sql'], $writeMode);
        $redacted = SqlGuard::redact($data['sql']);
        $this->record(
            $project, $user, $data['sql'], $result['category'],
            $result['status'], $result['duration_ms'],
            $result['category'] === 'write' ? $result['affected'] : count($result['rows']),
            $result['error']
        );
        $action = $result['status'] === 'blocked' ? 'SQL_BLOCKED'
            : ($result['category'] === 'write' ? 'SQL_WRITE_EXECUTED' : 'SQL_READ_EXECUTED');
        AdminAudit::record($action, $project, 'sql', null, [
            'hash' => hash('sha256', $redacted),
            'category' => $result['category'],
            'status' => $result['status'],
            'duration_ms' => $result['duration_ms'],
        ]);
        $result['redacted'] = $redacted;

        return response()->json($result);
    }

    protected function record(
        Project $project, $user, string $sql, string $category,
        string $status, int $ms, int $rows, ?string $error
    ): void {
        SqlQueryHistory::create([
            'project_id' => $project->id,
            'owner_user_id' => $user?->id,
            'query_hash' => hash('sha256', SqlGuard::redact($sql)),
            'category' => $category,
            'status' => $status,
            'duration_ms' => $ms,
            'rows' => $rows,
            'redacted_sql' => SqlGuard::redact($sql),
            'error' => $error ? mb_substr($error, 0, 800) : null,
        ]);
    }

    public function history(Request $request)
    {
        $project = $this->project($request);
        CpAccess::require($request->user(), 'sql.execute_read');

        return response()->json(
            SqlQueryHistory::query()->where('project_id', $project->id)
                ->orderByDesc('id')->limit(50)->get()
                ->makeHidden([])
        );
    }

    public function saved(Request $request)
    {
        $project = $this->project($request);
        CpAccess::require($request->user(), 'sql.execute_read');

        return response()->json(
            SavedSqlQuery::query()->where('project_id', $project->id)->orderBy('name')->get()
        );
    }

    public function save(Request $request)
    {
        $project = $this->project($request);
        CpAccess::require($request->user(), 'sql.execute_read');
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'sql' => 'required|string|max:20000',
        ]);
        $q = SavedSqlQuery::updateOrCreate(
            ['project_id' => $project->id, 'name' => $data['name']],
            ['sql' => $data['sql'], 'created_by' => $request->user()?->id]
        );

        return response()->json($q, 201);
    }

    public function destroySaved(Request $request)
    {
        $project = $this->project($request);
        CpAccess::require($request->user(), 'sql.execute_read');
        SavedSqlQuery::query()->where('project_id', $project->id)
            ->where('id', $request->route('id'))->delete();

        return response()->json(['ok' => true]);
    }

    /** Enable write mode after password re-authentication (10-minute window). */
    public function enableWrite(Request $request)
    {
        $project = $this->project($request);
        $user = $request->user();
        CpAccess::require($user, 'sql.execute_write');
        $data = $request->validate(['password' => 'required|string']);
        abort_unless(Hash::check($data['password'], (string) $user->getAuthPassword()), 403, 'Password confirmation failed.');
        session()->put("cp_sql_write_{$project->id}", now()->addMinutes(self::WRITE_MODE_MINUTES));
        AdminAudit::record('WRITE_MODE_ENABLED', $project, 'sql', null, ['minutes' => self::WRITE_MODE_MINUTES]);

        return response()->json(['ok' => true, 'minutes' => self::WRITE_MODE_MINUTES]);
    }

    public function writeStatus(Request $request)
    {
        $project = $this->project($request);
        CpAccess::require($request->user(), 'sql.execute_read');

        return response()->json(['active' => $this->writeModeActive($project)]);
    }
}
