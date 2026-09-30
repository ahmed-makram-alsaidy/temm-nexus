<?php

namespace App\Connectors\Firebase;

use App\Connectors\Firebase\Protocol\FirebaseRestClient;
use App\Connectors\Firebase\Protocol\FirebaseTransportException;
use App\Connectors\Firebase\Protocol\FixtureFirebaseTransport;
use App\Models\MigrationSource;
use App\Services\ControlPlane\Connectors\Support\ConnectorNetworkGuard;
use App\Services\ControlPlane\Connectors\Support\BaseSourceAdapter;
use App\Services\ControlPlane\Migration\SchemaFingerprint;

/**
 * Phase 29A/29C/29D/29E — Firebase source adapter (read-only).
 *
 * Implements the Phase 24 SourceAdapter contract over the Firebase REST
 * client. READ-ONLY GUARANTEE: only list/runQuery/batchGet/list-objects
 * calls are ever issued; no Firebase write surface is reachable through
 * this adapter.
 *
 * Normalized inventory (27B.2): each top-level collection (and each observed
 * subcollection) maps to a table with an inferred column set; the document id
 * is the '_id' primary key. Subcollections are structurally explicit children
 * (Firestore tree semantics → parent_id + EXPLICIT FK). Inference evidence,
 * relationship candidates, auth/storage/functions analysis live under the
 * 'firebase' metadata section and per-table attributes — the core never needs
 * to understand them.
 */
class FirebaseSourceAdapter extends BaseSourceAdapter
{
    protected ?FirebaseRestClient $client = null;
    protected ?array $analysisCache = null;
    protected string $schemaName = '';
    protected array $realTableNames = [];

    /** Normalized table name → source collection paths ('orders/o001/timeline'). */
    protected array $tablePaths = [];

    public static function id(): string
    {
        return 'firebase';
    }

    public function connect(): void
    {
        if ($this->client !== null) {
            return;
        }
        $config = $this->source->connection ?? [];
        $projectId = (string) ($config['project_id'] ?? '');
        if ($projectId === '') {
            throw new \InvalidArgumentException('firebase connector requires a project_id.');
        }
        $databaseId = (string) ($config['database_id'] ?? '(default)');
        $this->schemaName = NameSanitizer::schemaName($databaseId);

        $emulator = (bool) ($config['use_emulator'] ?? false);
        $emulatorHost = (string) ($config['emulator_host'] ?? '');
        if ($emulator) {
            if ($emulatorHost === '') {
                throw new \InvalidArgumentException('emulator mode requires emulator_host.');
            }
            [$host, $port] = $this->splitHostPort($emulatorHost, 8080);
            ConnectorNetworkGuard::assertSafeHost($host, $port, $this->localSourceAllowed());
        } elseif ((string) ($config['transport'] ?? '') !== 'fixture') {
            foreach (['firestore.googleapis.com', 'identitytoolkit.googleapis.com', 'firebasestorage.googleapis.com', 'cloudfunctions.googleapis.com'] as $apiHost) {
                ConnectorNetworkGuard::assertSafeHost($apiHost, 443, $this->localSourceAllowed());
            }
        }

        $this->client = new FirebaseRestClient(
            projectId: $projectId,
            databaseId: $databaseId,
            storageBucket: (string) ($config['storage_bucket'] ?? ''),
            emulator: $emulator,
            emulatorHost: $emulatorHost,
            account: $emulator ? null : $this->serviceAccount(),
            transport: $this->transport(),
        );
        $this->connection = [
            'project_id' => $projectId,
            'database_id' => $databaseId,
            'mode' => $emulator ? 'emulator' : ((string) ($config['transport'] ?? '') === 'fixture' ? 'fixture' : 'production'),
        ];
    }

    public function client(): FirebaseRestClient
    {
        if ($this->client === null) {
            $this->connect();
        }

        return $this->client;
    }

    public function close(): void
    {
        $this->client = null;
        $this->analysisCache = null;
        parent::close();
    }

    // ── credentials (29B — vault-resolved, never persisted) ─────────────

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

    protected function serviceAccount(): ?\App\Connectors\Firebase\Protocol\ServiceAccount
    {
        $json = $this->sourceSecret('service_account');
        if ($json === '') {
            return null;
        }

        return \App\Connectors\Firebase\Protocol\ServiceAccount::parse($json);
    }

