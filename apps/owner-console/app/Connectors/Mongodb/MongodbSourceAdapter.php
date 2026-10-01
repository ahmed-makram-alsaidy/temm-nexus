<?php

namespace App\Connectors\Mongodb;

use App\Connectors\Mongodb\Protocol\BsonCodec;
use App\Connectors\Mongodb\Protocol\MongoWireClient;
use App\Connectors\Mongodb\Protocol\MongoCommandException;
use App\Models\MigrationSource;
use App\Services\ControlPlane\Connectors\Support\BaseSourceAdapter;
use App\Services\ControlPlane\Connectors\Support\ConnectorNetworkGuard;
use App\Services\ControlPlane\Migration\SchemaFingerprint;

/**
 * Phase 28A/28H — MongoDB source adapter (read-only).
 *
 * Implements the Phase 24 SourceAdapter contract on top of the pure-PHP wire
 * client. READ-ONLY GUARANTEE: the underlying client has a hard allowlist of
 * read commands — write commands are structurally impossible to send; every
 * operation below is discovery/inspection only (28 read-only requirement).
 *
 * Normalized inventory (27B.2): the selected database maps to a schema,
 * collections to tables (with the DECIDED mapping strategy baked into the
 * columns), MongoDB views to views, GridFS buckets to the storage domain.
 * MongoDB-specific evidence (validators, variance, relationship candidates,
 * index types, strategies) lives under the 'mongodb' metadata section and in
 * per-table attributes — core never needs to understand it.
 */
class MongodbSourceAdapter extends BaseSourceAdapter
{
    protected ?MongoWireClient $client = null;
    protected ?array $analysisCache = null;
    protected string $database = '';

    public static function id(): string
    {
        return 'mongodb';
    }

    /** Build the effective URI (28B.1/28B.2) and connect (28B.3 guarded). */
    public function connect(): void
    {
        if ($this->client?->isConnected()) {
            return;
        }
        $config = $this->source->connection ?? [];
        $this->database = (string) ($config['database'] ?? '');

        $uri = $this->effectiveUri($config);
        $hosts = $this->resolveHosts($uri);
        $mayUseLocal = $this->localSourceAllowed();
        foreach ($hosts as $host) {
            ConnectorNetworkGuard::assertSafeHost($host['host'], $host['port'], $mayUseLocal);
        }

        $secret = $this->sourceSecret('uri');
        $this->client = new MongoWireClient(
            $uri,
            (int) ($config['server_selection_timeout_ms'] ?? 10000),
            (string) ($config['read_preference'] ?? 'primary'),
            $secret !== '' ? MongoWireClient::parseUri($uri) : null,
        );
        $this->client->connect();
        $this->connection = ['database' => $this->database, 'hosts' => count($hosts)];
    }

    /**
     * Effective connection URI: the `uri` credential when present, otherwise
     * assembled from split configuration (28B.2). The URI is used only to
     * build the client — it is never logged, echoed or persisted.
     */
    protected function effectiveUri(array $config): string
    {
        $uri = $this->sourceSecret('uri');
        if ($uri !== '') {
            if ($this->database !== '' && ! preg_match('/\/([^\/?]+)(\?|$)/', $uri)) {
                $uri = rtrim($uri, '/').'/'.$this->database;
            }

            return $uri;
        }
        $host = (string) ($config['host'] ?? '');
        if ($host === '') {
            throw new \InvalidArgumentException('mongodb connector requires a connection URI (or host configuration).');
        }
        $username = $this->sourceSecret('username');
        $password = $this->sourceSecret('password');
        $auth = $username !== null && $username !== ''
            ? rawurlencode($username).':'.rawurlencode((string) $password).'@'
            : '';
        $port = (int) ($config['port'] ?? 27017);
        $tls = ($config['tls'] ?? false) ? '?tls=true' : '';
        $authSource = $config['auth_source'] ?? null;
        if ($authSource !== null) {
            $tls = $tls === '' ? '?authSource='.$authSource : $tls.'&authSource='.$authSource;
        }

        return sprintf('mongodb://%s%s:%d/%s%s', $auth, $host, $port, $this->database, $tls);
    }

