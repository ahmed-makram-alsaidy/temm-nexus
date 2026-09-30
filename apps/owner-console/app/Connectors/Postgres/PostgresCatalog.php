<?php

namespace App\Connectors\Postgres;

use App\Connectors\Postgres\Protocol\PgExecutor;

/**
 * Phase 30B — PostgreSQL catalog inspection (pg_catalog-native).
 *
 * Deliberately NOT information_schema-based: pg_catalog carries the full
 * fidelity the 30B inventory needs (generated columns, identity columns,
 * partition bounds, matviews, RLS forcing, extension types) that
 * information_schema either lacks or truncates. Every query is READ-only.
 */
class PostgresCatalog
{
    /** Schemas that are never import candidates (30B system namespaces). */
    public const SYSTEM_SCHEMAS = [
        'pg_catalog', 'pg_toast', 'pg_temp_1', 'pg_toast_temp_1', 'information_schema',
    ];

    public function __construct(protected PgExecutor $db)
    {
    }

    public function executor(): PgExecutor
    {
        return $this->db;
    }

    /** Server identity + effective settings relevant to migration fidelity. */
    public function serverInfo(): array
    {
        $version = (string) $this->db->scalar('SELECT current_setting(\'server_version_num\')');
        $settings = $this->db->rows(
            "SELECT name, setting FROM pg_settings WHERE name IN ('default_transaction_read_only', 'transaction_read_only', 'server_encoding', 'lc_ctype', 'max_identifier_length')"
        );
        $byName = [];
        foreach ($settings as $setting) {
            $byName[$setting['name']] = $setting['setting'];
        }

        return [
            'version_num' => (int) $version,
            'transaction_read_only' => $byName['transaction_read_only'] ?? 'off',
            'server_encoding' => $byName['server_encoding'] ?? 'unknown',
            'lc_ctype' => $byName['lc_ctype'] ?? 'unknown',
        ];
    }

    /** @return list<string> importable schema names (system schemas excluded). */
    public function schemas(): array
    {
        return array_map(
            fn ($row) => (string) $row['nspname'],
            $this->db->rows(
                "SELECT n.nspname FROM pg_namespace n
                 WHERE n.nspname NOT LIKE 'pg\\\\_%' AND n.nspname <> 'information_schema'
                 ORDER BY n.nspname"
            )
        );
    }

    /**
     * Tables + partitioned tables of one schema (relkind r/p), with row
     * estimates, RLS flags and persistence.
     *
     * @return list<array<string, mixed>>
     */
    public function tables(string $schema): array
    {
        return $this->db->rows(
            "SELECT c.oid::text AS oid, c.relname, c.relkind, c.relrowsecurity, c.relforcerowsecurity,
                    GREATEST(c.reltuples, 0)::bigint AS row_estimate,
                    pg_get_expr(c.relpartbound, c.oid) AS partition_bound,
                    EXISTS (SELECT 1 FROM pg_inherits i WHERE i.inhparent = c.oid) AS has_partitions
             FROM pg_class c
             JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE n.nspname = ? AND c.relkind IN ('r', 'p')
             ORDER BY c.relname",
            [$schema]
        );
    }

    /** Columns of one table with EXACT type preservation (30C). */
    public function columns(string $schema, string $table): array
    {
        return $this->db->rows(
            "SELECT a.attname, a.attnum, a.attnotnull, a.attidentity, a.attgenerated,
                    format_type(a.atttypid, a.atttypmod) AS pg_type,
                    t.typcategory, t.typtype,
                    pg_get_expr(d.adbin, d.adrelid) AS default_expr,
                    CASE WHEN a.attidentity <> '' THEN true ELSE false END AS is_identity,
                    CASE WHEN a.attgenerated <> '' THEN true ELSE false END AS is_generated
             FROM pg_attribute a
             JOIN pg_type t ON t.oid = a.atttypid
             LEFT JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum
             WHERE a.attrelid = (SELECT c.oid FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
                                 WHERE n.nspname = ? AND c.relname = ?)
               AND a.attnum > 0 AND NOT a.attisdropped
             ORDER BY a.attnum",
            [$schema, $table]
        );
    }

    /** Primary key columns (ordered by constraint position). */
    public function primaryKey(string $schema, string $table): array
    {
        $rows = $this->db->rows(
            "SELECT a.attname
             FROM pg_constraint con
             JOIN pg_class c ON c.oid = con.conrelid
             JOIN pg_namespace n ON n.oid = c.relnamespace
             JOIN unnest(con.conkey) WITH ORDINALITY AS k(attnum, ord) ON true
             JOIN pg_attribute a ON a.attrelid = con.conrelid AND a.attnum = k.attnum
             WHERE n.nspname = ? AND c.relname = ? AND con.contype = 'p'
             ORDER BY k.ord",
            [$schema, $table]
        );

        return array_map(fn ($row) => (string) $row['attname'], $rows);
    }

