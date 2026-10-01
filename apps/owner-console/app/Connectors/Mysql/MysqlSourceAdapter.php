<?php

namespace App\Connectors\Mysql;

use App\Connectors\Mysql\Protocol\FixtureMysqlExecutor;
use App\Connectors\Mysql\Protocol\MysqlExecutor;
use App\Connectors\Mysql\Protocol\PdoMysqlExecutor;
use App\Models\MigrationSource;
use App\Services\ControlPlane\Connectors\Support\BaseSourceAdapter;
use App\Services\ControlPlane\Migration\SchemaFingerprint;

/**
 * Phase 31A-31E — MySQL/MariaDB source adapter (read-only).
 *
 * READ-ONLY GUARANTEE: the session is pinned TRANSACTION READ ONLY and the
 * pin is verified; the SQL surface is allowlisted to SELECT/SHOW shapes.
 *
 * Type mapping (31B/31C) is unsigned-safe and deterministic; AUTO_INCREMENT
 * state is captured per table (31D); non-UTF-8 charsets are flagged for
 * transcoding review (31E) — text is never silently transcoded here.
 */
class MysqlSourceAdapter extends BaseSourceAdapter
{
    protected ?MysqlExecutor $executor = null;
    protected ?array $analysisCache = null;
    protected ?MysqlCatalog $catalog = null;

    /** Character sets considered UTF-8-safe for a UTF-8 target (31E). */
    public const UTF8_CHARSETS = ['utf8mb4', 'utf8mb3', 'utf8', 'ucs2'];

    public static function id(): string
    {
        return 'mysql';
    }

    public function connect(): void
    {
        if ($this->executor !== null) {
            return;
        }
        $config = $this->source->connection ?? [];
        $this->executor = $this->buildExecutor($config);
        $this->catalog = new MysqlCatalog($this->executor);
        $server = $this->catalog->serverInfo();
        if ($server['transaction_read_only'] !== 'on') {
            throw new \RuntimeException('mysql source is not in a read-only session — refusing to continue (31).');
        }
        $this->connection = [
            'host' => $config['host'] ?? 'fixture',
            'database' => $config['database'] ?? '',
            'read_only_session' => true,
            'executor' => $this->executor->name(),
        ];
    }

    protected function buildExecutor(array $config): MysqlExecutor
    {
        if ((string) ($config['transport'] ?? '') === 'fixture') {
            return new FixtureMysqlExecutor;
        }
        $refs = (array) ($this->source->secret_refs ?? []);
        $username = $this->secretValue($refs['username'] ?? null, (string) ($config['username'] ?? ''));
        $password = $this->secretValue($refs['password'] ?? null, '');

        return PdoMysqlExecutor::forSource($config, $username, $password, $this->localSourceAllowed());
    }

    protected function secretValue(?string $name, string $fallback): string
    {
        if (! is_string($name) || $name === '') {
            return $fallback;
        }
        $values = \App\Services\ControlPlane\SecretService::valuesFor($this->source->project, [$name]);
        $value = $values[$name] ?? '';

        return (string) ($value !== '' ? $value : $fallback);
    }

    protected function localSourceAllowed(): bool
    {
        return (bool) config('connectors.allow_private_networks', false);
    }

    public function catalog(): MysqlCatalog
    {
        if ($this->catalog === null) {
            $this->connect();
        }

        return $this->catalog;
    }

    public function close(): void
    {
        $this->executor = null;
        $this->catalog = null;
        $this->analysisCache = null;
        parent::close();
    }

    // ── inventory (31A/31B/31C/31D/31E) ─────────────────────────────────

    public function inventory(): array
    {
        $this->connect();

        return $this->analysisCache ??= $this->computeAnalysis();
    }

    public function analysis(): array
    {
        return $this->inventory();
    }