    protected function sourceSecret(string $field): string
    {
        $refs = (array) ($this->source->secret_refs ?? []);
        $name = $refs[$field] ?? null;
        if (! is_string($name) || $name === '') {
            return '';
        }
        $values = \App\Services\ControlPlane\SecretService::valuesFor($this->source->project, [$name]);
        $value = $values[$name] ?? '';

        return (string) $value;
    }

    /** @return list<array{host: string, port: int}> */
    protected function resolveHosts(string $uri): array
    {
        $parsed = MongoWireClient::parseUri($uri);
        $mayUseLocal = $this->localSourceAllowed();
        if ($parsed['srv']) {
            // SRV targets resolve at connect time; guard the seed host now
            // and each resolved target inside MongoWireClient::seedlist().
            $host = (string) ($parsed['hosts'][0] ?? '');
            ConnectorNetworkGuard::assertSafeHost($host, 27017, $mayUseLocal);

            return [['host' => $host, 'port' => 27017]];
        }
        $hosts = [];
        foreach ($parsed['hosts'] as $hostPort) {
            $colon = strrpos($hostPort, ':');
            $host = $colon === false ? $hostPort : substr($hostPort, 0, $colon);
            $port = $colon === false ? 27017 : (int) substr($hostPort, $colon + 1);
            // 28B.3 — every resolved host passes the SSRF guard HERE (the
            // host-resolution boundary), not only on the connect path.
            ConnectorNetworkGuard::assertSafeHost($host, $port, $mayUseLocal);
            $hosts[] = ['host' => $host, 'port' => $port];
        }

        return $hosts;
    }

    /** Operator allowance + declared permission (28B.3 — both required). */
    protected function localSourceAllowed(): bool
    {
        return (bool) config('connectors.allow_private_networks', false);
    }

    /** Close the wire connection (engine calls close() after analysis). */
    public function close(): void
    {
        $this->client?->close();
        $this->client = null;
        $this->analysisCache = null;
        parent::close();
    }

    public function client(): MongoWireClient
    {
        if ($this->client === null) {
            $this->connect();
        }

        return $this->client;
    }

    // ── Inventory (28C/28D/28E/28F/28G/28N) ─────────────────────────────

    public function inventory(): array
    {
        $this->connect();

        return $this->analysisCache ??= $this->computeAnalysis();
    }

    /** Full read-only analysis, memoized for the adapter's lifetime. */
    public function analysis(): array
    {
        return $this->inventory();
    }