    /** Transport override: 'fixture' serves the synthetic sandbox project (29J). */
    protected function transport(): ?\App\Connectors\Firebase\Protocol\FirebaseTransport
    {
        if ((string) ($this->source->connection['transport'] ?? '') === 'fixture') {
            return new FixtureFirebaseTransport;
        }

        return null;
    }

    protected function localSourceAllowed(): bool
    {
        return (bool) config('connectors.allow_private_networks', false);
    }

    /** @return array{0: string, 1: int} */
    protected function splitHostPort(string $hostPort, int $defaultPort): array
    {
        $colon = strrpos($hostPort, ':');

        return $colon === false
            ? [$hostPort, $defaultPort]
            : [substr($hostPort, 0, $colon), (int) substr($hostPort, $colon + 1)];
    }

    // ── inventory (29C/29D/29F/29G/29H) ─────────────────────────────────

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
        $sampleSize = (int) ($this->source->connection['sample_size'] ?? FirestoreAnalyzer::DEFAULT_SAMPLE_SIZE);

        $rootCollections = $this->client()->listRootCollections();
        $inference = [];
        $counts = [];
        $idDomains = [];
        $valueDomains = [];
        $subcollectionDocs = []; // subId => list of parent DOC paths ('orders/o001')
        $docParents = [];        // subId => parent collection id (first segment)

        $this->tablePaths = [];

        foreach ($rootCollections as $collectionId) {
            [$documents] = $this->sampleCollection($collectionId, $sampleSize);
            [$count, $idDomain, $valueDomain] = $this->domainsAndCount($documents, $collectionId);
            $tableName = NameSanitizer::tableName($collectionId);
            $this->tablePaths[$tableName] = [$collectionId];
            $inference[$tableName] = (new FirestoreAnalyzer)->infer($documents);
            $counts[$tableName] = $count;
            $idDomains[$tableName] = $idDomain;
            $valueDomains[$tableName] = $valueDomain;

            // Subcollections observed on up to 5 sampled documents (29C).
            $checked = 0;
            foreach ($documents as $document) {
                if ($checked >= 5) {
                    break;
                }
                $docPath = $this->documentRelativePath((string) ($document['name'] ?? ''));
                if ($docPath === '') {
                    continue;
                }
                $checked++;
                try {
                    foreach ($this->client()->listSubCollections($docPath) as $subId) {
                        $subcollectionDocs[$subId][] = $docPath;
                        $docParents[$subId] = explode('/', $docPath)[0];
                    }
                } catch (FirebaseTransportException) {
                    // subcollection discovery is best-effort (29C) — honest absence
                }
            }
        }

        // Subcollection tables (Firestore tree semantics → derived tables).
        $subTables = [];
        foreach ($subcollectionDocs as $subId => $parentDocPaths) {
            $documents = $this->fetchSubcollectionDocuments(array_values(array_unique($parentDocPaths)), $subId, $sampleSize);
            [$count, $idDomain, $valueDomain] = $this->domainsAndCount($documents, $parentDocPaths[0].'/'.$subId);
            $parentCollection = (string) ($docParents[$subId] ?? explode('/', $parentDocPaths[0])[0]);
            $tableName = NameSanitizer::baseName($parentCollection.'__'.$subId);
            $paths = array_values(array_unique(array_map(fn ($p) => $p.'/'.$subId, $parentDocPaths)));
            $this->tablePaths[$tableName] = $paths;
            $inference[$tableName] = (new FirestoreAnalyzer)->infer($documents);
            $counts[$tableName] = $count;
            $idDomains[$tableName] = $idDomain;
            $valueDomains[$tableName] = $valueDomain;
            $subTables[$tableName] = ['sub_id' => $subId, 'parent_collection' => $parentCollection, 'parent_documents' => array_values(array_unique($parentDocPaths))];
        }

        // 29D — relationship candidates.
        $relationships = (new RelationshipInferer)->infer($inference, $idDomains, $valueDomains);

        // 29F — auth inventory (no credential material ever leaves this path).
        $auth = $this->analyzeAuth();

        // 29G — storage inventory.
        $storage = $this->analyzeStorage();

