# Connector architecture

How the Phase 27 Connector SDK separates a provider-agnostic platform core
from self-contained connector packages, and where each seam lives.

## Two-layer design

| Layer | Location | Knows about | Never knows about |
|-------|----------|-------------|-------------------|
| Platform core | `apps/owner-console/app/Services/ControlPlane/Connectors/` | the `Connector` contract, capability vocabulary, manifest validation, secret scoping, lifecycle | any provider name, API shape, or credential layout |
| Connector packages | `apps/owner-console/app/Connectors/<Pascal>/` | one provider (Supabase, JSON datasets, future MongoDB) | other connectors, the Migration Center internals |

The core **never branches on provider names**. `ConnectorRegistry` (27D) is
the single boundary where connectors are declared and resolved: onboarding,
the Migration Center, the AI Copilot and the CLI all go through the registry.
Provider branching that existed before Phase 27 was removed in favor of
registry resolution (27D.2).

## The registry

`App\Services\ControlPlane\Connectors\ConnectorRegistry` is a container
singleton (`ConnectorRegistry::instance()` — no static caching, so the
container stays the source of truth across test rebuilds/Octane):

| Operation | Behavior |
|-----------|----------|
| `register($connector, ?bool $enabled)` | refuses duplicate keys with `ConnectorKeyConflict` (27D.3); default enablement follows trust — only `first_party` is enabled (27K.1) |
| `connector($key)` | returns the connector or throws `ConnectorNotSupported` (unknown) / `ConnectorDisabled` (registered but off) — disabled connectors are **unreachable**, not hidden |
| `connectorForSource($source)` | resolves by `MigrationSource::effectiveConnectorKey()` |
| `resolve($source)` (static) | returns the Phase 24 `SourceAdapter` for a source — the engine seam |
| `setEnabled / isEnabled` | runtime enable/disable (27D.1) |
| `reject($key, $reason)` | records rejected packages for observability (27J.3/27R) |
| `descriptors()` | presentation shape for generic UI/CLI (key, name, version, trust, capabilities, enabled, import_flow, category, docs_url) |

## The engine seam

The Phase 24 `SourceAdapter` contract
(`app/Services/ControlPlane/Migration/Contracts/SourceAdapter.php`, 24J.1) is
the extract-level seam every connector plugs into:

```php
interface SourceAdapter
{
    public function __construct(MigrationSource $source);
    public static function id(): string;
    public function connect(): void;              // must throw on failure; must enforce read-only
    public function inventory(): array;           // adapter-normalized capability inventory (24A.3)
    public function fingerprint(): string;        // deterministic schema fingerprint
    public function countRows(string $schema, string $table): int;
    public function streamRows(...$callback, int $batchSize = 500): int;  // PK-ordered, UTF-8 safe (24J.4)
    public function streamAuthUsers(callable $callback, int $batchSize = 500): int;
    public function close(): void;
}
```

`MigrationCenterService::makeAdapter()` resolves through the registry for
every real connector:

```php
public function makeAdapter(MigrationSource $source): object
{
    if (! in_array($source->effectiveConnectorKey(), array_keys($this->adapters), true)) {
        return ConnectorRegistry::instance()->resolveSource($source);
    }
    // ... legacy 'sqlite' engine fixture only
}
```

`MigrationRunManager::makeSource()` (line ~401) delegates to the same method —
there is exactly one adapter resolution path. The only hardwired adapter is
`sqlite` (`SqliteSourceAdapter`), an engine fixture for rehearsal targets, not
a provider plugin.

Connectors implement `SourceConnector::sourceAdapter()` and usually extend
`Support\BaseSourceAdapter`, which supplies generic fingerprinting, safe
identifier quoting (`qi()` — validates then double-quotes identifiers) and the
no-auth `streamAuthUsers` default. Provider adapters stay independent of each
other instead of subclassing a specific provider.

## Data flow through the Migration Center

```
Connector package
  └─ SourceConnector (manifest, credential schema, capabilities, lifecycle ops)
       └─ SourceAdapter::connect() → inventory()            (read-only, 24J.1)
            └─ NORMALIZED ANALYSIS INVENTORY (27B.2 shape)  ← the contract between layers
                 schemas / tables (columns, PK, FK, indexes, rls_enabled) /
                 views / matviews / enums / functions / triggers / policies /
                 extensions / auth / storage / realtime / cron /
                 edge_functions / client_dependencies
                 └─ MigrationCenterService::analyze() → versioned MigrationAnalysis
                      └─ compatibility engine (CompatibilityClassifier + RiskDetector, 24A.5/24A.6)
                           └─ MigrationPlan (FK-dependency ordered stages)
                                └─ MigrationRunManager::execute() → target (rehearsal/dry_run/real)
                                     └─ validation suite + connector-provided artifacts (27B.4)
```

Every analysis is an immutable new row stamped with connector key/version and
`analysis_version` (see CONNECTOR_VERSIONING.md). The normalized 27B.2 shape
is what makes the pipeline provider-agnostic: `ExampleJsonSourceAdapter`
produces the same sections as the Supabase adapter, so analyze → plan → run →
validate works with **no Supabase code participating**
(`tests/Feature/Phase27/ExampleConnectorEndToEndTest.php`).

## Package discovery

`ConnectorDiscovery` scans `config('connectors.paths')`
(default `[app_path('Connectors')]`) for `*/connector.json` packages, strictly
validates each manifest, instantiates the entrypoint, verifies the entrypoint
reports the same key, and registers with trust-gated enablement. Malformed or
incompatible packages are rejected all-or-nothing and recorded on the registry
(see CONNECTOR_MANIFEST.md). There is no remote installation (27J.4).

## Contracts index

| Contract | Purpose | Phase |
|----------|---------|-------|
| `Contracts\Connector` | base: manifest, definition, credential schema, capabilities, capabilityStatus, testConnection, health | 27B |
| `Contracts\SourceConnector` | full source lifecycle: discoverProjects, createSourceProfile, sourceAdapter, analyze, extract, validateSource, fingerprint | 27B.1 |
| `Contracts\DiscoverableSourceConnector` | declares `discoveryRequires()` | 27B |
| `Contracts\AccountDiscoveryConnector` | account import flow: connectAccount, testAccount, discoverAccountProjects | 27G.2 |
| `Contracts\AnalyzableSourceConnector` | declares `analysisRequires()` | 27B.2 |
| `Contracts\ExtractableSourceConnector` | declares `extractionRequires()`, `supportsResume()` | 27B.3 |
| `Contracts\ValidatableSourceConnector` | declares `providedValidators()` | 27B.4 |
| `Contracts\ClientScannerProvider` | contributes client repo scanning patterns | 27B |

Capability-refinement interfaces are separate so metadata-without-extraction
remains a legitimate connector shape.

## Honest limitations

- In-process PHP connectors are trusted code — the SDK scopes credentials and
  files, but is **not** a security sandbox (27K, documented honestly in
  CONNECTOR_SECURITY.md).
- `supportsResume()` is `false` for both shipped connectors: extraction uses
  offset batching, not persisted resume tokens.
- Only local first-party package discovery exists; the SQLite fixture adapter
  is the only non-registry resolution path, kept deliberately.
