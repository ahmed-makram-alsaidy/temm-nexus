# Build your first connector

A step-by-step tutorial: build a working source connector from scratch, using
the real Example JSON connector (`app/Connectors/ExampleJson/`) as the
reference at every step. Code excerpts are from the actual files.

## What you are building

A connector that treats a directory of JSON files (`users.json`,
`orders.json`, …) as a read-only data source, feeds it through the standard
Migration Center pipeline (analyze → plan → migrate → validate) and passes the
contract battery — with zero Supabase code.

## Step 0 — scaffold

```sh
php artisan connector:make acme-json
# app/Connectors/AcmeJson/connector.json + AcmeJsonConnector.php + README.md
```

The scaffold is `trust: unverified` — it registers **disabled** and stays
uninvokable until you promote it (Step 8). `connector:make` rejects invalid
names (kebab-case, max 32 chars).

## Step 1 — define the manifest

Edit `connector.json`: key, name, description, capabilities (from the 27C
vocabulary), permissions, and entrypoint. The Example JSON manifest declares
exactly what it needs:

```json
"capabilities": [
    "database_metadata",
    "data_extraction",
    "read_only_enforcement",
    "source_fingerprint"
],
"permissions": ["filesystem.dataset.read"],
"trust": "first_party",
"import_flow": "credentials-form"
```

`import_flow: credentials-form` makes the generic wizard render a form from
your credential schema (no account-connect step). See CONNECTOR_MANIFEST.md
for every field and guard.

## Step 2 — declare credentials

In the connector class, build a `ConnectorDefinition::fromManifest()` with
your fields. The Example connector has a single non-secret configuration
field:

```php
$datasetField = new ConnectorCredentialField(
    key: 'dataset_path',
    label: 'Dataset directory',
    type: 'file',
    secret: false,
    required: true,
    scope: ConnectorCredentialField::SCOPE_CONFIGURATION,
    help: 'Absolute path to a directory of JSON files — one collection per file ...',
    capability: ConnectorCapability::DATABASE_METADATA,
);

return $this->definition = ConnectorDefinition::fromManifest($this->manifest(), [], [$datasetField]);
```

Scope rules: `account` = encrypted account connection, `source` = project
vault via `secret_refs`, `configuration` = `MigrationSource.connection` JSON.
A dataset path is not a secret, so `configuration` is right. If your connector
needs a password, use `scope: SCOPE_SOURCE, secret: true` and never declare a
default for it (the contract test fails otherwise).

## Step 3 — implement testConnection

Classify honestly using the `ConnectorTestResult` vocabulary:

```php
public function testConnection(ConnectorCredentials $credentials): ConnectorTestResult
{
    $path = (string) $credentials->get('dataset_path', '');
    if ($path === '') {
        return ConnectorTestResult::make(ConnectorTestResult::INVALID_CONFIGURATION, 'dataset_path is required.');
    }
    try {
        $files = ProjectScopedFileReader::datasetFiles($path, 'json',
            (int) config('connectors.limits.max_dataset_files', 50));
    } catch (\Throwable $e) {
        $message = mb_substr($e->getMessage(), 0, 200);
        return ConnectorTestResult::make(
            str_contains($message, 'not exist') ? ConnectorTestResult::NOT_FOUND : ConnectorTestResult::INVALID_CONFIGURATION,
            $message);
    }
    if ($files === []) {
        return ConnectorTestResult::make(ConnectorTestResult::INVALID_CONFIGURATION, 'No .json dataset files found in the directory.');
    }

    return ConnectorTestResult::pass(count($files).' JSON dataset file(s) found', ['files' => count($files)]);
}
```

Note the guards: file access goes through `ProjectScopedFileReader`
(traversal/extension/size caps), and the failure taxonomy is from the stable
result list — never a bare exception to the UI.

## Step 4 — implement the source adapter

Extend `BaseSourceAdapter` and implement the Phase 24 contract.
`connect()` loads and validates the dataset:

```php
public function connect(): void
{
    $path = (string) ($this->source->connection['dataset_path'] ?? '');
    $files = ProjectScopedFileReader::datasetFiles($path, 'json',
        (int) config('connectors.limits.max_dataset_files', 50));
    $maxBytes = ((int) config('connectors.limits.max_dataset_file_kb', 10240)) * 1024;
    // ... parse each <collection>.json (a JSON array of objects)
    $this->collections = $collections;
}
```