    /** Foreign keys with full target qualification + deferrability. */
    public function foreignKeys(string $schema, string $table): array
    {
        return $this->db->rows(
            "SELECT con.conname,
                    src_a.attname AS column_name,
                    tgt_c.relname AS references_table,
                    tgt_n.nspname AS references_schema,
                    tgt_a.attname AS references_column,
                    con.condeferrable, con.condeferred, con.confupdtype, con.confdeltype
             FROM pg_constraint con
             JOIN pg_class src_c ON src_c.oid = con.conrelid
             JOIN pg_namespace src_n ON src_n.oid = src_c.relnamespace
             JOIN pg_class tgt_c ON tgt_c.oid = con.confrelid
             JOIN pg_namespace tgt_n ON tgt_n.oid = tgt_c.relnamespace
             JOIN unnest(con.conkey) WITH ORDINALITY AS k(attnum, ord) ON true
             JOIN pg_attribute src_a ON src_a.attrelid = con.conrelid AND src_a.attnum = k.attnum
             JOIN unnest(con.confkey) WITH ORDINALITY AS tk(attnum, ord) ON tk.ord = k.ord
             JOIN pg_attribute tgt_a ON tgt_a.attrelid = con.confrelid AND tgt_a.attnum = tk.attnum
             WHERE src_n.nspname = ? AND src_c.relname = ? AND con.contype = 'f'
             ORDER BY con.conname, k.ord",
            [$schema, $table]
        );
    }

    /** Check / unique / exclusion constraints (PK + FK covered separately). */
    public function checkConstraints(string $schema, string $table): array
    {
        return $this->db->rows(
            "SELECT con.conname, con.contype, pg_get_constraintdef(con.oid) AS definition
             FROM pg_constraint con
             JOIN pg_class c ON c.oid = con.conrelid
             JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE n.nspname = ? AND c.relname = ? AND con.contype IN ('c', 'u', 'x')
             ORDER BY con.conname",
            [$schema, $table]
        );
    }

    /** Indexes (PK index excluded — covered by the primary key). */
    public function indexes(string $schema, string $table): array
    {
        return $this->db->rows(
            "SELECT idx.indexrelid::text AS index_oid, ic.relname AS index_name,
                    idx.indisunique, idx.indisprimary, idx.indpred IS NOT NULL AS is_partial,
                    pg_get_indexdef(idx.indexrelid) AS definition,
                    (SELECT string_agg(COALESCE(pg_get_indexdef(idx.indexrelid, k, NULL), a.attname), ', ')
                       FROM generate_series(1, idx.indnkeyatts) k
                       LEFT JOIN pg_attribute a ON a.attrelid = idx.indrelid AND a.attnum = idx.indkey[k]) AS key_columns
             FROM pg_index idx
             JOIN pg_class tc ON tc.oid = idx.indrelid
             JOIN pg_namespace n ON n.oid = tc.relnamespace
             JOIN pg_class ic ON ic.oid = idx.indexrelid
             WHERE n.nspname = ? AND tc.relname = ? AND NOT idx.indisprimary
             ORDER BY ic.relname",
            [$schema, $table]
        );
    }

    /** Views + materialized views of one schema. */
    public function views(string $schema): array
    {
        return $this->db->rows(
            "SELECT c.relname, c.relkind, pg_get_viewdef(c.oid, true) AS definition
             FROM pg_class c
             JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE n.nspname = ? AND c.relkind IN ('v', 'm')
             ORDER BY c.relname",
            [$schema]
        );
    }

    /** Sequences owned by table columns + their current state (30D). */
    public function sequences(string $schema): array
    {
        return $this->db->rows(
            "SELECT s.relname AS sequence_name,
                    sn.nspname AS sequence_schema,
                    tn.nspname AS owned_by_table_schema,
                    tc.relname AS owned_by_table,
                    a.attname AS owned_by_column,
                    pg_sequences.last_value, pg_sequences.data_type
             FROM pg_class s
             JOIN pg_namespace sn ON sn.oid = s.relnamespace
             LEFT JOIN pg_depend d ON d.objid = s.oid AND d.deptype = 'a'
             LEFT JOIN pg_class tc ON tc.oid = d.refobjid
             LEFT JOIN pg_namespace tn ON tn.oid = tc.relnamespace
             LEFT JOIN pg_attribute a ON a.attrelid = d.refobjid AND a.attnum = d.refobjsubid
             LEFT JOIN pg_sequences ON pg_sequences.schemaname = sn.nspname AND pg_sequences.sequencename = s.relname
             WHERE sn.nspname = ? AND s.relkind = 'S'
             ORDER BY s.relname",
            [$schema]
        );
    }

