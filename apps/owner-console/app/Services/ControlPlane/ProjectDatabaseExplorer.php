<?php

namespace App\Services\ControlPlane;

use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read + safe-write access to ONE project's database via query builder only.
 * No raw SQL execution is exposed to the GUI. All identifiers are validated
 * against information_schema so URL/query-string tampering cannot escape the
 * project connection or reach other databases.
 */
class ProjectDatabaseExplorer
{
    public function __construct(protected Project $project, protected string $connection) {}

    public static function for(Project $project): self
    {
        return new self($project, ProjectConnectionManager::connection($project));
    }

    public function project(): Project
    {
        return $this->project;
    }

    public function connectionName(): string
    {
        return $this->connection;
    }

    /** @return list<array{name:string,type:string,rows:int|null}> */
    public function tables(): array
    {
        $rows = DB::connection($this->connection)->select(
            "SELECT tablename AS name, 'table' AS type, c.reltuples::bigint AS rows
               FROM pg_tables t
               LEFT JOIN pg_class c ON c.relname = t.tablename
              WHERE schemaname = 'public'
              ORDER BY tablename"
        );
        $views = DB::connection($this->connection)->select(
            "SELECT viewname AS name, 'view' AS type, NULL AS rows
               FROM pg_views WHERE schemaname = 'public' ORDER BY viewname"
        );

        return array_map(
            fn ($r) => ['name' => $r->name, 'type' => $r->type, 'rows' => $r->rows !== null ? (int) $r->rows : null],
            array_merge($rows, $views)
        );
    }

    public function assertTable(string $table): string
    {
        abort_unless(preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/', $table) === 1, 404);

        $exists = DB::connection($this->connection)->selectOne(
            "SELECT 1 AS ok FROM information_schema.tables WHERE table_schema = 'public' AND table_name = ?",
            [$table]
        );
        abort_unless($exists !== null, 404, 'Unknown table.');

        return $table;
    }

    /** @return list<array{name:string,type:string,nullable:bool,default:?string,pk:bool}> */
    public function columns(string $table): array
    {
        $table = $this->assertTable($table);

        return array_map(function ($c) {
            return [
                'name' => $c->column_name,
                'type' => $c->data_type.($c->character_maximum_length ? "({$c->character_maximum_length})" : ''),
                'nullable' => $c->is_nullable === 'YES',
                'default' => $c->column_default,
                'pk' => (bool) $c->is_pk,
            ];
        }, DB::connection($this->connection)->select(
            "SELECT c.column_name, c.data_type, c.character_maximum_length, c.is_nullable, c.column_default,
                    CASE WHEN kcu.column_name IS NOT NULL THEN true ELSE false END AS is_pk
               FROM information_schema.columns c
               LEFT JOIN information_schema.table_constraints tc
                 ON tc.table_schema = c.table_schema AND tc.table_name = c.table_name AND tc.constraint_type = 'PRIMARY KEY'
               LEFT JOIN information_schema.key_column_usage kcu
                 ON kcu.constraint_name = tc.constraint_name AND kcu.column_name = c.column_name
              WHERE c.table_schema = 'public' AND c.table_name = ?
              ORDER BY c.ordinal_position",
            [$table]
        ));
    }

    /** @return list<array{from:string,to_table:string,to_column:string}> */
    public function foreignKeys(string $table): array
    {
        $table = $this->assertTable($table);

        return array_map(fn ($r) => [
            'from' => $r->from_column,
            'to_table' => $r->to_table,
            'to_column' => $r->to_column,
        ], DB::connection($this->connection)->select(
            "SELECT kcu.column_name AS from_column, ccu.table_name AS to_table, ccu.column_name AS to_column
               FROM information_schema.table_constraints tc
               JOIN information_schema.key_column_usage kcu
                 ON tc.constraint_name = kcu.constraint_name AND tc.table_schema = kcu.table_schema
               JOIN information_schema.constraint_column_usage ccu ON ccu.constraint_name = tc.constraint_name
              WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_schema = 'public' AND tc.table_name = ?
              ORDER BY kcu.ordinal_position",
            [$table]
        ));
    }

    /** @return list<array{name:string,definition:string}> */
    public function indexes(string $table): array
    {
        $table = $this->assertTable($table);

        return array_map(fn ($r) => [
            'name' => $r->indexname,
            'definition' => $r->indexdef,
        ], DB::connection($this->connection)->select(
            'SELECT indexname, indexdef FROM pg_indexes WHERE schemaname = \'public\' AND tablename = ? ORDER BY indexname',
            [$table]
        ));
    }

    public function exactCount(string $table): int
    {
        $table = $this->assertTable($table);

        return (int) DB::connection($this->connection)->table($table)->count();
    }

    public function query(string $table)
    {
        return DB::connection($this->connection)->table($this->assertTable($table));
    }

    public function find(string $table, int|string $id): ?object
    {
        $table = $this->assertTable($table);
        $pk = $this->primaryKey($table) ?? 'id';

        return DB::connection($this->connection)->table($table)->where($pk, $id)->first();
    }

    public function primaryKey(string $table): ?string
    {
        foreach ($this->columns($table) as $col) {
            if ($col['pk']) {
                return $col['name'];
            }
        }

        return Schema::connection($this->connection)->hasColumn($table, 'id') ? 'id' : null;
    }

    public function insert(string $table, array $data): int|string
    {
        $this->assertWritable($table);

        return DB::connection($this->connection)->table($table)->insertGetId($data, $this->primaryKey($table));
    }

    public function update(string $table, int|string $id, array $data): int
    {
        $this->assertWritable($table);
        $pk = $this->primaryKey($table) ?? 'id';

        return DB::connection($this->connection)->table($table)->where($pk, $id)->limit(1)->update($data);
    }

    public function delete(string $table, int|string $id): int
    {
        $this->assertWritable($table);
        $pk = $this->primaryKey($table) ?? 'id';

        return DB::connection($this->connection)->table($table)->where($pk, $id)->limit(1)->delete();
    }

    protected function assertWritable(string $table): void
    {
        $this->assertTable($table);
        abort_if(ProtectedTables::isReadOnly($table), 403, 'This table is read-only in the control plane. Use protected pgAdmin for structural work.');
        abort_if($this->isView($table), 403, 'Views are read-only in the control plane.');
    }

    protected function isView(string $table): bool
    {
        foreach ($this->tables() as $t) {
            if ($t['name'] === $table) {
                return $t['type'] === 'view';
            }
        }

        return false;
    }

    /** @return list<string> text-ish columns usable for search */
    public function searchableColumns(string $table): array
    {
        $out = [];
        foreach ($this->columns($table) as $col) {
            if (preg_match('/char|text|mail|name|title|slug|description|note/i', $col['type'].$col['name'])) {
                $out[] = $col['name'];
            }
        }

        return array_slice($out, 0, 6);
    }
}
