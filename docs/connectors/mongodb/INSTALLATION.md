# Installation

Nothing to install. The MongoDB connector is pure PHP — no `ext-mongodb`, no
PECL extension, no `mongod`/`mongosh` binaries on the platform host (mongosh
is only used on YOUR MongoDB server to create the read-only user, see
READ_ONLY_PERMISSIONS.md).

## Requirements

| Requirement | Value |
|-------------|-------|
| Platform | `>=0.2.0` (`connector.json` → `platform_requirement`; enforced by the strict manifest gate) |
| PHP | the platform's own runtime — `stream` sockets for the wire client, `BCMath` for exact Decimal128 (both standard) |
| Composer packages | none |
| Source-side | any MongoDB 4.x+ reachable over the wire protocol (Atlas or self-hosted) |

## Auto-discovery

The connector is discovered like every first-party package:
`ConnectorDiscovery` scans `app/Connectors/*/connector.json` and registers
the manifest with `ConnectorRegistry`. There is no service provider, config
file or registration step — dropping (or having) the
`app/Connectors/Mongodb/` package is the whole installation. The manifest
declares `trust: first_party` (enabled by default, 27K.1) and the
`credentials-form` import flow.

`ConnectorManifest::parseFile` validates the manifest strictly at
registration: the `platform_requirement >=0.2.0` gate, the capability
vocabulary, permission keys and the UI metadata are all checked before the
connector can appear in `connector:list` — a malformed manifest fails
loudly rather than half-registering.

## Verify with artisan

```bash
php artisan connector:list            # mongodb appears in the registry table
php artisan connector:inspect mongodb # definition, credential schema, capabilities (no secret values)
php artisan connector:test mongodb    # the 27L contract battery against the connector
```

`connector:test` is the first diagnostic to run when anything misbehaves —
it validates the manifest, capability honesty, secret-leak (canary) and
read-only profile without touching a real server.

## Permissions declared by the manifest

| Permission | Purpose |
|------------|---------|
| `network.outbound` | connect to the MongoDB server |
| `network.local_source` | loopback/private targets (still requires the operator opt-in, 28B.3) |
| `source.db.read` | read-only database access |
| `client.repo.read` | client-repository scanning for MongoDB driver usage (28R) |

## What is deliberately NOT installed

- No MongoDB C driver or `mongodb.so` — the connector speaks the wire
  protocol itself (`app/Connectors/Mongodb/Protocol/`).
- No connection broker, tunnel or sidecar — direct stream sockets only.
- No write path of any kind — the client's command allowlist contains only
  read and authentication commands.

Next step: CONNECTION.md to configure the source.