    protected function computeAnalysis(): array
    {
        $database = $this->database;
        if ($database === '') {
            throw new \InvalidArgumentException('mongodb connector requires a selected database (28C — single-database import).');
        }

        // 28T — connector-instance substitution defense: the selected
        // database must be genuinely accessible (a bogus name would otherwise
        // silently produce an empty inventory instead of an honest refusal).
        $accessible = [];
        foreach ($this->client()->listDatabases() as $doc) {
            $accessible[] = (string) (BsonCodec::untag($doc['name'] ?? null) ?? '');
        }
        if (! in_array($database, $accessible, true)) {
            throw new \InvalidArgumentException('Database '.$database.' is not accessible with these credentials — check the database name (28T instance-substitution defense).');
        }

        $collections = $this->listCollections($database);
        $sampleSize = (int) ($this->source->connection['sample_size'] ?? SchemaInferer::DEFAULT_SAMPLE_SIZE);

        $inference = [];
        $collectionOptions = [];
        $indexes = [];
        $counts = [];
        $validators = [];
        $stats = [];

        foreach ($collections['collections'] as $name) {
            $counts[$name] = $this->countRows($database, $name);
            $sample = [];
            foreach ($this->client()->streamFind($database, $name, [], ['sort' => ['_id' => 1], 'limit' => $sampleSize, 'batchSize' => min($sampleSize, 101)], null) as $document) {
                $sample[] = $document;
                if (count($sample) >= $sampleSize) {
                    break;
                }
            }
            $inference[$name] = (new SchemaInferer)->infer($sample);
            $collectionOptions[$name] = $collections['options'][$name] ?? [];
            $validators[$name] = $this->extractValidator($collectionOptions[$name]);
            $indexes[$name] = $this->listIndexes($database, $name);
            $stats[$name] = $this->collStats($database, $name);
        }

        // 28F — relationship candidates across the database.
        $relationships = (new RelationshipInferer)->infer($inference);

        // 28I — deterministic per-collection mapping strategies.
        $strategies = [];
        foreach ($inference as $name => $inf) {
            $strategies[$name] = $this->decideStrategy($inf, $validators[$name] !== null);
        }

        // 28N — GridFS buckets (never ordinary tables).
        $gridfs = new GridFsInspector;
        $gridfsBuckets = $gridfs->detectBuckets(
            $collections['collections'],
            fn ($name) => $counts[$name] ?? 0,
            fn ($name) => $stats[$name]['bytes'] ?? 0
        );
        $gridfsNames = [];
        foreach ($gridfsBuckets as $bucket) {
            $gridfsNames[$bucket['files_collection']] = true;
            $gridfsNames[$bucket['chunks_collection']] = true;
        }

        // ── Normalized tables ────────────────────────────────────────────
        $this->realCollectionNames = array_flip($collections['collections']);
        $tables = [];
        $mongodbMeta = [
            'database' => $database,
            'sample_size' => $sampleSize,
            'collections' => [],
            'validators' => $validators,
            'relationships' => $relationships,
            'strategies' => $strategies,
            'gridfs' => $gridfsBuckets,
            'capped' => [],
            'timeseries' => [],
            'sharded' => [],
            'index_types' => $this->classifyIndexes($indexes),
        ];

        foreach ($inference as $name => $inf) {
            if (isset($gridfsNames[$name])) {
                continue; // 28N — GridFS pairs are reported as storage, not tables
            }
            $mongodbMeta['collections'][$name] = [
                'documents' => $counts[$name] ?? 0,
                'avg_obj_size_bytes' => $stats[$name]['avg_obj_size'] ?? null,
                'is_view' => false,
                'capped' => (bool) ($collectionOptions[$name]['capped']['v'] ?? false),
                'validator' => $validators[$name] !== null,
                'schema_variance_fields' => count(array_filter($inf['fields'], fn ($f) => $f['variance'])),
            ];
            if ($mongodbMeta['collections'][$name]['capped']) {
                $mongodbMeta['capped'][] = $name;
            }
            if (isset($collectionOptions[$name]['timeseries'])) {
                $mongodbMeta['timeseries'][] = $name;
            }
            $built = $this->buildTable($database, $name, $inf, $strategies[$name], $relationships, $indexes[$name], $validators[$name], $counts[$name] ?? 0);
            $tables[] = $built['table'];
            foreach ($built['children'] as $child) {
                $tables[] = $child;
            }
        }

        // Views (28D — where supported).
        $views = [];
        foreach ($collections['views'] as $name) {
            $views[] = ['schema' => $database, 'name' => $name];
            $mongodbMeta['collections'][$name] = ['documents' => null, 'avg_obj_size_bytes' => null, 'is_view' => true, 'capped' => false, 'validator' => false, 'schema_variance_fields' => 0];
        }

        return [
            'schemas' => [$database],
            'tables' => $tables,
            'views' => $views,
            'matviews' => [],
            'enums' => [],
            'functions' => [],
            'triggers' => [],
            'policies' => [],
            'extensions' => [],
            'auth' => ['present' => false, 'users_count' => 0, 'identities_count' => 0, 'providers' => [], 'hash_strategy' => 'no_auth_domain'],
            'storage' => [
                'present' => $gridfsBuckets !== [],
                'buckets' => array_map(fn ($b) => ['name' => $b['name'], 'files' => $b['files'], 'bytes' => $b['bytes'], 'kind' => 'gridfs'], $gridfsBuckets),
                'object_counts' => collect($gridfsBuckets)->mapWithKeys(fn ($b) => [$b['name'] => ['objects' => $b['files'], 'bytes' => $b['bytes']]])->all(),
            ],
            'realtime' => ['present' => false, 'publication_tables' => []],
            'cron' => [],
            'edge_functions' => (array) ($this->source->connection['manifest']['edge_functions'] ?? []),
            'client_dependencies' => (array) ($this->source->connection['manifest']['client_dependencies'] ?? []),
            'mongodb' => $mongodbMeta,
        ];
    }