`inventory()` must return the **normalized 27B.2 shape** — the same sections
every adapter produces (`schemas`, `tables`, `views`, `matviews`, `enums`,
`functions`, `triggers`, `policies`, `extensions`, `auth`, `storage`,
`realtime`, `cron`). The Example connector fills only what a dataset can
honestly have and reports the rest as empty/absent:

```php
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
```

`countRows()` is trivial; `streamRows()` is batched, callback-driven and
deterministic (records sorted by `id` when present), with column projection:

```php
foreach (array_chunk($ordered, max(1, $batchSize)) as $batch) {
    foreach ($batch as $row) {
        $callback($projected === null ? $row : array_intersect_key($row, $projected));
        $total++;
    }
}
```

If your source is a database, enforce read-only at the session level (the
Supabase adapter does `SET default_transaction_read_only = on` plus
`BEGIN TRANSACTION READ ONLY`).

## Step 5 — implement validateSource

Return connector-provided validation artifacts (27B.4). The Example connector
reports exact row counts and detects foreign-key violations:

```php
foreach ($inventory['tables'] as $table) {
    $rowCounts[$table['name']] = $adapter->countRows('public', $table['name']);
    // stream the referenced column, stream this table's FK column,
    // count values with no matching parent → $fkIssues[]
}
return [
    'kind' => 'connector_validation',
    'connector_key' => self::KEY,
    'tables' => count($inventory['tables']),
    'row_counts' => $rowCounts,
    'foreign_key_issues' => $fkIssues,
    'source_fingerprint' => $this->fingerprint($source),
];
```

## Step 6 — wire the lifecycle helpers

The generic pipeline calls these; default them to adapter delegation:

```php
public function analyze(MigrationSource $source): array
{
    return $this->sourceAdapter($source)->inventory();
}

public function extract(MigrationSource $source, string $schema, string $table, array $columns, callable $callback, int $batchSize = 500): int
{
    return $this->sourceAdapter($source)->streamRows($schema, $table, $columns, $callback, $batchSize);
}

public function fingerprint(MigrationSource $source): string
{
    return $this->sourceAdapter($source)->fingerprint();
}
```

Also implement `createSourceProfile()` (create a read-only `MigrationSource`
storing only secret REFS), `health()`, `discoverProjects()` (return `[]` when
not applicable), and the `analysisRequires()` / `extractionRequires()` /
`supportsResume()` refinement methods.

## Step 7 — test with connector:test

```sh
php artisan connector:test acme-json
php artisan connector:inspect acme-json
```

All checks must be `PASS` (or legitimate `SKIP`). Add a PHPUnit test to the
first-party loop in `tests/Feature/Phase27/ConnectorLifecycleTest.php`
(`test_contract_test_battery_passes_for_first_party_connectors`) and, for
end-to-end coverage, mirror `ExampleConnectorEndToEndTest.php`:
`createSourceProfile` → `MigrationCenterService::analyze` → `classify` →
`generatePlan` → `MigrationRunManager::start/execute` with a disposable sqlite
target → `validate`.

## Step 8 — review and promote

1. `connector:list` — confirm your connector shows the intended trust level.
2. The scaffold ships as `unverified` and stays **disabled**: promote it via
   `CONNECTOR_ENABLED_TRUST_LEVELS` / `config('connectors.enabled_trust_levels')`,
   or, for a polished in-repo connector, set `trust: first_party` after code
   review (first-party packages auto-enable).
3. Once enabled, the connector appears in onboarding (credentials-form wizard)
   and the Migration Center, and `MigrationCenterService::makeAdapter()`
   resolves it through the registry — no core changes.

## Checklist

- [ ] Manifest validates (`connector:inspect` shows Compatible: yes)
- [ ] `testConnection` classifies all failure modes; no secret in details
- [ ] `inventory()` returns the full normalized 27B.2 shape honestly
- [ ] `streamRows` batched + deterministic; source never mutated
- [ ] `validateSource` returns countable artifacts
- [ ] Contract battery green; added to the first-party loop
- [ ] Secrets via scopes (no plaintext on `MigrationSource`)
- [ ] `supportsResume` honest (offset batching is not resume)
