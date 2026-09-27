# Connector testing

The Phase 27L testing SDK: what `ConnectorContractTester` checks, how to run
it, and the PHPUnit conventions for connector feature tests.

## ConnectorContractTester

`App\Services\ControlPlane\Connectors\Testing\ConnectorContractTester` is the
reusable contract battery every first-party source connector must pass
(27L.1). The artisan `connector:test` command drives it; PHPUnit feature tests
embed it as their assertion engine. Checks are HONEST — a check that cannot be
executed for a connector reports `SKIP`, never `PASS`. Each check returns
`['status' => 'PASS'|'FAIL'|'SKIP', 'detail' => string]`.

| Check | What it verifies |
|-------|------------------|
| `manifest` | manifest parses; key/version present; reports key, version, schema version and trust |
| `definition` | definition builds; name and description non-empty |
| `capabilities` | every declared capability is inside the 27C vocabulary |
| `credential_schema` | every field has key/label; **secret fields declare no default**; field `capability` keys exist; `configuration` fields are not secret |
| `capability_status_honesty` | for every vocabulary key NOT declared, `capabilityStatus()` must NOT return `SUPPORTED` — faked support fails |
| `secret_leak` | runs `testConnection` with a canary value (`CANARY-SECRET-…`) in every field; the raw canary must never appear in output metadata or exception messages |
| `unknown_handling` | `testConnection` on empty credentials must return a classified `ConnectorTestResult`, not crash |
| `read_only_profile` | (SourceConnector only) `createSourceProfile` produces a `read_only = true` MigrationSource; `SKIP`s without a `project` fixture option |

`ConnectorContractTester::summarize($results)` collapses the map into
`['status' => 'PASS'|'FAIL', 'detail' => 'N passed, N failed, N skipped — <failing checks>']`.

## CLI usage

```sh
php artisan connector:test supabase    # one connector
php artisan connector:test             # every registered connector
```

`connector:test {key?}` (27M.3) prints one line per check:

```
Connector: example-json — PASS (8 passed, 0 failed, 0 skipped)
  [PASS] manifest                     example-json v1.0.0 schema v1 trust first_party
  [PASS] credential_schema            credential schema safe
  ...
```

Exit code is non-zero when any connector fails. Unknown keys fail cleanly.

## Expectations for first-party connectors (27L.1)

Every first-party source connector must pass the full battery with a project
fixture. This is asserted in
`tests/Feature/Phase27/ConnectorLifecycleTest.php`:

```php
foreach (['supabase', 'example-json'] as $key) {
    $connector = ConnectorRegistry::instance()->sourceConnector($key);
    $results = ConnectorContractTester::run($connector, [
        'project'        => $this->projectA,
        'configuration'  => ['dataset_path' => $this->buildJsonDataset(...)],
    ]);
    $this->assertSame('PASS', ConnectorContractTester::summarize($results)['status']);
}
```

`read_only_profile` is the only check that may SKIP (when profile creation
needs connector-specific configuration). New first-party connectors must be
added to this loop.

## PHPUnit conventions

| Convention | Value |
|------------|-------|
| Suite location | `apps/owner-console/tests/Feature/Phase27/` |
| Database | `RefreshDatabase` trait + sqlite `:memory:` (phpunit.xml: `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`) |
| Fixture | `Tests\Feature\Phase27\Concerns\BuildsPhase27Fixture` — admin user + projects A/B + `buildJsonDataset()` (12 users → 24 orders → 36 order_items) |
| HTTP isolation | `Http::fake()` for management API classification tests (e.g. 401 → `INVALID_CREDENTIAL`) |
| Package fixtures | temporary packages written under `storage_path('framework/testing/phase27/packages')` and cleaned up inline |

## The Phase 27 test files

| File | Coverage |
|------|----------|
| `ConnectorSdkTest.php` | 27A/27B/27C/27E — contracts, normalized inventory, capability honesty, credential schemas, secret minimization, manifest validation, platform gate |
| `ConnectorRegistrySecurityTest.php` | 27D/27J/27K/27T — registry behavior, discovery rejection, trust gating, SSRF, traversal, log injection, project isolation (see CONNECTOR_SECURITY.md) |
| `ConnectorLifecycleTest.php` | 27F — version stamping, disable/remove semantics, history preservation, first-party contract battery |
| `ExampleConnectorEndToEndTest.php` | 27N — schema inference, batched extraction, analyze → plan → run (sqlite rehearsal) → validate with no Supabase code |
| `ConnectorPerformanceTest.php` | 27U — 100-connector registry scale, 10 000-item normalization scale |
| `ConnectorCliUiTest.php` | 27M/27Q — CLI commands, scaffold trust gating, generic UI capability matrix, onboarding import flow |

## Writing tests for your own connector

1. Run the battery in CI exactly like the first-party loop above.
2. Assert honesty explicitly: undeclared capabilities return `NOT_SUPPORTED`.
3. Assert secret minimization: `resolveScoped($source, $definition, $connector->discoveryRequires())`
   must not contain analysis-only secrets.
4. For end-to-end coverage, mirror `ExampleConnectorEndToEndTest`:
   `createSourceProfile` → `MigrationCenterService::analyze` → `classify` →
   `generatePlan` → `MigrationRunManager::start/execute` (disposable sqlite
   target) → `validate`.