    /** 28C — listCollections (system collections excluded). */
    protected function listCollections(string $database): array
    {
        $collections = [];
        $views = [];
        $options = [];
        $reply = $this->client()->run('listCollections', [
            'nameOnly' => BsonCodec::tag(true, 'boolean'),
            'authorizedCollections' => BsonCodec::tag(true, 'boolean'),
        ], $database);
        foreach ($this->cursorDocuments($reply, $database) as $info) {
            $name = (string) (BsonCodec::untag($info['name'] ?? null) ?? '');
            if ($name === '' || str_starts_with($name, 'system.')) {
                continue; // 28C — internal namespaces excluded by default
            }
            $type = (string) (BsonCodec::untag($info['type'] ?? null) ?? 'collection');
            $opts = BsonCodec::untag($info['options'] ?? null);
            $options[$name] = is_array($opts) ? $opts : [];
            if ($type === 'view') {
                $views[] = $name;
            } else {
                $collections[] = $name;
            }
        }
        sort($collections);
        sort($views);

        return ['collections' => $collections, 'views' => $views, 'options' => $options];
    }

    /** Drain a firstBatch/getMore reply (listCollections has no $db cursor ns). */
    protected function cursorDocuments(array $reply, string $database): \Generator
    {
        $cursorDoc = MongoWireClient::docField($reply, 'cursor');
        $cursorId = MongoWireClient::intField($cursorDoc, 'id', 0);
        $batch = MongoWireClient::listField($cursorDoc, 'firstBatch');
        do {
            foreach ($batch as $document) {
                if (is_array($document) && ($document['t'] ?? null) === 'document') {
                    yield (array) $document['v'];
                }
            }
            if ($cursorId === 0) {
                break;
            }
            $ns = (string) ($cursorDoc['ns']['v'] ?? '');
            $collection = str_contains($ns, '.') ? substr($ns, strpos($ns, '.') + 1) : '';
            $reply = $this->client()->run('getMore', [
                'getMore' => BsonCodec::tag($cursorId, 'int64'),
                'batchSize' => BsonCodec::tag(1000, 'int32'),
                'collection' => BsonCodec::tag($collection, 'string'),
            ], $database);
            MongoWireClient::assertOk($reply, 'getMore');
            $cursorDoc = MongoWireClient::docField($reply, 'cursor');
            $batch = MongoWireClient::listField($cursorDoc, 'nextBatch');
            $cursorId = MongoWireClient::intField($cursorDoc, 'id', 0);
        } while (true);
    }

    /** 28G.2 — $jsonSchema / query validator from collection options. */
    protected function extractValidator(array $options): ?array
    {
        $validator = BsonCodec::untag($options['validator'] ?? null);
        if (! is_array($validator) || $validator === []) {
            return null;
        }

        return [
            'json_schema' => TypeMapper::canonicalJson(BsonCodec::tag($validator, 'document')),
            'validation_level' => (string) (BsonCodec::untag($options['validationLevel'] ?? null) ?? 'moderate'),
            'validation_action' => (string) (BsonCodec::untag($options['validationAction'] ?? null) ?? 'error'),
        ];
    }

    /** 28G — index inspection (read-only listIndexes). */
    protected function listIndexes(string $database, string $collection): array
    {
        try {
            $reply = $this->client()->run('listIndexes', [], $database, $collection);
        } catch (MongoCommandException) {
            return []; // namespace may not allow index listing — honest empty
        }
        $indexes = [];
        foreach ($this->cursorDocuments($reply, $database) as $index) {
            $key = BsonCodec::untag($index['key'] ?? null);
            $columns = [];
            $types = [];
            if (is_array($key)) {
                foreach ($key as $field => $direction) {
                    $dir = BsonCodec::untag($direction);
                    $columns[] = FieldNameSanitizer::baseName((string) $field);
                    $types[(string) $field] = is_scalar($dir) ? (string) $dir : 'compound-doc';
                }
            }
            $indexTypes = [];
            foreach ($types as $spec) {
                if ($spec === '2dsphere' || $spec === '2d' || $spec === 'geoHaystack') {
                    $indexTypes[] = 'geospatial';
                }
                if ($spec === 'text') {
                    $indexTypes[] = 'text';
                }
                if ($spec === 'hashed') {
                    $indexTypes[] = 'hashed';
                }
            }
            if (isset($index['expireAfterSeconds'])) {
                $indexTypes[] = 'ttl';
            }
            if ((bool) (BsonCodec::untag($index['sparse'] ?? null))) {
                $indexTypes[] = 'sparse';
            }
            if ((bool) (BsonCodec::untag($index['unique'] ?? null))) {
                $indexTypes[] = 'unique';
            }
            $indexes[] = [
                'name' => (string) (BsonCodec::untag($index['name'] ?? null) ?? ''),
                'unique' => in_array('unique', $indexTypes, true),
                'columns' => $columns,
                'types' => $indexTypes, // 28G.1 — ttl/text/2dsphere mapped with review, never silently approximated
                'expire_after_seconds' => isset($index['expireAfterSeconds']) ? (int) BsonCodec::untag($index['expireAfterSeconds']) : null,
            ];
        }

        return $indexes;
    }