    protected function computeAnalysis(): array
    {
        $catalog = $this->catalog();
        $server = $catalog->serverInfo();
        $database = (string) ($this->source->connection['database'] ?? '');

        $tables = [];
        $enums = [];
        $transcodingReview = [];
        $mysqlMeta = [
            'database' => $database,
            'server' => $server,
            'tables' => [],
            'procedures' => [],
            'auto_increment' => [],
            'transcoding_review' => [],
            'unsigned_widenings' => [],
            'read_only_enforced' => $server['transaction_read_only'] === 'on',
            'mariadb_compat_note' => $server['flavor'] === 'mariadb'
                ? 'MariaDB: PASS/PARTIAL by design — version-specific SQL modes and type corners are validated per rehearsal (31 acceptance).'
                : null,
        ];

        foreach ($catalog->tables($database) as $tableInfo) {
            $tableName = (string) $tableInfo['table_name'];
            $table = $this->buildTable($catalog, $database, $tableName, $tableInfo, $enums, $transcodingReview, $mysqlMeta);
            $tables[] = $table;
            if (! empty($tableInfo['auto_increment'])) {
                $mysqlMeta['auto_increment'][$tableName] = (string) $tableInfo['auto_increment'];
            }
            $mysqlMeta['tables'][$tableName] = [
                'engine' => (string) $tableInfo['engine'],
                'collation' => (string) $tableInfo['table_collation'],
                'row_estimate' => max(0, (int) $tableInfo['table_rows']),
            ];
        }
        // Collected during the table loop — assigned after it (31E).
        $mysqlMeta['transcoding_review'] = $transcodingReview;
        $mysqlMeta['unsigned_widenings'] = $mysqlMeta['unsigned_widenings'];

        foreach ($catalog->views($database) as $view) {
            $this->viewBuffer[] = ['schema' => $database, 'name' => (string) $view['view_name'], 'definition' => (string) $view['view_definition']];
        }
        foreach ($catalog->routines($database) as $routine) {
            if (($routine['routine_type'] ?? '') === 'PROCEDURE') {
                $mysqlMeta['procedures'][] = $routine;
                continue;
            }
            $this->functionBuffer[] = [
                'schema' => $database,
                'name' => (string) $routine['routine_name'],
                'language' => 'mysql',
                'security_definer' => (($routine['security_type'] ?? '') === 'DEFINER'),
                'identity_arguments' => (string) ($routine['parameters'] ?? ''),
                'result_type' => (string) ($routine['dtd_identifier'] ?? ''),
                'definition' => (string) ($routine['routine_definition'] ?? ''),
            ];
        }

        return [
            'schemas' => [$database],
            'tables' => $tables,
            'views' => $this->viewBuffer,
            'matviews' => [],
            'enums' => $enums,
            'functions' => $this->functionBuffer,
            'triggers' => $catalog->triggers($database),
            'policies' => [],
            'extensions' => [],
            'auth' => ['present' => false, 'users_count' => 0, 'identities_count' => 0, 'providers' => [], 'hash_strategy' => 'no_auth_domain'],
            'storage' => ['present' => false, 'buckets' => [], 'object_counts' => []],
            'realtime' => ['present' => false, 'publication_tables' => []],
            'cron' => array_map(fn ($event) => [
                'name' => (string) $event['event_name'],
                'schedule' => sprintf('every %s %s', $event['interval_value'], $event['interval_field']),
                'status' => (string) $event['status'],
                'definition' => (string) $event['event_definition'],
            ], $catalog->events($database)),
            'edge_functions' => [],
            'client_dependencies' => [],
            'mysql' => $mysqlMeta,
        ];
    }

    /** Build one normalized table (31B mapping + 31C widening + 31E charset). */
    protected function buildTable(MysqlCatalog $catalog, string $database, string $tableName, array $tableInfo, array &$enums, array &$transcodingReview, array &$mysqlMeta): array
    {
        $columns = [];
        foreach ($catalog->columns($database, $tableName) as $column) {
            $columnType = (string) $column['column_type'];
            $normalized = MysqlTypeMapper::normalize($columnType);
            if ($normalized['note'] !== null) {
                $mysqlMeta['unsigned_widenings'][$tableName.'.'.$column['column_name']] = $normalized['note'];
            }
            // 31E — non-UTF-8 text columns are flagged for transcoding review.
            $charset = $column['character_set_name'] ?? null;
            if ($charset !== null && ! in_array(strtolower((string) $charset), self::UTF8_CHARSETS, true)) {
                $transcodingReview[] = ['table' => $tableName, 'column' => (string) $column['column_name'], 'charset' => $charset];
            }
            $columnDefault = $column['column_default'] ?? null;
            $isGenerated = str_contains((string) ($column['extra'] ?? ''), 'GENERATED');
            $type = $normalized['type'];
            if ($normalized['type'] === 'text' && preg_match('/^enum\((.*)\)$/i', $columnType)) {
                // ENUM → a PostgreSQL enum type created via ensureEnum (31B).
                $enumName = NameSanitizer::tableName($tableName.'__'.$column['column_name']);
                $enums[] = ['schema' => $database, 'name' => $enumName, 'values' => MysqlTypeMapper::enumValues($columnType)];
                $type = $enumName;
            }
            $columns[] = [
                'name' => (string) $column['column_name'],
                'type' => $type,
                'nullable' => strtoupper((string) $column['is_nullable']) === 'YES',
                'default' => $isGenerated || str_contains((string) ($column['extra'] ?? ''), 'auto_increment') ? null : $columnDefault,
                'source_type' => $columnType, // 31B — full COLUMN_TYPE preserved
                'needs_review' => $normalized['needs_review'],
                'is_generated' => $isGenerated,
                'generation_expression' => $isGenerated ? (string) ($column['generation_expression'] ?? '') : null,
                'auto_increment' => str_contains((string) ($column['extra'] ?? ''), 'auto_increment'),
                'charset' => $charset,
            ];
        }

        $primaryKey = $catalog->primaryKey($database, $tableName);
        $foreignKeys = array_map(fn ($fk) => [
            'column' => (string) $fk['column_name'],
            'references_schema' => $database,
            'references_table' => (string) $fk['referenced_table_name'],
            'references_column' => (string) $fk['referenced_column_name'],
            'confidence' => 'EXPLICIT',
        ], $catalog->foreignKeys($database, $tableName));

        // Group secondary indexes (statistics rows come per column).
        $indexGroups = [];
        foreach ($catalog->indexes($database, $tableName) as $indexRow) {
            $indexGroups[(string) $indexRow['index_name']]['unique'] = ((int) $indexRow['non_unique']) === 0;
            $indexGroups[(string) $indexRow['index_name']]['columns'][] = (string) $indexRow['column_name'];
        }
        $indexes = array_map(fn ($name, $def) => [
            'name' => NameSanitizer::safeMetadataName($name),
            'unique' => $def['unique'],
            'columns' => $def['columns'],
        ], array_keys($indexGroups), $indexGroups);

        return [
            'schema' => $database,
            'name' => $tableName,
            'columns' => $columns,
            'primary_key' => $primaryKey,
            'foreign_keys' => $foreignKeys,
            'indexes' => $indexes,
            'rls_enabled' => false,
            'row_estimate' => max(0, (int) $tableInfo['table_rows']),
            'migration_strategy' => 'RELATIONAL_TABLE',
            'strategy_reason' => 'native relational source (31A)',
            'resume' => $primaryKey !== [] ? 'keyset' : 'none',
            'child_tables' => [],
        ];
    }

