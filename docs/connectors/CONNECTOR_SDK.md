# Connector SDK

Phase 27 turns the platform from "Supabase import built into the platform"
into a **generic migration platform with installable source connectors**.
Future migration sources (MongoDB, Firebase, PostgreSQL, MySQL, PocketBase,
Appwrite, Neon, …) are added as connector packages **without changing the
platform core**.

## What the core knows

- what a `Connector` is (`App\Services\ControlPlane\Connectors\Contracts\Connector`)
- the stable capability vocabulary (`ConnectorCapability`, 27C)
- how to invoke standardized connector operations (`SourceConnector`, 27B)
- how to authorize connector access (scoped secret resolution, 27E)
- how to store connector configuration (`MigrationSource` + vault refs)
- how to render connector UI metadata (generic wizard cards, 27G)
- how to feed connector output into the Migration Center (normalized
  inventory → analysis → plan → run → validation)
- how to validate connector behavior (Testing SDK, 27L)

## What the core does NOT know

- Supabase-specific API logic — lives in `app/Connectors/Supabase/`
- MongoDB-specific schema logic — Phase 28 will live in `app/Connectors/Mongodb/`
- provider-specific credentials — connectors declare declarative schemas

## The two layers

```
Platform core (provider-agnostic)
├── ConnectorRegistry          — key → connector, enabled/disabled, dup guard
├── ConnectorCapabilityProbe   — dynamic capability matrix + live probe
├── ConnectorDiscovery         — first-party package discovery (connector.json)
├── ConnectorManifest          — strict manifest validation + platform gate
├── Support\ScopedSecretResolver  — vault-backed, schema-scoped credentials
├── Support\ConnectorNetworkGuard — SSRF protection for connector URLs
├── Support\ProjectScopedFileReader — traversal-guarded local reads
├── Support\ConnectorLogger    — structured, redacting operation logs
├── Support\ConnectorArtifactWriter — scoped artifact persistence
└── Testing\ConnectorContractTester — the 27L contract battery

Connector packages (provider-specific, self-contained)
├── app/Connectors/Supabase/     — key "supabase" (Phase 25 logic, unchanged behavior)
├── app/Connectors/ExampleJson/  — key "example-json" (developer example)
└── app/Connectors/<Your>/       — scaffolded via `php artisan connector:make`
```

## Source lifecycle (27F)

```
Install/Register → Configure → Test Connection → Discover → Select Source
→ Capability Probe → Analyze → Extract → Validate → Disable → Remove
```

Removing an instance clears configuration + secret references but PRESERVES
historical analyses/artifacts (never erase migration history silently).

## Registered connectors

| Key            | Trust       | Import flow       | Purpose                                  |
|----------------|-------------|-------------------|------------------------------------------|
| `supabase`     | first_party | supabase-account  | Phase 25 Supabase import (read-only PG)  |
| `example-json` | first_party | credentials-form  | Local JSON dataset developer example     |

Only `first_party` trust is enabled by default (27K.1). See
`CONNECTOR_ARCHITECTURE.md` for the full design and
`BUILD_YOUR_FIRST_CONNECTOR.md` to write your own.