    /** Functions / procedures / aggregates (30B — metadata only). */
    public function functions(string $schema): array
    {
        return $this->db->rows(
            "SELECT p.proname, p.prokind, l.lanname AS language, p.prosecdef,
                    pg_get_function_identity_arguments(p.oid) AS identity_arguments,
                    pg_get_function_result(p.oid) AS result_type,
                    pg_get_functiondef(p.oid) AS definition
             FROM pg_proc p
             JOIN pg_namespace n ON n.oid = p.pronamespace
             JOIN pg_language l ON l.oid = p.prolang
             WHERE n.nspname = ?
               AND p.oid NOT IN (SELECT objid FROM pg_depend WHERE deptype = 'e')
             ORDER BY p.proname",
            [$schema]
        );
    }

    /** Non-internal triggers. */
    public function triggers(string $schema): array
    {
        return $this->db->rows(
            "SELECT t.tgname, c.relname AS table_name, pg_get_triggerdef(t.oid) AS definition
             FROM pg_trigger t
             JOIN pg_class c ON c.oid = t.tgrelid
             JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE n.nspname = ? AND NOT t.tgisinternal
             ORDER BY c.relname, t.tgname",
            [$schema]
        );
    }

    /** Extensions installed in this database (types they add → NEEDS_REVIEW). */
    public function extensions(): array
    {
        return $this->db->rows(
            'SELECT e.extname, e.extversion FROM pg_extension e ORDER BY e.extname'
        );
    }

    /** Enum types of one schema. */
    public function enums(string $schema): array
    {
        $rows = $this->db->rows(
            "SELECT t.typname, e.enumlabel
             FROM pg_type t
             JOIN pg_namespace n ON n.oid = t.typnamespace
             JOIN pg_enum e ON e.enumtypid = t.oid
             WHERE n.nspname = ?
             ORDER BY t.typname, e.enumsortorder",
            [$schema]
        );
        $enums = [];
        foreach ($rows as $row) {
            $enums[$row['typname']][] = (string) $row['enumlabel'];
        }

        return $enums;
    }

    /** Domain types of one schema (base type + constraints). */
    public function domains(string $schema): array
    {
        return $this->db->rows(
            "SELECT t.typname, format_type(t.typbasetype, t.typtypmod) AS base_type,
                    t.typnotnull, t.typdefault,
                    (SELECT pg_get_constraintdef(con.oid) FROM pg_constraint con WHERE con.contypid = t.oid LIMIT 1) AS check_definition
             FROM pg_type t
             JOIN pg_namespace n ON n.oid = t.typnamespace
             WHERE n.nspname = ? AND t.typtype = 'd'
             ORDER BY t.typname",
            [$schema]
        );
    }

    /** Partition children of a partitioned table (30B partitioning metadata). */
    public function partitions(string $schema, string $table): array
    {
        return $this->db->rows(
            "SELECT c.relname AS partition_name, pg_get_expr(c.relpartbound, c.oid) AS bound
             FROM pg_inherits i
             JOIN pg_class p ON p.oid = i.inhparent
             JOIN pg_namespace pn ON pn.oid = p.relnamespace
             JOIN pg_class c ON c.oid = i.inhrelid
             WHERE pn.nspname = ? AND p.relname = ?
             ORDER BY c.relname",
            [$schema, $table]
        );
    }

    /** RLS policies (30B — the policy metadata domain, native here). */
    public function policies(string $schema, string $table): array
    {
        return $this->db->rows(
            "SELECT pol.polname AS policy_name, c.relname AS table_name,
                    CASE pol.polcmd WHEN 'r' THEN 'SELECT' WHEN 'a' THEN 'INSERT' WHEN 'w' THEN 'UPDATE' WHEN 'd' THEN 'DELETE' WHEN '*' THEN 'ALL' END AS command,
                    pol.polroles::text[] AS roles,
                    pg_get_expr(pol.polqual, pol.polrelid) AS using_expression,
                    pg_get_expr(pol.polwithcheck, pol.polrelid) AS check_expression
             FROM pg_policy pol
             JOIN pg_class c ON c.oid = pol.polrelid
             JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE n.nspname = ? AND c.relname = ?
             ORDER BY pol.polname",
            [$schema, $table]
        );
    }

    /** Large object metadata inventory (30B — counts + sizes, not contents). */
    public function largeObjects(): array
    {
        // pg_largeobject_metadata's OID column is `oid` (35.5: verified live
        // on PostgreSQL 17 — `loid` only ever existed in ancient releases;
        // aliased back to loid so the subquery correlation stays readable).
        $row = $this->db->rows(
            'SELECT COUNT(*)::bigint AS count, COALESCE(SUM(size), 0)::bigint AS bytes
             FROM (SELECT lom.oid AS loid, (SELECT COALESCE(SUM(pg_column_size(lo.data)), 0)
                                    FROM pg_largeobject lo WHERE lo.loid = lom.oid) AS size
                   FROM pg_largeobject_metadata lom) t'
        );

        return [
            'count' => (int) ($row[0]['count'] ?? 0),
            'bytes' => (int) ($row[0]['bytes'] ?? 0),
            'note' => 'Large object CONTENTS are inventoried only — streaming them is a rehearsal-time decision (30B).',
        ];
    }
}
