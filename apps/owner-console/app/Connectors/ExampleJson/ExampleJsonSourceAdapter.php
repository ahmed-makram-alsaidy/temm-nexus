<?php

namespace App\Connectors\ExampleJson;

use App\Models\MigrationSource;
use App\Services\ControlPlane\Connectors\Support\BaseSourceAdapter;
use App\Services\ControlPlane\Connectors\Support\ProjectScopedFileReader;

/**
 * Phase 27N — Example JSON source adapter.
 *
 * Treats a directory of JSON files as a read-only data source (one file per
 * collection, each file a JSON array of objects). Builds the SAME normalized
 * inventory the Supabase adapter produces (27B.2), so the entire Migration
 * Center pipeline — analysis, plan, run, validation — works without any
 * Supabase participation. Proves the Connector SDK is provider-agnostic.
 *
 * Read-only guarantee (27H.3 analog): nothing here writes anywhere — the
 * dataset is only read, and reads are bounded by size/count guards.
 */
class ExampleJsonSourceAdapter extends BaseSourceAdapter
{
    /** Parsed collections: name => list<array> (memory-bounded by file caps). */
    protected ?array $collections = null;

    protected string $datasetPath = '';

    public static function id(): string
    {
        return 'example-json';
    }

    public function connect(): void
    {
        if ($this->collections !== null) {
            return;
        }
        $path = (string) ($this->source->connection['dataset_path'] ?? '');
        if ($path === '') {
            throw new \InvalidArgumentException('example-json connector requires a dataset_path configuration value.');
        }
        $this->datasetPath = $path;

        $files = ProjectScopedFileReader::datasetFiles($path, 'json', (int) config('connectors.limits.max_dataset_files', 50));
        $maxBytes = ((int) config('connectors.limits.max_dataset_file_kb', 10240)) * 1024;
        $collections = [];
        foreach ($files as $file) {
            $name = pathinfo($file, PATHINFO_FILENAME);
            $content = ProjectScopedFileReader::readFile($path, $file, ['json'], $maxBytes);
            $decoded = json_decode($content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \InvalidArgumentException("Dataset file '{$name}.json' is not valid JSON: ".json_last_error_msg());
            }
            // A collection is a JSON array of objects; an object is accepted
            // as a single-record collection (normalized to a one-element list).
            if (is_array($decoded) && ! array_is_list($decoded)) {
                $decoded = [$decoded];
            }
            abort_if(! is_array($decoded), 422, "Dataset file '{$name}.json' must contain a JSON array of objects.");
            $decoded = array_values(array_filter($decoded, 'is_array'));
            $collections[$name] = $decoded;
        }
        $this->collections = $collections;
    }

    /** Normalized inventory (27B.2) — same shape as every source adapter. */
    public function inventory(): array
    {
        $this->connect();

        $tables = [];
        foreach ($this->collections as $name => $records) {
            $tables[] = [
                'schema' => 'public',
                'name' => $name,
                'columns' => $this->inferColumns($records),
                'primary_key' => $this->hasId($records) ? ['id'] : [],
                'foreign_keys' => $this->inferForeignKeys($name, $records),
                'indexes' => [],
                'rls_enabled' => false,
                'row_estimate' => count($records),
            ];
        }

        return [
            'schemas' => ['public'],
            'tables' => $tables,
            'views' => [], 'matviews' => [], 'enums' => [], 'functions' => [],
            'triggers' => [], 'policies' => [], 'extensions' => [],
            'auth' => ['present' => false, 'users_count' => 0, 'identities_count' => 0, 'providers' => [], 'hash_strategy' => 'not_supabase_auth'],
            'storage' => ['present' => false, 'buckets' => [], 'object_counts' => []],
            'realtime' => ['present' => false, 'publication_tables' => []],
            'cron' => [],
            'edge_functions' => (array) ($this->source->connection['manifest']['edge_functions'] ?? []),
            'client_dependencies' => (array) ($this->source->connection['manifest']['client_dependencies'] ?? []),
        ];
    }

    public function countRows(string $schema, string $table): int
    {
        $this->connect();

        return count($this->collections[$table] ?? []);
    }

    /** 27B.3 — batched, callback-driven extraction (never one giant payload). */
    public function streamRows(string $schema, string $table, array $columns, callable $callback, int $batchSize = 500): int
    {
        $this->connect();
        $records = $this->collections[$table] ?? [];
        $ordered = $this->sortedRecords($records, $table);
        $projected = $columns === []
            ? null
            : array_flip($columns);
        $total = 0;
        foreach (array_chunk($ordered, max(1, $batchSize)) as $batch) {
            foreach ($batch as $row) {
                $callback($projected === null ? $row : array_intersect_key($row, $projected));
                $total++;
            }
        }

        return $total;
    }

