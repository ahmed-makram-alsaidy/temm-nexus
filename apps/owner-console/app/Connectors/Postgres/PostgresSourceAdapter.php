<?php

namespace App\Connectors\Postgres;

use App\Connectors\Postgres\Protocol\FixturePgExecutor;
use App\Connectors\Postgres\Protocol\PdoPgExecutor;
use App\Connectors\Postgres\Protocol\PgExecutor;
use App\Models\MigrationSource;
use App\Services\ControlPlane\Connectors\Support\BaseSourceAdapter;
use App\Services\ControlPlane\Migration\SchemaFingerprint;

/**
 * Phase 30A/30B/30C — PostgreSQL source adapter (read-only).
 *
 * Implements the Phase 24 SourceAdapter contract over the pg_catalog
 * inspector. READ-ONLY GUARANTEE (30A): the session is pinned
 * default_transaction_read_only=on AND transaction_read_only=on, the pin is
 * verified by reading the effective setting back, and the SQL surface is
 * allowlisted to SELECT/SHOW shapes. No source mutation is reachable.
 *
 * Normalized inventory (27B.2): tables carry the engine's PG-style type
 * vocabulary; the EXACT format_type() value is preserved per column as
 * source_type (30C). Sequences (with state), partitions, domains, generated
 * and identity columns, large objects and extension-driven NEEDS_REVIEW
 * types live under the 'postgres' metadata section.
 */
class PostgresSourceAdapter extends BaseSourceAdapter
{
    protected ?PgExecutor $executor = null;
    protected ?array $analysisCache = null;
    protected ?PostgresCatalog $catalog = null;

    public static function id(): string
    {
        return 'postgres';
    }

    public function connect(): void
    {
        if ($this->executor !== null) {
            return;
        }
        $config = $this->source->connection ?? [];
        $this->executor = $this->buildExecutor($config);
        $this->catalog = new PostgresCatalog($this->executor);
        $server = $this->catalog->serverInfo();
        if ($server['transaction_read_only'] !== 'on') {
            throw new \RuntimeException('postgres source is not in a read-only session — refusing to continue (30A).');
        }
        $this->connection = [
            'host' => $config['host'] ?? 'fixture',
            'database' => $config['database'] ?? $this->source->connection['database'] ?? '',
            'read_only_session' => true,
            'executor' => $this->executor->name(),
        ];
    }

    protected function buildExecutor(array $config): PgExecutor
    {
        if ((string) ($config['transport'] ?? '') === 'fixture') {
            return new FixturePgExecutor($this->fixtureDataset());
        }
        $refs = (array) ($this->source->secret_refs ?? []);
        $username = $this->secretValue($refs['username'] ?? null, (string) ($config['username'] ?? ''));
        $password = $this->secretValue($refs['password'] ?? null, '');

        return PdoPgExecutor::forSource($config, $username, $password, $this->localSourceAllowed());
    }