    protected function classifyIndexes(array $indexesByCollection): array
    {
        $out = [];
        foreach ($indexesByCollection as $collection => $indexes) {
            foreach ($indexes as $index) {
                foreach ($index['types'] as $type) {
                    $out[$type] = ($out[$type] ?? 0) + 1;
                }
            }
        }

        return $out;
    }

    /** Collection count + best-effort average document size (28D). */
    public function countRows(string $schema, string $table): int
    {
        $this->connect();
        // 28L.1 — derived child tables have no source collection; their row
        // count is the exploded element count (validation expects parity).
        if (str_contains($table, '__') && ! isset($this->realCollectionNames[$table])) {
            $total = 0;
            $this->streamRows($schema, $table, [], function () use (&$total) {
                $total++;
            }, 1000);

            return $total;
        }
        $reply = $this->client()->run('count', [], $schema !== '' ? $schema : $this->database, $table);
        MongoWireClient::assertOk($reply, 'count');

        return MongoWireClient::intField($reply, 'n', 0);
    }

    /** Best-effort $collStats — returns [] when permissions/topology refuse. */
    protected function collStats(string $database, string $collection): array
    {
        try {
            foreach ($this->client()->streamAggregate($database, $collection, [['$collStats' => ['storageStats' => BsonCodec::tag((object) [], 'document')]]], 1) as $stats) {
                $storage = BsonCodec::untag($stats['storageStats'] ?? null);

                return is_array($storage) ? [
                    'avg_obj_size' => isset($storage['avgObjSize']) ? (int) BsonCodec::untag($storage['avgObjSize']) : null,
                    'bytes' => isset($storage['size']) ? (int) BsonCodec::untag($storage['size']) : 0,
                ] : [];
            }
        } catch (\Throwable) {
            return []; // honest "not available"
        }

        return [];
    }

    // ── 28I — mapping strategies ─────────────────────────────────────────

    /** Deterministic, explainable strategy decision (28I — no forced normalization). */
    public function decideStrategy(array $inference, bool $hasValidator): array
    {
        $fields = array_filter($inference['fields'], fn ($f, $path) => ! str_contains((string) $path, '[]'), ARRAY_FILTER_USE_BOTH);
        $fieldCount = count($fields);
        $varianceFields = count(array_filter($fields, fn ($f) => $f['variance']));
        $varianceRatio = $fieldCount > 0 ? $varianceFields / $fieldCount : 0;
        $objectArrayFields = count(array_filter($inference['fields'], fn ($f) => str_ends_with($f['path'], '[]') === false && $f['array_shape'] !== null && in_array($f['array_shape'], ['document', 'mixed'], true)));
        $overflowFields = count(array_filter($fields, fn ($f) => $f['overflow']));

        if ($fieldCount > 60 || $varianceRatio > 0.5 || $overflowFields > 0) {
            $reason = $overflowFields > 0
                ? 'nesting depth exceeds safe inference depth (28L.2)'
                : ($varianceRatio > 0.5 ? sprintf('schema variance on %.0f%% of fields (28E.3)', $varianceRatio * 100) : sprintf('%d inferred fields (28I.2)', $fieldCount));

            return ['strategy' => 'JSONB_DOCUMENT', 'reason' => $reason];
        }
        if ($objectArrayFields === 0) {
            return ['strategy' => 'RELATIONAL_TABLE', 'reason' => $hasValidator ? 'stable shape backed by a collection validator (28G.2)' : 'stable shape across the sample (28I.1)'];
        }

        return ['strategy' => 'HYBRID', 'reason' => sprintf('%d array-of-object field(s): common fields as columns, variable payload as JSONB (28I.3), arrays split to child tables (28L.1)', $objectArrayFields)];
    }

