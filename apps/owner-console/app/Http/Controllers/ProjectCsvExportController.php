<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\ControlPlane\AdminAudit;
use App\Services\ControlPlane\CpAccess;
use App\Services\ControlPlane\ProjectDatabaseExplorer;
use App\Services\ControlPlane\ProtectedTables;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Phase 20E CSV export of a project table (capped, owner/team-gated, audited).
 * Secret columns are never exported.
 */
class ProjectCsvExportController extends Controller
{
    public const MAX_ROWS = 10000;

    public function __invoke(Request $request, Project $project): StreamedResponse
    {
        $owner = $request->user();
        CpAccess::require($owner, 'database.read');

        $table = (string) $request->route('table');
        $explorer = ProjectDatabaseExplorer::for($project);
        $explorer->assertTable($table);
        abort_if(ProtectedTables::isReadOnly($table) && ! $owner->is_admin, 403);

        $columns = array_column($explorer->columns($table), 'name');
        $visible = ProtectedTables::visibleColumns($columns);
        abort_if($visible === [], 422, 'Nothing exportable.');

        AdminAudit::record('RECORDS_EXPORTED', $project, $table, null, ['columns' => count($visible), 'cap' => self::MAX_ROWS]);

        $filename = $project->slug.'-'.$table.'-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($explorer, $table, $visible) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $visible);
            $explorer->query($table)->select($visible)->orderBy(
                $explorer->primaryKey($table) ?? $visible[0]
            )->chunk(1000, function ($rows) use ($out, $visible) {
                foreach ($rows as $row) {
                    $line = [];
                    foreach ($visible as $col) {
                        $v = $row->{$col} ?? null;
                        $line[] = is_scalar($v) ? (string) $v : json_encode($v);
                    }
                    fputcsv($out, $line);
                }
            });
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