    /** Optional sandbox server-setting overrides (fixture transport only). */
    protected function fixtureDataset(): ?array
    {
        $overrides = (array) ($this->source->connection['fixture_server'] ?? []);
        if ($overrides === []) {
            return null;
        }
        $dataset = require __DIR__.'/datasets/synthetic-database.php';
        $dataset['server'] = array_merge($dataset['server'], $overrides);

        return $dataset;
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

    public function catalog(): PostgresCatalog
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

    // ── inventory (30B/30C) ──────────────────────────────────────────────

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
        $schemas = $catalog->schemas();
        // Optional operator filter (e.g. import only 'public'). The
        // configuration may arrive as a comma-separated string (35.6 live
        // finding: (array) 'public,archive' yields ONE element and the
        // intersect silently matched nothing — normalize first).
        $only = collect((array) ($this->source->connection['schemas'] ?? []))
            ->flatMap(fn ($s) => explode(',', (string) $s))
            ->map(fn ($s) => trim((string) $s))
            ->filter()
            ->values()
            ->all();
        if ($only !== []) {
            $schemas = array_values(array_intersect($schemas, $only));
        }
        $this->policyBuffer = [];
        $this->needsReviewBuffer = [];

        $tables = [];
        $views = [];
        $matviews = [];
        $enums = [];
        $functions = [];
        $triggers = [];
        // 0.6.0 Phase E (§E8): large objects and the extension list are
        // OPTIONAL metadata — a permission denial on them is a warning with a
        // stable check identifier, never a failed analysis. The live audit
        // specimen was `permission denied for table pg_largeobject` flipping
        // the whole source to ERROR; this boundary ends that.
        try {
            $largeObjects = $catalog->largeObjects();
        } catch (\Throwable $e) {
            $this->recordProbeWarning('postgres.large_objects', $e);
            $largeObjects = ['available' => false];
        }
        try {
            $extensions = array_map(fn ($e) => ['name' => $e['extname'], 'version' => $e['extversion']], $catalog->extensions());
        } catch (\Throwable $e) {
            $this->recordProbeWarning('postgres.extensions', $e);
            $extensions = [];
        }
        $postgresMeta = [
            'database' => $this->source->connection['database'] ?? '',
            'server' => $server,
            'schemas' => $schemas,
            'sequences' => [],
            'domains' => [],
            'procedures' => [],
            'partitions' => [],
            'large_objects' => $largeObjects,
            'extensions' => $extensions,
            'needs_review_types' => $this->needsReviewBuffer,
            'read_only_enforced' => $server['transaction_read_only'] === 'on',
        ];

        foreach ($schemas as $schema) {
            $enumsOfSchema = $catalog->enums($schema);
            foreach ($enumsOfSchema as $name => $values) {
                $enums[] = ['schema' => $schema, 'name' => $name, 'values' => $values];
            }
            $domainsOfSchema = $catalog->domains($schema);
            $domainBase = [];
            foreach ($domainsOfSchema as $domain) {
                $domainBase[$domain['typname']] = $domain['base_type'];
                $postgresMeta['domains'][] = ['schema' => $schema] + $domain;
            }

            foreach ($catalog->views($schema) as $view) {
                $entry = ['schema' => $schema, 'name' => $view['relname'], 'definition' => $view['definition']];
                if ($view['relkind'] === 'm') {
                    $matviews[] = $entry;
                } else {
                    $views[] = $entry;
                }
            }

            foreach ($catalog->sequences($schema) as $sequence) {
                $postgresMeta['sequences'][] = $sequence + ['schema' => $schema];
            }

            foreach ($catalog->functions($schema) as $function) {
                if ($function['prokind'] === 'p') {
                    $postgresMeta['procedures'][] = ['schema' => $schema] + $function;
                    continue;
                }
                $functions[] = [
                    'schema' => $schema,
                    'name' => $function['proname'],
                    'language' => $function['language'],
                    'security_definer' => (bool) $function['prosecdef'],
                    'identity_arguments' => $function['identity_arguments'],
                    'result_type' => $function['result_type'],
                    'definition' => $function['definition'],
                ];
            }

            foreach ($catalog->triggers($schema) as $trigger) {
                $triggers[] = ['schema' => $schema] + $trigger;
            }

            foreach ($catalog->tables($schema) as $tableInfo) {
                $tableName = (string) $tableInfo['relname'];
                $table = $this->buildTable($catalog, $schema, $tableName, $tableInfo, $enumsOfSchema, $domainBase);
                $tables[] = $table;
                if (self::pgBool($tableInfo['has_partitions'])) {
                    $postgresMeta['partitions'][] = [
                        'schema' => $schema,
                        'table' => $tableName,
                        'partitions' => $catalog->partitions($schema, $tableName),
                    ];
                }
            }
        }
        // Collected during the table loop — assigned after it (30C).
        $postgresMeta['needs_review_types'] = $this->needsReviewBuffer;

        return [
            'schemas' => $schemas,
            'tables' => $tables,
            'views' => $views,
            'matviews' => $matviews,
            'enums' => $enums,
            'functions' => $functions,
            'triggers' => $triggers,
            'policies' => $this->policyBuffer,
            'extensions' => $postgresMeta['extensions'],
            'auth' => ['present' => false, 'users_count' => 0, 'identities_count' => 0, 'providers' => [], 'hash_strategy' => 'no_auth_domain'],
            'storage' => ['present' => false, 'buckets' => [], 'object_counts' => []],
            'realtime' => ['present' => false, 'publication_tables' => []],
            'cron' => [],
            'edge_functions' => [],
            'client_dependencies' => [],
            'postgres' => $postgresMeta,
        ];
    }

