<?php

namespace App\Http\Controllers;

use App\Models\ErdLayout;
use App\Models\Project;
use App\Services\ControlPlane\CpAccess;
use App\Services\ControlPlane\ErdService;
use Illuminate\Http\Request;

/**
 * Phase 21A: read-only ERD graph JSON backend (project-scoped,
 * permission-gated) + per-user layout persistence.
 *
 * The graph is derived live from pg_catalog on every request — there is no
 * cached/stored relationship config that could drift from the real schema.
 */
class ProjectErdController extends Controller
{
    protected function project(Request $request): Project
    {
        $project = $request->route('project');
        if ($project instanceof Project) {
            return $project;
        }

        return Project::findOrFail($project);
    }

    /** GET /cp-erd/{project}?schema=public — full graph for one schema. */
    public function show(Request $request)
    {
        $project = $this->project($request);
        CpAccess::require($request->user(), 'database.read');

        $schema = (string) ($request->query('schema', 'public') ?: 'public');
        $graph = ErdService::graph($project, $schema);
        $layout = ErdLayout::query()
            ->where('project_id', $project->id)
            ->where('user_id', $request->user()->id)
            ->where('schema', $graph['schema'])
            ->first();

        return response()->json([
            'status' => 'ok',
            'project' => $project->slug,
            'layout' => $layout?->layout,
            'graph' => $graph,
        ]);
    }

    /** GET /cp-erd/{project}/schemas — schema selector options. */
    public function schemas(Request $request)
    {
        $project = $this->project($request);
        CpAccess::require($request->user(), 'database.read');

        return response()->json([
            'status' => 'ok',
            'schemas' => ErdService::schemas($project),
        ]);
    }

    /** PUT /cp-erd/{project}/layout — persist dragged node positions. */
    public function saveLayout(Request $request)
    {
        $project = $this->project($request);
        CpAccess::require($request->user(), 'database.read');

        $data = $request->validate([
            'schema' => 'required|string|max:63',
            'layout' => 'required|array|max:2000',
            'layout.*.x' => 'required|numeric',
            'layout.*.y' => 'required|numeric',
            'layout.*.collapsed' => 'sometimes|boolean',
        ]);
        $schema = ErdService::assertSchema($data['schema']);

        // Layout keys must be plausible identifiers; positions bounded so a
        // corrupt/oversized payload cannot break rendering for the user.
        $clean = [];
        foreach (array_slice($data['layout'], 0, 1000) as $table => $pos) {
            if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/', (string) $table) !== 1) {
                continue;
            }
            $clean[$table] = [
                'x' => max(-5000, min(20000, (float) $pos['x'])),
                'y' => max(-5000, min(20000, (float) $pos['y'])),
                'collapsed' => (bool) ($pos['collapsed'] ?? false),
            ];
        }

        ErdLayout::updateOrCreate(
            ['project_id' => $project->id, 'user_id' => $request->user()->id, 'schema' => $schema],
            ['layout' => $clean]
        );

        return response()->json(['status' => 'ok', 'tables' => count($clean)]);
    }
}
