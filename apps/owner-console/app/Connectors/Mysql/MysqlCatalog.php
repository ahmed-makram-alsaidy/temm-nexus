<?php

namespace App\Connectors\Mysql;

use App\Connectors\Mysql\Protocol\MysqlExecutor;

/**
 * Phase 31A — MySQL/MariaDB catalog inspection (information_schema).
 *
 * information_schema on MySQL 8+/MariaDB 10.5+ carries the full 31A
 * surface (columns with COLUMN_TYPE including UNSIGNED, EXTRA with
 * auto_increment/GENERATED, keys, routines, events, charsets). READ-only.
 */
class MysqlCatalog
{
    public function __construct(protected MysqlExecutor $db)
    {
    }

    public function executor(): MysqlExecutor
    {
        return $this->db;
    }

    /** Server identity + session read-only confirmation (31 read-only). */
    public function serverInfo(): array
    {
        $version = (string) $this->db->scalar('SELECT VERSION()');
        $readOnly = (string) $this->db->scalar('SELECT @@SESSION.transaction_read_only');
        $maria = str_contains(strtolower($version), 'mariadb');

        return [
            'version' => $version,
            'flavor' => $maria ? 'mariadb' : 'mysql',
            'transaction_read_only' => in_array($readOnly, ['1', 'on', 'true'], true) ? 'on' : 'off',
        ];
    }

    /** Base tables of one database (views excluded — inventoried separately). */
    public function tables(string $database): array
    {
        return $this->db->rows(
            'SELECT table_name, engine, table_collation, auto_increment, table_rows, table_comment
             FROM information_schema.tables
             WHERE table_schema = ? AND table_type = \'BASE TABLE\'
             ORDER BY table_name',
            [$database]
        );
    }

    /** Columns of one table — COLUMN_TYPE carries the FULL type (31B/31C). */
    public function columns(string $database, string $table): array
    {
        return $this->db->rows(
            'SELECT column_name, ordinal_position, column_type, is_nullable, column_default, column_key,
                    extra, generation_expression, character_set_name, collation_name,
                    numeric_precision, numeric_scale, datetime_precision
             FROM information_schema.columns
             WHERE table_schema = ? AND table_name = ?
             ORDER BY ordinal_position',
            [$database, $table]
        );
    }

    /** Primary key columns (ordered). */
    public function primaryKey(string $database, string $table): array
    {
        return array_map(
            fn ($row) => (string) $row['column_name'],
            $this->db->rows(
                "SELECT column_name
                 FROM information_schema.key_column_usage
                 WHERE table_schema = ? AND table_name = ? AND constraint_name = 'PRIMARY'
                 ORDER BY ordinal_position",
                [$database, $table]
            )
        );
    }

    /** Foreign keys. */
    public function foreignKeys(string $database, string $table): array
    {
        return $this->db->rows(
            'SELECT constraint_name, column_name, referenced_table_name, referenced_column_name
             FROM information_schema.key_column_usage
             WHERE table_schema = ? AND table_name = ? AND referenced_table_name IS NOT NULL
             ORDER BY constraint_name, ordinal_position',
            [$database, $table]
        );
    }

    /** Secondary indexes (PK excluded). */
    public function indexes(string $database, string $table): array
    {
        return $this->db->rows(
            'SELECT index_name, non_unique, column_name, seq_in_index
             FROM information_schema.statistics
             WHERE table_schema = ? AND table_name = ? AND index_name <> \'PRIMARY\'
             ORDER BY index_name, seq_in_index',
            [$database, $table]
        );
    }

    /** Views of one database. */
    public function views(string $database): array
    {
        return $this->db->rows(
            'SELECT table_name AS view_name, view_definition
             FROM information_schema.views
             WHERE table_schema = ? ORDER BY table_name',
            [$database]
        );
    }

    /** Triggers of one database. */
    public function triggers(string $database): array
    {
        return $this->db->rows(
            'SELECT trigger_name, event_object_table, action_timing, event_manipulation, action_statement
             FROM information_schema.triggers
             WHERE trigger_schema = ? ORDER BY trigger_name',
            [$database]
        );
    }

    /** Stored procedures + functions (31A routines inventory). */
    public function routines(string $database): array
    {
        return $this->db->rows(
            'SELECT routine_name, routine_type, data_type, dtd_identifier, routine_definition
             FROM information_schema.routines
             WHERE routine_schema = ? ORDER BY routine_name',
            [$database]
        );
    }

    /** Scheduled events (schedule_metadata — 31A events). */
    public function events(string $database): array
    {
        return $this->db->rows(
            'SELECT event_name, event_definition, interval_value, interval_field, status, last_executed
             FROM information_schema.events
             WHERE event_schema = ? ORDER BY event_name',
            [$database]
        );
    }
}
