# Connector development

Practical guide for building and shipping a connector package: layout,
discovery, lifecycle operations, UI integration, client scanning, the CLI and
trust promotion.

## Package layout

```
app/Connectors/<Pascal>/
├── connector.json          # manifest — the only discovery surface
├── <Name>Connector.php     # entrypoint: implements SourceConnector (+ refinements)
├── <Name>SourceAdapter.php # optional: the Phase 24 SourceAdapter (extends BaseSourceAdapter)
├── <Name>ClientScanner.php # optional: ClientScannerProvider patterns
├── ...                     # package-private services
├── README.md               # package notes (scaffold generates one)
└── datasets/               # package data (scaffold gitignores this)
```

Discovery paths come from `config('connectors.php') → 'paths'`
(default `[app_path('Connectors')]`). Every direct subdirectory containing
`connector.json` is a package.

## Discovery flow (27J.1)

1. `ConnectorDiscovery::discover()` globs `*/connector.json` under each path.
2. `ConnectorManifest::parseFile()` validates strictly (all-or-nothing —
   see CONNECTOR_MANIFEST.md).
3. The entrypoint class is resolved from the container and must report the
   SAME key as the manifest.
4. `ConnectorRegistry::register()` — enabled only when
   `trust === 'first_party'` AND `first_party` is in
   `config('connectors.enabled_trust_levels')`.
5. Failures are recorded: `registry.reject(key, reason)` + audit event
   `CONNECTOR_PACKAGE_REJECTED`; `connector:list` prints them.

## What to implement

The base `Connector` contract (27B) plus `SourceConnector` (27B.1):

| Method | Must do |
|--------|---------|
| `manifest()` | parse+cache the package `connector.json` |
| `definition()` | `ConnectorDefinition::fromManifest()` + your credential/configuration fields |
| `credentialSchema()` | secret + configuration fields merged (drives UI, secret resolution, capability matrix) |
| `capabilities()` / `capabilityStatus()` | declared keys / honest per-instance status from resolvable field keys |
| `testConnection($credentials)` | read-only probe; classify with `ConnectorTestResult` (`PASS`, `INVALID_CREDENTIAL`, `INSUFFICIENT_SCOPE`, `NOT_FOUND`, `NETWORK_ERROR`, `RATE_LIMITED`, `PROVIDER_ERROR`, `INVALID_CONFIGURATION`, `UNKNOWN_ERROR`); never leak secret values |
| `health($source)` | `ConnectorHealth`: `CONNECTED`, `PARTIAL`, `DISCONNECTED`, `ERROR`, `DISABLED` |
| `discoverProjects()` | account-level discovery (empty list when not applicable) |
| `createSourceProfile()` | create the read-only `MigrationSource`; store secret REFS only, never plaintext |
| `sourceAdapter()` | the Phase 24 `SourceAdapter` — `connect()` (enforce read-only), `inventory()` (normalized 27B.2 shape), `countRows`, `streamRows` (batched, callback-driven), `close()` |
| `analyze()` / `extract()` / `validateSource()` / `fingerprint()` | default to adapter delegation; `validateSource` returns connector-provided artifacts (row counts, checksums, integrity checks) |

Optional refinements: `DiscoverableSourceConnector` (`discoveryRequires()`),
`AnalyzableSourceConnector` (`analysisRequires()`), `ExtractableSourceConnector`
(`extractionRequires()`, `supportsResume()`), `ValidatableSourceConnector`
(`providedValidators()`), `AccountDiscoveryConnector` (account import flow),
`ClientScannerProvider` (repo scanning).

Rules that the contract battery enforces: every operation strictly read-only
against the source (27H.3); normalized 27B.2 inventory shapes; honest
classification (never fake support); keep secrets server-side.

## Enable / disable and instance lifecycle

- Type-level: `ConnectorRegistry::instance()->setEnabled($key, bool)`.
- Instance-level (`ConnectorLifecycleService`, 27F):
  - `disable($source)` — status `disabled`, configuration intact, audit
    `CONNECTOR_INSTANCE_DISABLED`.
  - `removeInstance($source)` — clears `secret_refs` + `connection`, deletes
    vault secrets no other source references, PRESERVES historical
    analyses/artifacts (audit evidence, 27F.1), audit
    `CONNECTOR_INSTANCE_REMOVED`.

## UI integration (27G/27Q)

The generic wizard and Migration Center render any connector from its
definition — no provider imports. The manifest's `import_flow` decides the
wizard path:

| Flow | Wizard behavior |
|------|-----------------|
| `supabase-account` | account connect (secret) → `discoverAccountProjects` → select → read-only source profile |
| `credentials-form` | generic credential/configuration form built from the field schema → test → create profile |
| `none` | connector appears for introspection only; no import step |

Credential fields with `capability` keys feed the "Connector capabilities"
matrix on the Migration Center page (rendered from
`ConnectorCapabilityProbe::matrix()`).

## Client scanner contribution

Implement `ClientScannerProvider` (27B) to contribute repo-scanning patterns:
`scannerLabel()`, `patternsFor('dart'|'javascript'|'typescript'|'php'|'common')`
(lists of `['category', 'regex', 'target']`), `secretMarkers()` (names only),
`configDirNames()`, `hardcodedUrlRiskCode()`. The generic scanner merges all
providers and stores evidence hashed only. See
`app/Connectors/Supabase/SupabaseClientScanner.php` for a complete example.

## CLI reference (27M)

| Command | Purpose |
|---------|---------|
| `connector:list {--all}` | table of key, name, version, trust, capability count, enabled, import flow; `--all` includes disabled/unverified; prints rejected packages |
| `connector:inspect {key}` | definition, credential/configuration schema (never values), capabilities, permissions, platform compatibility vs the running platform |
| `connector:test {key?}` | run the 27L contract battery (see CONNECTOR_TESTING.md) |
| `connector:make {name}` | scaffold a package (see below) |

## Scaffold and trust promotion

`php artisan connector:make <name>` generates `connector.json` +
`<Name>Connector.php` (with TODO-marked adapter skeleton) + `README.md` +
`.gitignore`. Key facts:

- Key is derived via kebab-casing and validated against the manifest regex;
  invalid names exit non-zero.
- The scaffold is `trust: unverified` and `import_flow: credentials-form` —
  it **registers but stays disabled and uninvokable** until promoted
  (`ConnectorCliUiTest::test_connector_make_scaffolds_disabled_unverified_package`).
- Promotion is an operator config action:
  `CONNECTOR_ENABLED_TRUST_LEVELS` / `config('connectors.enabled_trust_levels')`
  — or ship the finished connector as `first_party` in-repo after review.
  No arbitrary code is ever auto-registered trusted.

Workflow: scaffold → implement TODOs → `connector:test <key>` green →
`connector:inspect <key>` review → promote → use through the wizard/Migration
Center. See BUILD_YOUR_FIRST_CONNECTOR.md for the full walkthrough.