    /** Build one normalized table (columns per strategy + derived child tables). */
    protected function buildTable(string $database, string $name, array $inference, array $strategy, array $relationships, array $indexes, ?array $validator, int $documentCount): array
    {
        $taken = ['_id' => '_id'];
        $columns = [['name' => '_id', 'type' => $inference['fields']['_id']['normalized_type'] ?? 'text', 'nullable' => false, 'default' => null]];
        $fieldMap = ['_id' => '_id'];
        $childTables = [];
        $strategyName = $strategy['strategy'];
        $flatFields = array_filter(
            $inference['fields'],
            fn ($f) => $f['path'] !== '_id' && ! str_contains($f['path'], '[]')
        );

        if ($strategyName === 'JSONB_DOCUMENT') {
            $columns[] = ['name' => 'document', 'type' => 'jsonb', 'nullable' => false, 'default' => null];
            $fieldMap['document'] = '(document)';
        } else {
            foreach ($flatFields as $field) {
                if ($field['array_shape'] !== null && in_array($field['array_shape'], ['document', 'mixed'], true)) {
                    continue; // handled as child table below
                }
                $column = FieldNameSanitizer::columnFor($field['path'], $taken);
                $columns[] = [
                    'name' => $column,
                    'type' => $field['array_shape'] !== null ? 'jsonb' : $field['normalized_type'],
                    'nullable' => $field['nullable'],
                    'default' => null,
                ];
                $fieldMap[$column] = $field['path'];
                if ($field['variance']) {
                    // variance evidence stays attached to the column (28E.3)
                }
            }
            if ($strategyName === 'HYBRID') {
                $columns[] = ['name' => 'document', 'type' => 'jsonb', 'nullable' => false, 'default' => null];
                $fieldMap['document'] = '(document)';
            }
            // 28L.1 — object arrays become derived child tables (name-safe).
            foreach ($inference['fields'] as $parentField => $arrayField) {
                if (str_contains((string) $parentField, '[]') || ! in_array($arrayField['array_shape'], ['document', 'mixed'], true)) {
                    continue;
                }
                $childName = $name.'__'.FieldNameSanitizer::baseName((string) $parentField);
                if (isset($this->realCollectionNames[$childName])) {
                    continue; // name collision — the array stays in the JSONB payload
                }
                $childTables[] = $this->buildChildTable($database, $name, $childName, (string) $parentField, $inference);
            }
        }

        $foreignKeys = [];
        foreach ($relationships as $candidate) {
            if (! $candidate['auto_fk'] || $candidate['collection'] !== $name) {
                continue;
            }
            // Array-ELEMENT references (items[].product_id) have no column on
            // the parent — they materialize through the derived child table
            // (28L.1); the candidate stays in the analysis metadata instead.
            if (str_contains($candidate['path'], '[]')) {
                continue;
            }
            $sourcePath = $candidate['path'];
            $sourcePath = $sourcePath === '_id' ? '_id' : $sourcePath;
            if (str_ends_with($sourcePath, '.$id')) {
                $sourcePath = substr($sourcePath, 0, -4);
            }
            $column = array_search($sourcePath, $fieldMap, true);
            if ($column === false) {
                $column = FieldNameSanitizer::baseName($sourcePath);
            }
            $foreignKeys[] = [
                'column' => $column,
                'references_schema' => $database,
                'references_table' => $candidate['references'],
                'references_column' => '_id',
                'confidence' => $candidate['confidence'],
            ];
        }

        $sanitizedIndexes = array_map(fn ($index) => [
            'name' => FieldNameSanitizer::safeMetadataName($index['name']),
            'unique' => $index['unique'],
            'columns' => $index['columns'],
        ], $indexes);

        return [
            'table' => [
                'schema' => $database,
                'name' => $name,
                'columns' => $columns,
                'primary_key' => ['_id'],
                'foreign_keys' => $foreignKeys,
                'indexes' => $sanitizedIndexes,
                'rls_enabled' => false,
                'row_estimate' => $documentCount,
                'migration_strategy' => $strategyName,
                'strategy_reason' => $strategy['reason'],
                'sanitized_field_map' => $fieldMap,
                'validator' => $validator !== null,
                'child_tables' => array_map(fn ($t) => $t['name'], $childTables),
            ],
            'children' => $childTables,
        ];
    }