        // 29H — functions inventory (where safely discoverable).
        $functions = $this->analyzeFunctions();

        // ── normalized tables ────────────────────────────────────────────
        $tables = [];
        $firebaseMeta = [
            'project_id' => $this->connection['project_id'] ?? '',
            'database_id' => $this->connection['database_id'] ?? '(default)',
            'sample_size' => $sampleSize,
            'collections' => [],
            'subcollections' => $subTables,
            'relationships' => $relationships,
            'auth' => $auth,
            'storage' => $storage,
            'functions' => $functions,
            'inference' => [
                'note' => 'Firestore has no authoritative relational schema — all field structure below is inferred evidence (29C).',
                'sample_size' => $sampleSize,
            ],
        ];

        foreach ($inference as $tableName => $inf) {
            $isSub = isset($subTables[$tableName]);
            $parentCollection = $isSub ? $subTables[$tableName]['parent_collection'] : null;
            $strategy = $this->decideStrategy($inf);
            $firebaseMeta['collections'][$tableName] = [
                'documents' => $counts[$tableName] ?? 0,
                'is_subcollection' => $isSub,
                'parent_collection' => $parentCollection,
                'schema_variance_fields' => count(array_filter($inf['fields'], fn ($f) => $f['variance'])),
                'reference_fields' => count(array_filter($inf['fields'], fn ($f) => ($f['firestore_types']['reference'] ?? 0) > 0)),
                'truncated' => $inf['truncated'],
            ];
            $built = $this->buildTable($this->schemaName, $tableName, $inf, $strategy, $relationships, $counts[$tableName] ?? 0, $isSub, $parentCollection);
            $tables[] = $built['table'];
            foreach ($built['children'] as $child) {
                $tables[] = $child;
            }
        }
        foreach ($tables as $table) {
            $this->realTableNames[$table['name']] = true;
        }

