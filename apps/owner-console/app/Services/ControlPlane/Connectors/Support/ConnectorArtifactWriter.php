<?php

namespace App\Services\ControlPlane\Connectors\Support;

use App\Models\MigrationAnalysis;
use App\Models\MigrationArtifact;
use App\Models\MigrationSource;
use App\Models\Project;
use Illuminate\Support\Facades\Storage;

/**
 * Phase 27K.3 — scoped artifact writing for connector operations.
 *
 * Artifacts live in PRIVATE local storage under the owning project's path.
 * Filenames are validated against a strict pattern so connector-provided
 * names can never traverse out of the artifact root (27T path escape).
 */
class ConnectorArtifactWriter
{
    private const FILENAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,120}$/';

    /** Persist a JSON artifact for a project. Returns the created model. */
    public static function write(Project|int $project, string $kind, string $filename, array $payload, array $summary): MigrationArtifact
    {
        $projectId = $project instanceof Project ? $project->id : $project;
        $safeName = $filename;
        abort_if(preg_match(self::FILENAME_PATTERN, $safeName) !== 1, 422, 'Invalid artifact filename.');
        $kind = preg_replace('/[^a-z0-9_\-]/', '', strtolower($kind)) ?: 'artifact';

        $path = sprintf('control-plane/migration-artifacts/%d/%s-%s.json', $projectId, $kind, $safeName);
        Storage::disk('local')->put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return MigrationArtifact::create([
            'project_id' => $projectId,
            'migration_analysis_id' => null,
            'migration_run_id' => null,
            'kind' => $kind,
            'path' => $path,
            'summary' => $summary,
        ]);
    }

    /** Write an analysis artifact (traceability fields included, 27I.2). */
    public static function writeAnalysis(MigrationAnalysis $analysis, string $kind, array $payload, array $summary): MigrationArtifact
    {
        $artifact = self::write($analysis->project_id, $kind, 'analysis-'.$analysis->run_id, $payload, $summary);
        $artifact->update(['migration_analysis_id' => $analysis->id]);

        return $artifact;
    }

    /** Write a source-scoped artifact with a connector-declared name. */
    public static function writeForSource(MigrationSource $source, string $kind, string $filename, array $payload, array $summary): MigrationArtifact
    {
        return self::write($source->project, $kind, $filename, $payload, $summary);
    }
}