    /** 28L.1 — derived child table for an array of objects. */
    protected function buildChildTable(string $database, string $parent, string $childName, string $parentField, array $inference): array
    {
        $taken = ['parent_id' => $parent.'._id', '__idx' => '(array index)'];
        $columns = [
            ['name' => 'parent_id', 'type' => $inference['fields']['_id']['normalized_type'] ?? 'text', 'nullable' => false, 'default' => null],
            ['name' => '__idx', 'type' => 'int4', 'nullable' => false, 'default' => null],
        ];
        $fieldMap = ['parent_id' => $parent.'._id', '__idx' => '(array index)'];
        foreach ($inference['fields'] as $path => $field) {
            if (! str_starts_with($path, $parentField.'[]'.'.')) {
                continue;
            }
            $elementPath = substr($path, strlen($parentField.'[].'));
            if ($elementPath === '' || str_contains($elementPath, '[]')) {
                continue;
            }
            $column = FieldNameSanitizer::columnFor($elementPath, $taken);
            $columns[] = [
                'name' => $column,
                'type' => $field['normalized_type'],
                // Array ELEMENTS are heterogeneous by nature: document-level
                // sampling cannot claim element-level NOT NULL (an element
                // field may be absent from some array items — 28E.3).
                'nullable' => true,
                'default' => null,
            ];
            $fieldMap[$column] = $elementPath;
        }

        return [
            'schema' => $database,
            'name' => $childName,
            'columns' => $columns,
            'primary_key' => [],
            'foreign_keys' => [[
                'column' => 'parent_id',
                'references_schema' => $database,
                'references_table' => $parent,
                'references_column' => '_id',
                'confidence' => 'EXPLICIT',
            ]],
            'indexes' => [],
            'rls_enabled' => false,
            'row_estimate' => 0,
            'migration_strategy' => 'ARRAY_CHILD_TABLE',
            'strategy_reason' => sprintf('derived from %s.%s[] (28L.1) — ordering preserved in __idx, parent linkage in parent_id', $parent, $parentField),
            'sanitized_field_map' => $fieldMap,
            'source_array_field' => $parentField,
            'validator' => false,
            'child_tables' => [],
        ];
    }

    /** Real collection names (for child-table collision checks) — set pre-build. */
    protected array $realCollectionNames = [];

    // ── 28H — extraction ─────────────────────────────────────────────────

    public function countRowsForTable(string $schema, string $table): int
    {
        return $this->countRows($schema, $table);
    }

    /** Batches streamed by the most recent extraction (28H progress). */
    public int $lastExtractionBatches = 0;

    /**
     * Stream one collection (or derived child table) in batches, projected to
     * the strategy columns. Cursor-ordered by _id for deterministic runs and
     * safe resumption (28H.1); bounded memory via server-side batching.
     */
    public function streamRows(string $schema, string $table, array $columns, callable $callback, int $batchSize = 500): int
    {
        $this->connect();
        $analysis = $this->analysis();
        $database = $analysis['mongodb']['database'];
        $this->lastExtractionBatches = 0;
        $batchConsumer = function (int $count): void {
            $this->lastExtractionBatches++;
        };

        // Derived child table?
        if (str_contains($table, '__')) {
            [$parent, $childField] = explode('__', $table, 2);
            if (isset($analysis['mongodb']['collections'][$parent])) {
                return $this->streamChildTable($analysis, $database, $parent, $table, $columns, $callback, $batchSize);
            }
        }

        $tableDef = collect($analysis['tables'])->firstWhere('name', $table);
        if ($tableDef === null) {
            throw new \InvalidArgumentException("Unknown collection '{$table}' for extraction");
        }
        $fieldMap = $tableDef['sanitized_field_map'];
        $strategy = $tableDef['migration_strategy'];
        $written = 0;

        $projector = $this->rowProjector($tableDef, $fieldMap, $strategy);
        foreach ($this->client()->streamFind($database, $table, [], ['sort' => ['_id' => 1], 'batchSize' => max(1, $batchSize)], $batchConsumer) as $document) {
            $row = $projector($document);
            $callback($columns === [] ? $row : array_intersect_key($row, array_flip($columns)));
            $written++;
        }

        return $written;
    }