    /** @var list<array<string, mixed>> collected while building tables */
    protected array $viewBuffer = [];
    protected array $functionBuffer = [];

    // ── extraction (31D — keyset, bounded, deterministic) ────────────────

    public function countRows(string $schema, string $table): int
    {
        $this->connect();

        return (int) $this->catalog()->executor()->scalar(
            sprintf('SELECT COUNT(*) FROM %s.%s', $this->qi($schema), $this->qi($table))
        );
    }

    /** Batches streamed by the most recent extraction (progress). */
    public int $lastExtractionBatches = 0;

    /**
     * Stream one table in bounded keyset batches ordered by the primary key
     * — deterministic and resumable (31D). PK-less tables use bounded
     * offset batches and are reported resume='none'.
     */
    public function streamRows(string $schema, string $table, array $columns, callable $callback, int $batchSize = 500): int
    {
        $this->connect();
        $analysis = $this->inventory();
        $tableDef = collect($analysis['tables'])->firstWhere('name', $table)
            ?? collect($analysis['tables'])->first(fn ($t) => $t['schema'] === $schema && $t['name'] === $table);
        if ($tableDef === null) {
            throw new \InvalidArgumentException("Unknown table '{$schema}.{$table}' for extraction");
        }
        $batchSize = max(1, $batchSize);
        $this->lastExtractionBatches = 0;
        $written = 0;
        $pk = $tableDef['primary_key'];
        $projection = $columns === []
            ? '*'
            : implode(', ', array_map(fn ($c) => $this->qi((string) $c), $columns));

        if ($pk !== []) {
            $cursor = null;
            $pkList = implode(', ', array_map([$this, 'qi'], $pk));
            do {
                $sql = sprintf('SELECT %s FROM %s.%s', $projection, $this->qi($schema), $this->qi($table));
                $bindings = [];
                if ($cursor !== null) {
                    $sql .= sprintf(' WHERE (%s) > (%s)', $pkList, implode(', ', array_fill(0, count($pk), '?')));
                    $bindings = $cursor;
                }
                $sql .= sprintf(' ORDER BY %s LIMIT %d', $pkList, $batchSize);
                $rows = $this->catalog()->executor()->rows($sql, $bindings);
                $this->lastExtractionBatches++;
                foreach ($rows as $row) {
                    $callback(array_change_key_case((array) $row, CASE_LOWER));
                    $written++;
                }
                if ($rows === []) {
                    break;
                }
                $last = array_change_key_case((array) end($rows), CASE_LOWER);
                $cursor = array_map(fn ($col) => $last[$col] ?? null, $pk);
            } while (count($rows) === $batchSize);
        } else {
            $offset = 0;
            do {
                $sql = sprintf('SELECT %s FROM %s.%s LIMIT %d OFFSET %d', $projection, $this->qi($schema), $this->qi($table), $batchSize, $offset);
                $rows = $this->catalog()->executor()->rows($sql);
                $this->lastExtractionBatches++;
                foreach ($rows as $row) {
                    $callback(array_change_key_case((array) $row, CASE_LOWER));
                    $written++;
                }
                $offset += $batchSize;
            } while (count($rows) === $batchSize);
        }

        return $written;
    }

    /** MySQL sources have no provider auth domain. */
    public function streamAuthUsers(callable $callback, int $batchSize = 500): int
    {
        return 0;
    }

    // ── fingerprint ──────────────────────────────────────────────────────

    public function fingerprint(): string
    {
        return SchemaFingerprint::compute($this->inventory());
    }

    /** Quote a validated SQL identifier (MySQL backticks, then validate). */
    protected function qi(string $identifier): string
    {
        if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $identifier)) {
            throw new \InvalidArgumentException("Invalid identifier: {$identifier}");
        }

        return '`'.$identifier.'`';
    }
}