        return [
            'schemas' => [$this->schemaName],
            'tables' => $tables,
            'views' => [],
            'matviews' => [],
            'enums' => [],
            'functions' => [],
            'triggers' => [],
            'policies' => [],
            'extensions' => [],
            'auth' => [
                'present' => $auth['users'] !== [],
                'users_count' => count($auth['users']),
                'identities_count' => array_sum($auth['providers']),
                'providers' => array_keys($auth['providers']),
                'hash_strategy' => $auth['migration']['hash_strategy'],
            ],
            'storage' => [
                'present' => $storage['present'],
                'buckets' => $storage['present'] ? [['name' => $storage['bucket'], 'files' => $storage['object_count'], 'bytes' => $storage['total_bytes'], 'kind' => 'firebase-storage']] : [],
                'object_counts' => $storage['present'] ? [$storage['bucket'] => ['objects' => $storage['object_count'], 'bytes' => $storage['total_bytes']]] : [],
            ],
            'realtime' => ['present' => false, 'publication_tables' => []],
            'cron' => [],
            'edge_functions' => array_map(fn ($f) => ['name' => $f['short_name'], 'class' => $f['class']], $functions['functions']),
            'client_dependencies' => (array) ($this->source->connection['manifest']['client_dependencies'] ?? []),
            'firebase' => $firebaseMeta,
        ];
    }

    /** Sample one collection's documents (bounded, 29C). */
    protected function sampleCollection(string $collectionId, int $sampleSize): array
    {
        $documents = [];
        $this->client()->streamCollection($collectionId, function (array $batch) use (&$documents, $sampleSize) {
            foreach ($batch as $document) {
                $documents[] = $document;
                if (count($documents) >= $sampleSize) {
                    return true; // stop streaming — bounded sample
                }
            }

            return false;
        }, min($sampleSize, 100));

        return [$documents];
    }

    /** Count + id/value domain extraction over sampled documents (29C/29D). */
    protected function domainsAndCount(array $documents, string $collectionPath): array
    {
        $idDomain = [];
        $valueDomain = [];
        foreach ($documents as $document) {
            $id = $this->documentId((string) ($document['name'] ?? ''));
            if ($id !== '') {
                $idDomain[$id] = true;
            }
            foreach ((array) ($document['fields'] ?? []) as $key => $value) {
                $type = FirebaseTypeMapper::observedType($value);
                if ($type === 'reference') {
                    $valueDomain[(string) $key][] = (string) $value['referenceValue'];
                } elseif (in_array($type, ['string', 'integer'], true)) {
                    $valueDomain[(string) $key][] = (string) FirebaseTypeMapper::convertValue($value);
                }
            }
        }
        $count = $this->countCollection($collectionPath, $documents);

        return [$count, $idDomain, $valueDomain];
    }

    /** Aggregation count (fallback: enumeration) for one collection path. */
    protected function countCollection(string $collectionPath, array $sampled = []): int
    {
        $count = $this->client()->countCollection($collectionPath);
        if ($count !== null) {
            return $count;
        }
        $total = 0;
        try {
            $this->client()->streamCollection($collectionPath, function (array $batch) use (&$total) {
                $total += count($batch);
            }, 1000);
        } catch (FirebaseTransportException) {
            return count($sampled); // honest sampled estimate when enumeration refuses
        }

        return $total;
    }

    /** Fetch documents of a subcollection under each sampled parent document. */
    protected function fetchSubcollectionDocuments(array $parentDocPaths, string $subId, int $sampleSize): array
    {
        $documents = [];
        foreach ($parentDocPaths as $docPath) {
            try {
                $this->client()->streamCollection($docPath.'/'.$subId, function (array $batch) use (&$documents, $sampleSize) {
                    foreach ($batch as $document) {
                        $documents[] = $document;
                        if (count($documents) >= $sampleSize) {
                            return true;
                        }
                    }

                    return false;
                }, min($sampleSize, 100));
            } catch (FirebaseTransportException) {
                // parent may no longer exist — honest absence
            }
        }

        return $documents;
    }

    /** Document id (final path segment) from a full REST document name. */
    protected function documentId(string $fullName): string
    {
        if ($fullName === '') {
            return '';
        }
        $path = (string) (parse_url($fullName, PHP_URL_PATH) ?: $fullName);
        $segments = explode('/', rtrim($path, '/'));

        return (string) end($segments);
    }

    /** Relative path (after .../documents/) of a REST document name. */
    protected function documentRelativePath(string $fullName): string
    {
        return explode('/documents/', $fullName, 2)[1] ?? '';
    }

    /** Parent document id for a subcollection document ('orders/o001/timeline/ev1' → 'o001'). */
    protected function parentDocumentId(string $fullName): string
    {
        $path = $this->documentRelativePath($fullName);
        $segments = explode('/', trim($path, '/'));
        // Odd segment count (collection/doc alternating): doc = last, its
        // parent document id sits two positions before it.
        if (count($segments) >= 3) {
            return (string) $segments[count($segments) - 3];
        }

        return '';
    }

    // ── 29F/29G/29H surface calls ────────────────────────────────────────

    protected function analyzeAuth(): array
    {
        $users = [];
        try {
            $pageToken = null;
            do {
                $page = $this->client()->authBatchGet(1000, $pageToken);
                foreach ($page['users'] as $user) {
                    $users[] = $user;
                    if (count($users) >= 10000) {
                        break 2; // bounded inventory — beyond this stays partial by design
                    }
                }
            } while ($pageToken !== '');
        } catch (FirebaseTransportException) {
            return (new FirebaseAuthAnalyzer)->analyze([]); // honest empty inventory
        }

        return (new FirebaseAuthAnalyzer)->analyze($users);
    }

    protected function analyzeStorage(): array
    {
        try {
            $items = [];
            $prefixes = [];
            $pageToken = null;
            do {
                $page = $this->client()->storageListObjects('', 1000, $pageToken);
                foreach ($page['items'] as $item) {
                    $items[] = $item;
                    if (count($items) >= 10000) {
                        break 2; // bounded inventory (29G)
                    }
                }
                $prefixes = array_merge($prefixes, $page['prefixes']);
            } while ($pageToken !== '');

            return (new FirebaseStorageAnalyzer)->analyze($items, $this->client()->defaultBucket(), $prefixes);
        } catch (FirebaseTransportException) {
            return (new FirebaseStorageAnalyzer)->analyze([], $this->client()->defaultBucket());
        }
    }

    protected function analyzeFunctions(): array
    {
        try {
            $result = $this->client()->functionsList();

            return (new FirebaseFunctionsAnalyzer)->analyze($result['functions'], true);
        } catch (FirebaseTransportException) {
            return (new FirebaseFunctionsAnalyzer)->analyze([], false);
        }
    }

    // ── 29C.2 — mapping strategies ───────────────────────────────────────

    /** Deterministic, explainable strategy decision (mirrors 28I semantics). */
    public function decideStrategy(array $inference): array
    {
        $fields = array_filter($inference['fields'], fn ($f, $path) => ! str_contains((string) $path, '[]'), ARRAY_FILTER_USE_BOTH);
        $fieldCount = count($fields);
        $varianceFields = count(array_filter($fields, fn ($f) => $f['variance']));
        $varianceRatio = $fieldCount > 0 ? $varianceFields / $fieldCount : 0;
        $objectArrayFields = count(array_filter($inference['fields'], fn ($f) => str_ends_with($f['path'], '[]') === false && $f['array_shape'] !== null && in_array($f['array_shape'], ['map', 'mixed'], true)));
        $overflowFields = count(array_filter($fields, fn ($f) => $f['overflow']));

        if ($fieldCount > 60 || $varianceRatio > 0.5 || $overflowFields > 0) {
            $reason = $overflowFields > 0
                ? 'nesting depth exceeds safe inference depth (29C)'
                : ($varianceRatio > 0.5 ? sprintf('schema variance on %.0f%% of fields (29C)', $varianceRatio * 100) : sprintf('%d inferred fields (29C)', $fieldCount));

            return ['strategy' => 'JSONB_DOCUMENT', 'reason' => $reason];
        }
        if ($objectArrayFields === 0) {
            return ['strategy' => 'RELATIONAL_TABLE', 'reason' => 'stable shape across the sample (29C)'];
        }

        return ['strategy' => 'HYBRID', 'reason' => sprintf('%d array-of-map field(s): common fields as columns, variable payload as JSONB, arrays split to child tables (29C)', $objectArrayFields)];
    }

    // ── table construction ───────────────────────────────────────────────

    /** Build one normalized table (columns per strategy + derived child tables). */
    protected function buildTable(string $schema, string $name, array $inference, array $strategy, array $relationships, int $documentCount, bool $isSub, ?string $parentCollection): array
    {
        $taken = ['_id' => '_id'];
        $columns = [['name' => '_id', 'type' => 'text', 'nullable' => false, 'default' => null]];
        $fieldMap = ['_id' => '_id'];
        $childTables = [];
        $strategyName = $strategy['strategy'];
        $flatFields = array_filter($inference['fields'], fn ($f) => $f['path'] !== '_id' && ! str_contains($f['path'], '[]'));

        if ($isSub) {
            $columns[] = ['name' => 'parent_id', 'type' => 'text', 'nullable' => false, 'default' => null];
            $fieldMap['parent_id'] = '(parent document id)';
        }

        if ($strategyName === 'JSONB_DOCUMENT') {
            $columns[] = ['name' => 'document', 'type' => 'jsonb', 'nullable' => false, 'default' => null];
            $fieldMap['document'] = '(document)';
        } else {
            foreach ($flatFields as $field) {
                if ($field['array_shape'] !== null && in_array($field['array_shape'], ['map', 'mixed'], true)) {
                    continue; // handled as child table below
                }
                $column = NameSanitizer::columnFor($field['path'], $taken);
                $columns[] = [
                    'name' => $column,
                    'type' => $field['array_shape'] !== null ? 'jsonb' : $field['normalized_type'],
                    'nullable' => $field['nullable'],
                    'default' => null,
                ];
                $fieldMap[$column] = $field['path'];
            }
            if ($strategyName === 'HYBRID') {
                $columns[] = ['name' => 'document', 'type' => 'jsonb', 'nullable' => false, 'default' => null];
                $fieldMap['document'] = '(document)';
            }
            foreach ($inference['fields'] as $parentField => $arrayField) {
                if (str_contains((string) $parentField, '[]') || ! in_array($arrayField['array_shape'], ['map', 'mixed'], true)) {
                    continue;
                }
                $childName = $name.'__'.NameSanitizer::baseName((string) $parentField);
                if (isset($this->realTableNames[$childName]) || isset($inference[$childName])) {
                    continue; // name collision — the array stays in the JSONB payload
                }
                $childTables[] = $this->buildChildTable($schema, $name, $childName, (string) $parentField, $inference);
            }
        }

        // Foreign keys — ONLY EXPLICIT candidates (29D "strong mappings only").
        $foreignKeys = [];
        foreach ($relationships as $candidate) {
            if (! $candidate['auto_fk'] || $candidate['collection'] !== $name) {
                continue;
            }
            if (str_contains($candidate['path'], '[]')) {
                continue; // array-element references materialize via child tables
            }
            $column = array_search($candidate['path'], $fieldMap, true);
            if ($column === false) {
                continue; // reference field not represented as a column — advisory only
            }
            $foreignKeys[] = [
                'column' => $column,
                'references_schema' => $schema,
                'references_table' => NameSanitizer::tableName((string) $candidate['references']),
                'references_column' => '_id',
                'confidence' => $candidate['confidence'],
            ];
        }
        if ($isSub && $parentCollection !== null) {
            $foreignKeys[] = [
                'column' => 'parent_id',
                'references_schema' => $schema,
                'references_table' => NameSanitizer::tableName($parentCollection),
                'references_column' => '_id',
                'confidence' => 'EXPLICIT',
            ];
        }

        return [
            'table' => [
                'schema' => $schema,
                'name' => $name,
                'columns' => $columns,
                'primary_key' => ['_id'],
                'foreign_keys' => $foreignKeys,
                'indexes' => [],
                'rls_enabled' => false,
                'row_estimate' => $documentCount,
                'migration_strategy' => $strategyName,
                'strategy_reason' => $strategy['reason'],
                'sanitized_field_map' => $fieldMap,
                'is_subcollection' => $isSub,
                'child_tables' => array_map(fn ($t) => $t['name'], $childTables),
            ],
            'children' => $childTables,
        ];
    }

    /** Derived child table for an array-of-maps field (29C). */
    protected function buildChildTable(string $schema, string $parent, string $childName, string $parentField, array $inference): array
    {
        $taken = ['parent_id' => $parent.'._id', '__idx' => '(array index)'];
        $columns = [
            ['name' => 'parent_id', 'type' => 'text', 'nullable' => false, 'default' => null],
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
            $column = NameSanitizer::columnFor($elementPath, $taken);
            $columns[] = [
                'name' => $column,
                'type' => $field['normalized_type'],
                // Array ELEMENTS are heterogeneous by nature: document-level
                // sampling cannot claim element-level NOT NULL (29C).
                'nullable' => true,
                'default' => null,
            ];
            $fieldMap[$column] = $elementPath;
        }

        return [
            'schema' => $schema,
            'name' => $childName,
            'columns' => $columns,
            'primary_key' => [],
            'foreign_keys' => [[
                'column' => 'parent_id',
                'references_schema' => $schema,
                'references_table' => $parent,
                'references_column' => '_id',
                'confidence' => 'EXPLICIT',
            ]],
            'indexes' => [],
            'rls_enabled' => false,
            'row_estimate' => 0,
            'migration_strategy' => 'ARRAY_CHILD_TABLE',
            'strategy_reason' => sprintf('derived from %s.%s[] (29C) — ordering preserved in __idx, parent linkage in parent_id', $parent, $parentField),
            'sanitized_field_map' => $fieldMap,
            'source_array_field' => $parentField,
            'child_tables' => [],
        ];
    }

    // ── 29E — extraction ─────────────────────────────────────────────────

    public function countRows(string $schema, string $table): int
    {
        $this->connect();
        $analysis = $this->analysis();
        $tableDef = collect($analysis['tables'])->firstWhere('name', $table);
        if ($tableDef === null) {
            throw new \InvalidArgumentException("Unknown collection '{$table}' for extraction");
        }
        if (($tableDef['migration_strategy'] ?? '') === 'ARRAY_CHILD_TABLE') {
            $total = 0;
            $this->streamRows($schema, $table, [], function () use (&$total) {
                $total++;
            }, 1000);

            return $total;
        }
        $total = 0;
        foreach ($this->tablePaths[$table] ?? [$table] as $path) {
            $total += $this->countCollection($path);
        }

        return $total;
    }

    /** Batches streamed by the most recent extraction (29E progress). */
    public int $lastExtractionBatches = 0;

    /**
     * Stream one collection (or derived table) in bounded batches, projected
     * to the strategy columns. Cursor-ordered by __name__ for deterministic
     * runs and safe resumption (29E): re-running an item replays the same
     * order, so partial failures resume idempotently.
     */
    public function streamRows(string $schema, string $table, array $columns, callable $callback, int $batchSize = 500): int
    {
        $this->connect();
        $analysis = $this->analysis();
        $this->lastExtractionBatches = 0;
        $batchSize = max(1, $batchSize);

        $tableDef = collect($analysis['tables'])->firstWhere('name', $table);
        if ($tableDef === null) {
            throw new \InvalidArgumentException("Unknown collection '{$table}' for extraction");
        }

        // Array-of-maps child table → explode elements of the parent's docs.
        if (($tableDef['migration_strategy'] ?? '') === 'ARRAY_CHILD_TABLE') {
            $parent = (string) $tableDef['foreign_keys'][0]['references_table'];

            return $this->streamChildTable($analysis, $parent, $tableDef, $columns, $callback, $batchSize);
        }

        $fieldMap = $tableDef['sanitized_field_map'];
        $written = 0;
        $projector = $this->rowProjector($tableDef, $fieldMap);
        $hasParentLink = in_array('(parent document id)', $fieldMap, true);

        foreach ($this->tablePaths[$table] ?? [$table] as $path) {
            $this->client()->streamCollection($path, function (array $batch) use (&$written, $projector, $callback, $columns, $hasParentLink) {
                $rows = [];
                foreach ($batch as $document) {
                    $row = $projector($document);
                    $row['_id'] = $this->documentId((string) ($document['name'] ?? ''));
                    if ($hasParentLink) {
                        $row['parent_id'] = $this->parentDocumentId((string) ($document['name'] ?? ''));
                    }
                    $rows[] = $row;
                    $written++;
                }
                $this->lastExtractionBatches++;
                foreach ($rows as $row) {
                    $callback($columns === [] ? $row : array_intersect_key($row, array_flip($columns)));
                }
            }, $batchSize);
        }

        return $written;
    }

    /** Build the per-document row projector for a strategy. */
    protected function rowProjector(array $tableDef, array $fieldMap): \Closure
    {
        return function (array $document) use ($tableDef, $fieldMap): array {
            $fields = (array) ($document['fields'] ?? []);
            $row = [];
            foreach ($fieldMap as $column => $path) {
                if ($path === '_id' || $path === '(parent document id)') {
                    // Placeholder keeps the column position; streamRows fills
                    // it from the document name after projection.
                    $row[$column] = null;
                    continue;
                }
                if ($path === '(document)') {
                    $row[$column] = FirebaseTypeMapper::canonicalJson(['mapValue' => ['fields' => $fields]]);
                    continue;
                }
                $row[$column] = $this->valueAtPath($fields, $path);
            }

            return $row;
        };
    }

    /** Resolve + convert a (possibly nested) source path against document fields. */
    protected function valueAtPath(array $fields, string $path): mixed
    {
        $segments = explode('.', $path);
        $node = $fields;
        foreach ($segments as $i => $segment) {
            if (! is_array($node) || ! array_key_exists($segment, $node)) {
                return null;
            }
            $node = $node[$segment];
            $isLast = $i === count($segments) - 1;
            if (! $isLast && $this->isWrapper($node) && array_key_first($node) === 'mapValue') {
                $node = FirebaseTypeMapper::decodeWrapper($node);
            }
        }
        if ($this->isWrapper($node) && array_key_first($node) === 'arrayValue') {
            return FirebaseTypeMapper::canonicalJson($node);
        }
        // DocumentReference columns hold the referenced DOCUMENT ID, not the
        // full path — the value must satisfy the proposed FK (29D); the full
        // path stays available in the canonical JSON payload.
        if ($this->isWrapper($node) && array_key_first($node) === 'referenceValue') {
            return FirebaseTypeMapper::referenceId((string) $node['referenceValue']);
        }

        return FirebaseTypeMapper::convertValue($node);
    }

    /** Explode an array-of-maps into child rows (29C). */
    protected function streamChildTable(array $analysis, string $parent, array $childDef, array $columns, callable $callback, int $batchSize): int
    {
        $parentField = (string) ($childDef['source_array_field'] ?? '');
        $written = 0;
        $elementColumns = array_filter(
            $childDef['sanitized_field_map'],
            fn ($path) => ! str_starts_with((string) $path, $parent.'._id') && $path !== '(array index)'
        );

        foreach ($this->tablePaths[$parent] ?? [$parent] as $path) {
            $this->client()->streamCollection($path, function (array $batch) use (&$written, $parentField, $elementColumns, $columns, $callback) {
                $rows = [];
                foreach ($batch as $document) {
                    $parentId = $this->documentId((string) ($document['name'] ?? ''));
                    $arrayNode = (array) ($document['fields'][$parentField] ?? []);
                    if (! $this->isWrapper($arrayNode) || array_key_first($arrayNode) !== 'arrayValue') {
                        continue;
                    }
                    foreach (FirebaseTypeMapper::decodeWrapper($arrayNode) as $index => $element) {
                        if (! is_array($element)) {
                            continue; // scalar elements have no map structure to project
                        }
                        // decodeWrapper returns PLAIN maps for mapValue elements —
                        // rebuild fields-shaped wrappers so valueAtPath sees the
                        // same shape the projector uses.
                        $elementFields = [];
                        foreach ($element as $k => $v) {
                            $elementFields[$k] = $this->rewrap($v);
                        }
                        $row = ['parent_id' => $parentId, '__idx' => $index];
                        foreach ($elementColumns as $column => $elementPath) {
                            $row[$column] = $this->valueAtPath($elementFields, (string) $elementPath);
                        }
                        $rows[] = $columns === [] ? $row : array_intersect_key($row, array_flip($columns));
                        $written++;
                    }
                }
                $this->lastExtractionBatches++;
                foreach ($rows as $row) {
                    $callback($row);
                }
            }, $batchSize);
        }

        return $written;
    }

    /** Known Firestore REST value-wrapper keys. */
    protected const WRAPPER_KEYS = [
        'nullValue', 'booleanValue', 'integerValue', 'doubleValue', 'stringValue',
        'timestampValue', 'referenceValue', 'geoPointValue', 'bytesValue', 'mapValue', 'arrayValue',
    ];

    protected function isWrapper(mixed $value): bool
    {
        return is_array($value) && count($value) === 1 && in_array(array_key_first($value), self::WRAPPER_KEYS, true);
    }

    /** Re-encode a decoded element value into a single-value wrapper. */
    protected function rewrap(mixed $value): mixed
    {
        if ($this->isWrapper($value)) {
            return $value;
        }
        if (is_array($value) && array_is_list($value)) {
            return ['arrayValue' => ['values' => array_map(fn ($v) => $this->rewrap($v), $value)]];
        }
        if (is_array($value)) {
            return ['mapValue' => ['fields' => array_map(fn ($v) => $this->rewrap($v), $value)]];
        }

        return $value; // plain scalars pass through convertValue unchanged
    }

    // ── 29F — auth streaming ─────────────────────────────────────────────

    /**
     * Stream auth users in the engine's normalized auth-row shape.
     *
     * `encrypted_password` is ALWAYS null: Firebase never exposes password
     * hashes through account listing, and faking portability is forbidden
     * (29F) — password users surface as NEEDS_REVIEW in the analysis
     * artifacts and must reset credentials on the target.
     */
    public function streamAuthUsers(callable $callback, int $batchSize = 500): int
    {
        $this->connect();
        $analysis = $this->analysis();
        $count = 0;
        foreach ($analysis['firebase']['auth']['users'] ?? [] as $user) {
            $callback([
                'id' => (string) ($user['uid'] ?? ''),
                'email' => $user['email'] ?? null,
                'encrypted_password' => null, // never available — never faked (29F)
                'created_at' => isset($user['created_at_ms']) && $user['created_at_ms']
                    ? gmdate('c', (int) ($user['created_at_ms'] / 1000))
                    : null,
            ]);
            $count++;
        }

        return $count;
    }

    // ── fingerprint (29Q semantics) ──────────────────────────────────────

    public function fingerprint(): string
    {
        return SchemaFingerprint::compute($this->inventory());
    }
}
