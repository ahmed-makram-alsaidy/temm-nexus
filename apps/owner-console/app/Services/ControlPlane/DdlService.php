<?php

namespace App\Services\ControlPlane;

use App\Models\Project;
use App\Models\ProjectSchemaChange;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Phase 20E/20R visual schema operations on ONE project database.
 *
 * Rules: identifiers validated (strict pattern + information_schema asserts);
 * protected tables (migrations, auth internals, pulse, …) can never be
 * altered or dropped here; every operation writes a traceable
 * project_schema_changes row + audit entry (20S); destructive ops require the
 * caller to have verified typed confirmation (slug match) first.
 */
class DdlService
{
    public static function identifier(string $name, int $max = 63): string
    {
        abort_unless(
            preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,'.$max.'}$/', $name) === 1,
            422,
            'Invalid identifier.'
        );

        return $name;
    }

    public static function assertNotProtected(string $table): void
    {
        abort_if(ProtectedTables::isReadOnly($table), 403, 'This table is protected. Structural work stays in pgAdmin.');
    }

    /** @param list<array{name:string,type:string,nullable:bool,default:?string,pk:bool,unique:bool}> $columns */
    public static function createTable(Project $project, string $table, array $columns, ?string $fk = null): array
    {
        $table = self::identifier($table);
        $explorer = ProjectDatabaseExplorer::for($project);
        abort_if(self::tableExists($explorer, $table), 422, 'Table already exists.');

        $defs = [];
        $pk = [];
        foreach ($columns as $col) {
            $defs[] = self::columnDefinition($col, $pk);
        }
        if ($pk) {
            $defs[] = 'PRIMARY KEY ("'.implode('", "', $pk).'")';
        }
        $sql = 'CREATE TABLE "public"."'.$table.'" ('.implode(', ', $defs).')';
        DB::connection($explorer->connectionName())->statement($sql);

        $fkSql = null;
        if ($fk) {
            $fkSql = self::addForeignKey($project, $table, $fk['column'], $fk['to_table'], $fk['to_column'], true);
        }

        self::trace($project, 'table_created', ['table' => $table, 'columns' => array_column($columns, 'name')], $sql.($fkSql ? '; '.$fkSql : ''));

        return ['table' => $table];
    }

    /** @param array{name:string,type:string,nullable:bool,default:?string,pk:bool,unique:bool} $col */
    protected static function columnDefinition(array $col, array &$pk): string
    {
        $name = self::identifier($col['name']);
        $type = self::columnType($col['type']);
        $def = '"'.$name.'" '.$type;
        if (empty($col['nullable'])) {
            $def .= ' NOT NULL';
        }
        if (isset($col['default']) && $col['default'] !== '' && $col['default'] !== null) {
            $def .= ' DEFAULT '.self::defaultLiteral($col['default'], $type);
        }
        if (! empty($col['pk'])) {
            $pk[] = $name;
        }
        if (! empty($col['unique'])) {
            $def .= ' UNIQUE';
        }

        return $def;
    }

    public static function columnType(string $type): string
    {
        $allow = [
            'text' => 'TEXT', 'varchar' => 'VARCHAR(255)', 'varchar(255)' => 'VARCHAR(255)',
            'integer' => 'INTEGER', 'int' => 'INTEGER', 'bigint' => 'BIGINT',
            'boolean' => 'BOOLEAN', 'bool' => 'BOOLEAN',
            'timestamp' => 'TIMESTAMPTZ', 'timestamptz' => 'TIMESTAMPTZ', 'date' => 'DATE',
            'numeric' => 'NUMERIC', 'real' => 'REAL', 'double' => 'DOUBLE PRECISION',
            'json' => 'JSON', 'jsonb' => 'JSONB', 'uuid' => 'UUID',
            'serial' => 'SERIAL', 'bigserial' => 'BIGSERIAL',
        ];
        $key = strtolower(trim($type));
        abort_unless(isset($allow[$key]), 422, 'Unsupported column type.');

        return $allow[$key];
    }

    protected static function defaultLiteral(string $default, string $type): string
    {
        $d = trim($default);
        $upper = strtoupper($d);
        if (in_array($upper, ['NOW()', 'CURRENT_TIMESTAMP', 'CURRENT_DATE', 'TRUE', 'FALSE', 'NULL', 'GEN_RANDOM_UUID()'], true)) {
            return $upper === 'NULL' ? 'NULL' : $d;
        }
        if (is_numeric($d)) {
            return $d;
        }
        // String literal, single-quote escaped.
        return "'".str_replace("'", "''", $d)."'";
    }

    public static function addColumn(Project $project, string $table, array $col): string
    {
        $table = self::identifier($table);
        self::assertNotProtected($table);
        $explorer = ProjectDatabaseExplorer::for($project);
        $explorer->assertTable($table);
        $pk = [];
        $sql = 'ALTER TABLE "public"."'.$table.'" ADD COLUMN '.self::columnDefinition($col, $pk);
        DB::connection($explorer->connectionName())->statement($sql);
        self::trace($project, 'column_added', ['table' => $table, 'column' => $col['name']], $sql);

        return $sql;
    }

    public static function addForeignKey(Project $project, string $table, string $column, string $toTable, string $toColumn, bool $skipTrace = false): string
    {
        $table = self::identifier($table);
        $column = self::identifier($column);
        $toTable = self::identifier($toTable);
        $toColumn = self::identifier($toColumn);
        self::assertNotProtected($table);
        $explorer = ProjectDatabaseExplorer::for($project);
        $explorer->assertTable($table);
        $explorer->assertTable($toTable);
        $name = "fk_{$table}_{$column}";
        $sql = 'ALTER TABLE "public"."'.$table.'" ADD CONSTRAINT "'.$name
            .'" FOREIGN KEY ("'.$column.'") REFERENCES "public"."'.$toTable.'" ("'.$toColumn.'") ON DELETE RESTRICT';
        DB::connection($explorer->connectionName())->statement($sql);
        if (! $skipTrace) {
            self::trace($project, 'fk_created', ['table' => $table, 'column' => $column, 'references' => "{$toTable}({$toColumn})"], $sql);
        }

        return $sql;
    }

    public static function dropForeignKey(Project $project, string $table, string $constraint): string
    {
        $table = self::identifier($table);
        $constraint = self::identifier($constraint);
        self::assertNotProtected($table);
        $explorer = ProjectDatabaseExplorer::for($project);
        // Constraint must belong to this table (no blind drops).
        $row = DB::connection($explorer->connectionName())->selectOne(
            "SELECT 1 AS ok FROM information_schema.table_constraints
              WHERE table_schema='public' AND table_name=? AND constraint_name=? AND constraint_type='FOREIGN KEY'",
            [$table, $constraint]
        );
        abort_unless($row, 404, 'Unknown foreign-key constraint.');
        $sql = 'ALTER TABLE "public"."'.$table.'" DROP CONSTRAINT "'.$constraint.'"';
        DB::connection($explorer->connectionName())->statement($sql);
        self::trace($project, 'fk_dropped', ['table' => $table, 'constraint' => $constraint], $sql);

        return $sql;
    }

    public static function dropTable(Project $project, string $table): string
    {
        $table = self::identifier($table);
        self::assertNotProtected($table);
        $explorer = ProjectDatabaseExplorer::for($project);
        $explorer->assertTable($table);
        $sql = 'DROP TABLE "public"."'.$table.'"';
        DB::connection($explorer->connectionName())->statement($sql);
        self::trace($project, 'table_dropped', ['table' => $table], $sql);

        return $sql;
    }

    public static function tableExists(ProjectDatabaseExplorer $explorer, string $table): bool
    {
        $row = DB::connection($explorer->connectionName())->selectOne(
            "SELECT 1 AS ok FROM information_schema.tables WHERE table_schema='public' AND table_name=?",
            [$table]
        );

        return $row !== null;
    }

    protected static function trace(Project $project, string $kind, array $detail, string $sql): void
    {
        ProjectSchemaChange::create([
            'project_id' => $project->id, 'kind' => $kind,
            'detail' => $detail, 'sql' => mb_substr($sql, 0, 4000),
            'owner_user_id' => Auth::id(),
        ]);
    }
}