    // ── Inference internals (schema inference, 27N.2) ───────────────────

    /**
     * Inferred scalar types are normalized to the engine's PostgreSQL-style
     * type vocabulary (27B.2 — the same vocabulary every source adapter and
     * target adapter speaks), so plans and target schema builds work
     * unchanged.
     */
    public const TYPE_MAP = [
        'int' => 'int8', 'float' => 'float8', 'boolean' => 'boolean',
        'datetime' => 'timestamptz', 'uuid' => 'uuid', 'json' => 'jsonb',
        'string' => 'text',
    ];

    protected function inferColumns(array $records): array
    {
        $order = [];
        $types = [];
        $nullable = [];
        foreach ($records as $record) {
            foreach ($record as $key => $value) {
                if (! isset($types[$key])) {
                    $order[] = $key;
                    $types[$key] = null;
                    $nullable[$key] = false;
                }
                if ($value === null) {
                    $nullable[$key] = true;
                    continue;
                }
                $type = self::inferType($value);
                if ($types[$key] === null || $types[$key] === $type) {
                    $types[$key] = $type;
                } elseif (in_array('int', [$types[$key], $type], true) && in_array('float', [$types[$key], $type], true)) {
                    $types[$key] = 'float';
                } else {
                    $types[$key] = 'string';
                }
            }
        }
        $columns = [];
        foreach ($order as $key) {
            $columns[] = [
                'name' => $key,
                'type' => self::TYPE_MAP[$types[$key]] ?? 'text',
                'nullable' => $nullable[$key] || ! $this->allRecordsHave($records, $key),
                'default' => null,
            ];
        }

        return $columns;
    }

    protected function allRecordsHave(array $records, string $key): bool
    {
        foreach ($records as $record) {
            if (! array_key_exists($key, $record)) {
                return false;
            }
        }

        return true;
    }

    protected function hasId(array $records): bool
    {
        return $records !== [] && $this->allRecordsHave($records, 'id')
            && ! in_array(null, array_map(fn ($r) => $r['id'], $records), true);
    }

    /** Relationship inference by convention: <singular(collection)>_id → collection.id. */
    protected function inferForeignKeys(string $collection, array $records): array
    {
        $fks = [];
        $candidateColumns = [];
        foreach ($records as $record) {
            foreach (array_keys($record) as $key) {
                if (str_ends_with((string) $key, '_id')) {
                    $candidateColumns[$key] = true;
                }
            }
        }
        foreach (array_keys($candidateColumns) as $column) {
            $referenced = self::collectionFromForeignKey($column);
            if ($referenced !== null && isset($this->collections[$referenced])) {
                $fks[] = [
                    'column' => $column,
                    'references_schema' => 'public',
                    'references_table' => $referenced,
                    'references_column' => 'id',
                ];
            }
        }

        return $fks;
    }

    /** Deterministic PK-ordered extraction when an id exists (stable validation). */
    protected function sortedRecords(array $records, string $table): array
    {
        if (! $this->hasId($records)) {
            return $records;
        }
        $ids = array_column($records, 'id');
        if (! in_array(false, array_map('is_numeric', $ids), true)) {
            usort($records, fn ($a, $b) => $a['id'] <=> $b['id']);
        } else {
            usort($records, fn ($a, $b) => strcmp((string) $a['id'], (string) $b['id']));
        }

        return $records;
    }

    public static function inferType(mixed $value): string
    {
        if (is_bool($value)) {
            return 'boolean';
        }
        if (is_int($value)) {
            return 'int';
        }
        if (is_float($value)) {
            return 'float';
        }
        if (is_array($value)) {
            return 'json';
        }
        if (is_string($value)) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:?\d{2})?$/', $value) === 1) {
                return 'datetime';
            }
            if (preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $value) === 1) {
                return 'uuid';
            }
        }

        return 'string';
    }

    public static function collectionFromForeignKey(string $column): ?string
    {
        if (! str_ends_with($column, '_id')) {
            return null;
        }
        $base = substr($column, 0, -3);
        // orders_items → order_items style pluralization: id column
        // `user_id` → collection `users`, `order_id` → `orders`.
        if (str_ends_with($base, 'y')) {
            return substr($base, 0, -1).'ies';
        }
        if (preg_match('/(s|x|z|ch|sh)$/', $base) === 1) {
            return $base.'es';
        }

        return $base.'s';
    }
}