    /** Build the per-document row projector for a strategy. */
    protected function rowProjector(array $tableDef, array $fieldMap, string $strategy): \Closure
    {
        return function (array $taggedDocument) use ($tableDef, $fieldMap, $strategy): array {
            $document = isset($taggedDocument['t']) && $taggedDocument['t'] === 'document'
                ? (array) $taggedDocument['v']
                : $taggedDocument;
            $row = [];
            foreach ($fieldMap as $column => $path) {
                if ($path === '(document)') {
                    $row[$column] = TypeMapper::canonicalJson($taggedDocument);
                    continue;
                }
                $row[$column] = $this->valueAtPath($document, $path);
            }

            return $row;
        };
    }

    /**
     * Phase 36 — public projector entry for CDC capture: build the SAME row
     * projector the snapshot extraction uses from the PLAN's persisted
     * field map, so change-stream rows carry identical columns (flattened
     * nested fields included).
     */
    public function cdcRowProjector(array $tableDef, array $fieldMap, string $strategy): \Closure
    {
        return $this->rowProjector($tableDef, $fieldMap, $strategy);
    }

    /** Resolve + convert a (possibly nested) source path against a document. */
    protected function valueAtPath(array $document, string $path): mixed
    {
        if ($path === '_id') {
            return isset($document['_id']) ? TypeMapper::convertValue($document['_id']) : null;
        }
        // Scalar arrays arrive as the array itself → canonical JSONB.
        $segments = explode('.', $path);
        $node = $document;
        foreach ($segments as $i => $segment) {
            if (! is_array($node) || ! isset($node[$segment])) {
                return null;
            }
            $node = $node[$segment];
            $isLast = $i === count($segments) - 1;
            if (! $isLast && is_array($node) && ($node['t'] ?? '') === 'document') {
                $node = (array) ($node['v'] ?? []);
            }
        }
        if (is_array($node) && ($node['t'] ?? '') === 'array') {
            return TypeMapper::canonicalJson($node);
        }

        return is_array($node) && isset($node['t']) ? TypeMapper::convertValue($node) : null;
    }

    /** 28L.1 — explode an array-of-objects into child rows. */
    protected function streamChildTable(array $analysis, string $database, string $parent, string $childName, array $columns, callable $callback, int $batchSize): int
    {
        $childDef = collect($analysis['tables'])->firstWhere('name', $childName);
        if ($childDef === null) {
            throw new \InvalidArgumentException("Unknown derived child table '{$childName}'");
        }
        $parentField = (string) ($childDef['source_array_field'] ?? '');
        $written = 0;
        // sanitized_field_map maps column → ELEMENT path (parent_id/__idx excluded).
        $elementColumns = array_filter(
            $childDef['sanitized_field_map'],
            fn ($path) => ! str_starts_with((string) $path, $parent.'._id') && $path !== '(array index)'
        );

        foreach ($this->client()->streamFind($database, $parent, [], ['sort' => ['_id' => 1], 'batchSize' => max(1, $batchSize)], null) as $document) {
            $doc = isset($document['t']) && $document['t'] === 'document' ? (array) $document['v'] : $document;
            $parentId = isset($doc['_id']) ? TypeMapper::convertValue($doc['_id']) : null;
            $arrayTagged = $doc[$parentField] ?? null;
            if (! is_array($arrayTagged) || ($arrayTagged['t'] ?? '') !== 'array') {
                continue;
            }
            foreach (array_values((array) ($arrayTagged['v'] ?? [])) as $index => $element) {
                if (! is_array($element) || ($element['t'] ?? '') !== 'document') {
                    continue;
                }
                $elementDoc = (array) ($element['v'] ?? []);
                $row = ['parent_id' => $parentId, '__idx' => $index];
                foreach ($elementColumns as $column => $elementPath) {
                    $row[$column] = $this->valueAtPath($elementDoc, (string) $elementPath);
                }
                $callback($columns === [] ? $row : array_intersect_key($row, array_flip($columns)));
                $written++;
            }
        }

        return $written;
    }

    /** Auth streams: MongoDB has no auth domain (28O) — nothing to stream. */
    public function streamAuthUsers(callable $callback, int $batchSize = 500): int
    {
        return 0;
    }

    // ── 28Q.2 — source fingerprint (also the immutability proof) ────────

    public function fingerprint(): string
    {
        return SchemaFingerprint::compute($this->inventory());
    }

    /** Deterministic document fingerprint (28Q.1) for validation. */
    public static function documentFingerprint(array $taggedDocument): string
    {
        return hash('sha256', TypeMapper::canonicalJson($taggedDocument));
    }
}
