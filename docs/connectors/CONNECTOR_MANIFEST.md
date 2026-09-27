# Connector manifest (connector.json)

The manifest is the ONLY discovery surface for a connector package. It is
validated strictly and all-or-nothing by
`App\Services\ControlPlane\Connectors\ConnectorManifest` (27J.2/27J.3) before
a connector can register: malformed, oversized, injection-carrying or
platform-incompatible manifests are rejected safely — never partially loaded.

## Package layout

```
app/Connectors/<Pascal>/
├── connector.json     ← the manifest
├── <Name>Connector.php ← entrypoint (must implement the Connector contract)
└── ...                ← adapters, services, datasets (package-private)
```

Discovery (`ConnectorDiscovery::discover`) scans `config('connectors.paths')`
(default `[app_path('Connectors')]`) for `*/connector.json`.

## Fields

| Field | Type / rules | Notes |
|-------|--------------|-------|
| `schema_version` | `1` | only schema version 1 is supported (`SUPPORTED_SCHEMA_VERSIONS = [1]`) |
| `key` | regex `^[a-z][a-z0-9]{1,20}(-[a-z0-9]{1,20}){0,4}$` | lowercase kebab, max 32 chars; reserved keys: `core`, `platform`, `registry` |
| `name` | 1–60 chars, no control characters | display name |
| `version` | `^\d+\.\d+\.\d+(-[0-9A-Za-z.\-]+)?$` | semantic version (see CONNECTOR_VERSIONING.md) |
| `description` | string ≤ 500 chars, no control characters | |
| `author` | string ≤ 100 chars | |
| `license` | optional string ≤ 40 chars | |
| `platform_requirement` | optional `>=X.Y.Z` (or plain `X.Y.Z`, or `*`) | hard compatibility gate (27R), see below |
| `entrypoint` | PHP class name (no slashes/dots/traversal) | must exist AND implement `Contracts\Connector` |
| `capabilities` | list ≤ 32 keys from the 27C vocabulary | unknown keys are rejected (`ConnectorCapability::isValid`) |
| `permissions` | list from the permission vocabulary | see CONNECTOR_SECURITY.md |
| `trust` | `first_party` \| `trusted` \| `unverified` (default `unverified`) | see CONNECTOR_SECURITY.md |
| `import_flow` | `supabase-account` \| `credentials-form` \| `none` (default `none`) | drives the generic onboarding wizard |
| `category` | string, default `source` | |
| `ui` | object: `display_name`, `icon`, `docs_url`, `setup_instructions`, `capability_labels` | `docs_url` must be http(s) (`ConnectorDefinition::safeDocsUrl`); setup instructions capped at 2000 chars |

No other root-level fields are allowed — unknown keys are a typo/injection
surface and fail validation.

## Example (real)

`app/Connectors/ExampleJson/connector.json`:

```json
{
    "schema_version": 1,
    "key": "example-json",
    "name": "Example JSON Connector",
    "version": "1.0.0",
    "description": "Developer example: analyzes and migrates a local JSON dataset directory ...",
    "author": "Backend Control Plane (first-party)",
    "license": "MIT",
    "platform_requirement": ">=0.1.0",
    "entrypoint": "App\\Connectors\\ExampleJson\\ExampleJsonConnector",
    "capabilities": [
        "database_metadata", "data_extraction", "read_only_enforcement", "source_fingerprint"
    ],
    "permissions": ["filesystem.dataset.read"],
    "trust": "first_party",
    "import_flow": "credentials-form",
    "category": "source",
    "ui": {
        "display_name": "Example JSON Connector",
        "icon": "heroicon-o-document-text",
        "docs_url": null,
        "setup_instructions": "Point dataset_path at a directory of JSON files ..."
    }
}
```

## Guards

| Guard | Value | Where |
|-------|-------|-------|
| Manifest size cap | 65 536 bytes (64 KB) — oversized metadata is rejected before parsing (27T) | `ConnectorManifest::MAX_BYTES` |
| JSON depth / syntax | `json_decode` with `JSON_THROW_ON_ERROR`, max depth 24 | `parseFile()` |
| Capability count | max 32 declared capabilities | `validate()` |
| Entrypoint check | class must exist and implement the contract; entrypoint must report the SAME key as the manifest (impostor packages are refused) | `validate()` + `ConnectorDiscovery::registerPackage()` |
| Platform gate | `>=X.Y.Z` compared against the platform core version line | `satisfiesPlatform()` / `Support\Platform` |

## Platform compatibility gate (27R)

Requirements are compared against the platform's version LINE: the
major.minor.patch core with any prerelease suffix stripped
(`"0.1.0-rc.2"` → `"0.1.0"`, via `Support\Platform::core()`). So a
prerelease platform build like `0.1.0-rc.2` satisfies `">=0.1.0"`, while
`">=0.2.0"` does not. `"*"` or an empty requirement always passes. A failed
gate throws `ConnectorManifestInvalid` — the package never registers.

## Validation is all-or-nothing

Every problem is collected and reported at once via
`ConnectorManifestInvalid::with($errors, $path)`:

```
Invalid connector manifest (connector.json): manifest key must be lowercase
kebab (max 32 chars), got "Bad Key!!"; ...
```

A package that fails validation is never registered. Discovery records it on
the registry (`ConnectorRegistry::reject()`) and, when migrations exist, writes
an audit event `CONNECTOR_PACKAGE_REJECTED` with the package name and a
truncated reason. `php artisan connector:list` prints rejected packages.

## Honesty note

Registration success ≠ enablement. A manifest can be perfectly valid yet the
connector stays **disabled** because its trust level is not enabled by default
(only `first_party` is, 27K.1). Invocation of a disabled connector throws
`ConnectorDisabled`. Third-party/remote package installation is intentionally
NOT implemented in Phase 27 (27J.4).