    /** Build one normalized table with exact-type preservation (30C). */
    protected function buildTable(PostgresCatalog $catalog, string $schema, string $tableName, array $tableInfo, array $enumsOfSchema, array $domainBase): array
    {
        $columns = [];
        $needsReview = [];
        foreach ($catalog->columns($schema, $tableName) as $column) {
            $pgType = (string) $column['pg_type'];
            $isEnum = isset($enumsOfSchema[$pgType]);
            if ($isEnum) {
                $normalized = $pgType; // enum types pass through (target ensureEnum)
                $review = false;
            } elseif (isset($domainBase[$pgType])) {
                // Domains resolve to their base type; the domain stays recorded.
                $resolved = PgTypeMapper::normalize($domainBase[$pgType]);
                $normalized = $resolved['type'];
                $review = $resolved['needs_review'];
            } elseif (PgTypeMapper::isArray($pgType)) {
                $resolved = PgTypeMapper::normalizedArrayType($pgType);
                $normalized = $resolved['type'];
                $review = $resolved['needs_review'];
            } else {
                $resolved = PgTypeMapper::normalize($pgType);
                $normalized = $resolved['type'];
                $review = $resolved['needs_review'];
            }
            if ($review) {
                $needsReview[] = ['column' => $column['attname'], 'source_type' => $pgType];
                $this->needsReviewBuffer[] = ['schema' => $schema, 'table' => $tableName] + $needsReview[count($needsReview) - 1];
            }
            $isGenerated = self::pgBool($column['is_generated']);
            $isIdentity = self::pgBool($column['is_identity']);
            $columns[] = [
                'name' => (string) $column['attname'],
                'type' => $normalized,
                'nullable' => ! self::pgBool($column['attnotnull']),
                'default' => $isGenerated || $isIdentity ? null : ($column['default_expr'] ?? null),
                'source_type' => $pgType, // 30C — exact preservation
                'is_identity' => $isIdentity,
                'is_generated' => $isGenerated,
                'domain' => isset($domainBase[$pgType]) ? $pgType : null,
            ];
        }

        $primaryKey = $catalog->primaryKey($schema, $tableName);
        $foreignKeys = array_map(fn ($fk) => [
            'column' => (string) $fk['column_name'],
            'references_schema' => (string) $fk['references_schema'],
            'references_table' => (string) $fk['references_table'],
            'references_column' => (string) $fk['references_column'],
            'confidence' => 'EXPLICIT',
        ], $catalog->foreignKeys($schema, $tableName));

        $indexes = array_map(fn ($index) => [
            'name' => (string) $index['index_name'],
            'unique' => (bool) $index['indisunique'],
            'columns' => array_map(fn ($c) => trim(trim((string) $c), '"'), explode(',', (string) ($index['key_columns'] ?? ''))),
            'partial' => (bool) ($index['is_partial'] ?? false),
            'definition' => (string) ($index['definition'] ?? ''),
        ], $catalog->indexes($schema, $tableName));

        $tablePolicies = $catalog->policies($schema, $tableName);
        foreach ($tablePolicies as $policy) {
            $this->policyBuffer[] = ['schema' => $schema, 'table' => $tableName] + $policy;
        }

        $isPartitioned = self::pgBool($tableInfo['has_partitions']);

        return [
            'schema' => $schema,
            'name' => $tableName,
            'columns' => $columns,
            'primary_key' => $primaryKey,
            'foreign_keys' => $foreignKeys,
            'indexes' => $indexes,
            'rls_enabled' => self::pgBool($tableInfo['relrowsecurity']),
            'row_estimate' => max(0, (int) $tableInfo['row_estimate']),
            'migration_strategy' => $isPartitioned ? 'PARTITIONED_TABLE' : 'RELATIONAL_TABLE',
            'strategy_reason' => $isPartitioned
                ? 'partitioned parent (relkind p) — children inventoried under postgres.partitions (30B)'
                : 'native relational source (30B)',
            'is_partitioned' => $isPartitioned,
            'relkind' => (string) $tableInfo['relkind'],
            'resume' => $primaryKey !== [] ? 'keyset' : 'none',
            'child_tables' => [],
        ];
    }

    /** @var list<array<string, mixed>> collected while building tables */
    protected array $policyBuffer = [];

    /** @var list<array<string, string>> columns whose types need review (30C) */
    protected array $needsReviewBuffer = [];

    // ── extraction (30D — keyset, bounded, deterministic) ────────────────

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
     * — deterministic and resumable (30D). Tables without a PK fall back to
     * LIMIT/OFFSET batches and are reported as resume='none' in inventory.
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
            // Keyset pagination: (pk1, pk2) > (last1, last2) — exclusive,
            // deterministic, no duplicates, no skips.
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
            // No PK: bounded offset batches — honest 'resume: none'.
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

    /** PostgreSQL sources have no provider auth domain. */
    public function streamAuthUsers(callable $callback, int $batchSize = 500): int
    {
        return 0;
    }

    // ── fingerprint ──────────────────────────────────────────────────────

    public function fingerprint(): string
    {
        return SchemaFingerprint::compute($this->inventory());
    }

    /** Quote a validated SQL identifier (never interpolate raw names). */
    protected function qi(string $identifier): string
    {
        if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $identifier)) {
            throw new \InvalidArgumentException("Invalid identifier: {$identifier}");
        }

        return '"'.$identifier.'"';
    }

    /** PDO pgsql booleans arrive as 't'/'f' strings — coerce correctly. */
    protected static function pgBool(mixed $value): bool
    {
        return $value === 't' || $value === true || $value === 1 || $value === '1';
    }
}
