# MongoDB connector

The `mongodb` connector package (`app/Connectors/Mongodb/`, stable key
`mongodb`, trust `first_party`, import flow `credentials-form`) imports an
existing MongoDB database — Atlas or self-hosted — through the Phase 27
Connector SDK into the platform's connector-agnostic Migration Center. Built
in Phase 28: everything MongoDB-specific lives in this package, the core
learns nothing MongoDB-specific.

## What works end-to-end

Connect → test → discover databases → select one → analyze (schema inference,
relationship candidates, index/validator analysis, GridFS detection) →
strategy classification → plan → batched extraction → migration into a
PostgreSQL-compatible target → validation (counts, id coverage, JSONB
equivalence). The full pipeline is proven by `MongodbMigrationTest` against a
scripted wire server and a disposable SQLite target, with Decimal128
exactness, Arabic UTF-8 roundtrip and child-table FK integrity asserted.

## The pure-PHP wire client

`app/Connectors/Mongodb/Protocol/` implements the MongoDB wire protocol
(OP_MSG over stream sockets) in pure PHP:

- no `ext-mongodb`, no PECL packages, no system installs — the platform host
  needs nothing beyond its existing PHP runtime;
- `BsonCodec.php` — BSON codec that decodes every value into a TAGGED
  representation so source types are preserved exactly (28K), including an
  exact BCMath-based Decimal128 (never float);
- `MongoWireClient.php` — connection, SRV seedlist resolution, optional TLS,
  batched `find`/`getMore` cursors and a HARD read-only command allowlist;
- `ScramAuth.php` — SCRAM-SHA-256 authentication (fallback SCRAM-SHA-1).

## Read-only guarantee

Every command the client can send is on a fixed allowlist
(`MongoWireClient::READ_COMMANDS`); write commands throw a `LogicException`
before serialization. Combined with least-privilege read credentials, source
mutation is structurally impossible — see READ_ONLY_PERMISSIONS.md.

## Capabilities

Declared (from `connector.json`):

| Capability | Status |
|------------|--------|
| `database_metadata` | SUPPORTED (needs `uri` or `host` configuration) |
| `data_extraction` | SUPPORTED (needs `uri` or `host` configuration) |
| `read_only_enforcement` | SUPPORTED |
| `source_fingerprint` | SUPPORTED |
| `resume` | SUPPORTED (`_id`-ordered range scans, 28H.1) |
| `client_scan` | SUPPORTED (MongoDB driver patterns in the client repo, 28R) |

Deliberately NOT declared (28A.2 honesty — statuses are never faked):

| Capability | Why not |
|------------|---------|
| `auth_metadata` | MongoDB database users are not application users (28O) |
| `policy_metadata` | MongoDB has no row-policy concept |
| `storage_metadata` (content) | GridFS buckets are reported as metadata buckets; content copy is DEFERRED (28V.8) |
| `incremental_export` | change streams are not implemented in Phase 28 |

## Documentation

| Doc | Contents |
|-----|----------|
| [INSTALLATION.md](INSTALLATION.md) | requirements, auto-discovery, artisan commands |
| [CONNECTION.md](CONNECTION.md) | URIs, split config, TLS, read preference, timeouts |
| [ATLAS.md](ATLAS.md) | SRV, TLS, SCRAM-SHA-256, least-privilege user |
| [SELF_HOSTED.md](SELF_HOSTED.md) | Docker setup, private-network opt-in |
| [READ_ONLY_PERMISSIONS.md](READ_ONLY_PERMISSIONS.md) | the built-in `read` role + command allowlist |
| [SCHEMA_INFERENCE.md](SCHEMA_INFERENCE.md) | sampling, per-field evidence, variance |
| [RELATIONSHIP_INFERENCE.md](RELATIONSHIP_INFERENCE.md) | DBRef, naming, confidence tiers |
| [TYPE_MAPPING.md](TYPE_MAPPING.md) | the BSON → PostgreSQL mapping table |
| [EMBEDDED_DOCUMENTS.md](EMBEDDED_DOCUMENTS.md) | flattening, name sanitization, depth overflow |
| [ARRAYS.md](ARRAYS.md) | JSONB arrays, derived child tables |
| [OBJECTID.md](OBJECTID.md) | id preservation, deterministic extraction, resume |
| [INDEX_MAPPING.md](INDEX_MAPPING.md) | unique/TTL/text/geo index candidates |
| [GRIDFS.md](GRIDFS.md) | bucket detection, metadata-only strategy |
| [AUTH_SEMANTICS.md](AUTH_SEMANTICS.md) | DB users ≠ app users, honest empty `auth` |
| [INCREMENTAL_MIGRATION.md](INCREMENTAL_MIGRATION.md) | resume today, change streams deferred |
| [MIGRATION_STRATEGIES.md](MIGRATION_STRATEGIES.md) | the JSONB/relational/hybrid decision matrix |
| [SECURITY.md](SECURITY.md) | secrets, SSRF, tamper defenses, the 28T battery |
| [TROUBLESHOOTING.md](TROUBLESHOOTING.md) | common errors and fixes |
| [TEST_MATRIX.md](TEST_MATRIX.md) | the 37 Phase 28 tests |
| [CLIENT_SCAN.md](CLIENT_SCAN.md) | repository scanning for MongoDB usage |

## Package contents

| File | Role |
|------|------|
| `connector.json` | manifest (6 capabilities, 4 permissions, `platform_requirement >=0.2.0`) |
| `MongodbConnector.php` | `SourceConnector`, `AnalyzableSourceConnector`, `ExtractableSourceConnector`, `ValidatableSourceConnector`, `ClientScannerProvider` |
| `MongodbSourceAdapter.php` | read-only adapter: inventory, strategies, extraction, fingerprint |
| `SchemaInferer.php` | probabilistic per-field schema evidence (28E) |
| `RelationshipInferer.php` | relationship candidates with confidence (28F) |
| `TypeMapper.php` | deterministic BSON → PostgreSQL mapping (28K) |
| `FieldNameSanitizer.php` | collision-safe identifier sanitization (28T.2) |
| `GridFsInspector.php` | GridFS bucket detection (28N) |
| `MongodbClientScanner.php` | client-repo driver patterns (28R) |
| `Protocol/BsonCodec.php` | pure-PHP BSON codec with tagged values |
| `Protocol/MongoWireClient.php` | OP_MSG wire client, read-only allowlist |
| `Protocol/ScramAuth.php` | SCRAM-SHA-1/256 authentication |
| `Protocol/MongoCommandException.php` | command errors carrying the server code |
