# Connector versioning

How connector versions, platform requirements and analysis schema versions
are stamped so every analysis and artifact stays reproducible and attributable
(27I).

## The four version axes

| Axis | Where | Format / value |
|------|-------|----------------|
| Connector version | `connector.json` `version` | SemVer `X.Y.Z` (optional `-suffix`) — validated by `ConnectorManifest` |
| Platform requirement | `connector.json` `platform_requirement` | `>=X.Y.Z` gate against the running platform |
| Platform version | `config/platform.php` → `Support\Platform::version()` | e.g. `0.1.0-rc.2`; `Platform::core()` strips the prerelease |
| Analysis schema | `MigrationCenterService::ANALYSIS_VERSION` | constant `analysis@1` (engine identity: `driver_version = engine@1`) |

## Manifest version rules

- `version` must match `^\d+\.\d+\.\d+(-[0-9A-Za-z.\-]+)?$`.
- Bump it with every connector change — it is recorded permanently on sources
  and analyses (below).
- The version is part of the public identity (`ConnectorDefinition::version`)
  shown by `connector:list` / `connector:inspect`.

## Platform compatibility gate (27R)

`ConnectorManifest::satisfiesPlatform()` supports `>=X.Y.Z`, plain `X.Y.Z`
(treated as `>=`) and `*`. Requirements compare against the platform's version
LINE — the major.minor.patch core with the prerelease stripped:

```
platform 0.1.0-rc.2  satisfies  >=0.1.0   → true
platform 0.1.0-rc.2  satisfies  >=0.2.0   → false
```

So a prerelease/rc build of a line satisfies that line's requirement. A
failed gate is a hard registration error (`ConnectorManifestInvalid`) —
`connector:inspect` also displays "Compatible: yes/NO" for the running
platform.

## Traceability stamps (27I.1/27I.2)

### MigrationSource

Columns added by the Phase 27 migration
(`database/migrations/2026_09_27_000000_phase27_connector_sdk.php`):

| Column | Written by |
|--------|-----------|
| `connector_key` | the stable key; mirrors legacy `type` (see `MigrationSource::effectiveConnectorKey()`) |
| `connector_version` | `MigrationCenterService::analyze()` calls `stampConnector()` after a successful analysis |
| `connector_instance_id` | instance identity for multi-instance setups |

`effectiveConnectorKey()` prefers `connector_key` and falls back to `type`,
so pre-SDK sources keep resolving through the registry unchanged.

### MigrationAnalysis

Every analysis row records, at creation time:

| Column | Value |
|--------|-------|
| `connector_key` | `$source->effectiveConnectorKey()` (raw key even if the connector is now unknown — the analysis then fails honestly on adapter resolution) |
| `connector_version` | the manifest version resolved during the run |
| `analysis_version` | `MigrationCenterService::ANALYSIS_VERSION` (`analysis@1`) |

### Artifacts

Analysis artifacts embed the same traceability in their `summary`
(27I.2): `connector_key`, `connector_version`, `analysis_version` and
`fingerprint`. Verified in
`ConnectorLifecycleTest::test_connector_version_is_stamped_on_sources_and_analyses`.

## Upgrades and historical evidence

- Analyses are immutable: every `analyze()` run creates a NEW `MigrationAnalysis`
  row (history is never overwritten).
- After a connector upgrade, old artifacts keep their `connector_version`
  stamps — a 2025 analysis made with `supabase` 1.0.0 remains attributable to
  1.0.0 even once 1.1.0 is installed. Comparing fingerprints across versions
  shows whether the source or the connector changed.
- `supportsResume()` is currently `false` for both shipped connectors;
  extraction re-runs are offset-batched, not token-based, so a source-side
  schema change is detected via fingerprint comparison rather than resumed
  cursors.

## Analysis schema evolution

`analysis@1` is the normalized inventory schema version the compatibility
engine consumes (schemas/tables/views/…/cron, 27B.2). When the normalized
shape changes in a future phase, `ANALYSIS_VERSION` bumps and historical
analyses remain interpretable because each row carries the version it was
produced with. Engine identity (`engine@1`) is recorded separately in
`MigrationAnalysis.driver_version`.
