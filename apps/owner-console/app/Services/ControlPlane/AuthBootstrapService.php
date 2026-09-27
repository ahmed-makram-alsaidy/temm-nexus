<?php

namespace App\Services\ControlPlane;

use App\Models\Project;

/**
 * Phase 20K one-click auth bootstrap: creates the conventional `roles` and
 * `permissions` tables (id, name unique, description, timestamps) when the
 * project opts into control-plane role management. Replaces the old
 * "create the table yourself in SQL" setup hint with a guided action.
 */
class AuthBootstrapService
{
    public static function tableSpec(): array
    {
        return [
            ['name' => 'id', 'type' => 'serial', 'nullable' => false, 'default' => null, 'pk' => true, 'unique' => false],
            ['name' => 'name', 'type' => 'varchar', 'nullable' => false, 'default' => null, 'pk' => false, 'unique' => true],
            ['name' => 'description', 'type' => 'text', 'nullable' => true, 'default' => null, 'pk' => false, 'unique' => false],
        ];
    }

    /** @return list<string> tables created */
    public static function ensureTables(Project $project): array
    {
        $explorer = ProjectDatabaseExplorer::for($project);
        $existing = array_column($explorer->tables(), 'name');
        $created = [];
        foreach (['roles', 'permissions'] as $table) {
            if (! in_array($table, $existing, true)) {
                DdlService::createTable($project, $table, self::tableSpec());
                $created[] = $table;
            }
        }

        return $created;
    }
}
