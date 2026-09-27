# MongoDB connector design (Phase 28)

## IMPLEMENTATION STATUS (Phase 28)

**IMPLEMENTED.** The connector ships at `apps/owner-console/app/Connectors/Mongodb/`
(stable key `mongodb`, trust `first_party`, import flow `credentials-form`,
`platform_requirement >=0.2.0`), built ONLY on the Phase 27 Connector SDK —
no MongoDB-specific branching in core. It runs on a **pure-PHP wire protocol
client** (`Protocol/MongoWireClient.php` + `Protocol/BsonCodec.php` +
`Protocol/ScramAuth.php`): OP_MSG over stream sockets, tagged BSON with an
exact BCMath Decimal128, SCRAM-SHA-256 (fallback SCRAM-SHA-1) — no
`ext-mongodb`, no system installs.

Shipped capabilities (declared in `connector.json`): `database_metadata`,
`data_extraction`, `read_only_enforcement`, `source_fingerprint`, `resume`
(`_id`-ordered range scans), `client_scan`. Deliberately NOT declared:
`auth_metadata` (28O), `policy_metadata`, storage content (GridFS buckets
are metadata-only), `incremental_export` (change streams not implemented —
honest status per 27C.1). The read-only guarantee is structural: a hard
command allowlist in the wire client; write commands throw before
serialization.

Deferred (documented, not faked): change streams / incremental export
(see `docs/connectors/mongodb/INCREMENTAL_MIGRATION.md`), GridFS content
copy (`PRESERVE_METADATA` + `content_migration: DEFERRED`, 28V.8), live
Atlas SRV/TLS verification (requires real cluster credentials; the scripted
wire server covers the protocol end to end).

Test evidence: **37 tests / 326 assertions** in
`apps/owner-console/tests/Feature/Phase28/` (5 classes, spec 28A-28U,
mapped in `docs/connectors/mongodb/TEST_MATRIX.md`), including the full
analyze → classify → plan → run → validate pipeline on a disposable SQLite
target with Decimal128 exactness, Arabic UTF-8 roundtrip, JSONB
preservation and child-table FK integrity.

Full documentation set: `docs/connectors/mongodb/README.md` (index).

---

Historical status (Phase 27 planning cycle — superseded by the section
above): at that time no MongoDB runtime code existed — the key `mongodb`
was NOT registered, `ConnectorRegistry::resolve()` on a `mongodb` source
threw `ConnectorNotSupported` (asserted in
`ConnectorRegistrySecurityTest::test_unknown_connector_fails_gracefully`),
and no `app/Connectors/Mongodb/` package existed yet.

Phase 28 will implement this connector using **ONLY the Connector SDK**: a
package under `app/Connectors/Mongodb/` behind stable key `mongodb`, with
**no MongoDB-specific branching in core** — exactly like `example-json`
proved (27N). The normalized inventory shape produced by the Supabase adapter
(27B.2) is the target contract.

## 1. Package shape

```
app/Connectors/Mongodb/
├── connector.json            # key "mongodb", trust per review, import_flow credentials-form
├── MongodbConnector.php      # SourceConnector, AnalyzableSourceConnector, ExtractableSourceConnector
├── MongodbSourceAdapter.php  # BaseSourceAdapter over a MongoDB driver connection
└── (optional) MongodbClientScanner.php
```

Manifest sketch:

```json
{
    "schema_version": 1,
    "key": "mongodb",
    "version": "0.1.0",
    "platform_requirement": ">=0.2.0",
    "capabilities": [
        "database_metadata", "data_extraction", "read_only_enforcement",
        "source_fingerprint", "incremental_export"
    ],
    "permissions": ["network.outbound", "network.local_source", "source.db.read"],
    "import_flow": "credentials-form"
}
```

## 2. Credentials (credential schema example)

| Key | Type | Scope | Secret | Notes |
|-----|------|-------|--------|-------|
| `uri` | password | source | yes | MongoDB connection URI (Atlas `mongodb+srv://…` or local `mongodb://host:27017`) — stored in the project vault via `secret_refs` |
| `database` | text | configuration | no | target database (a source maps to ONE database; multi-database = multiple sources) |
| `tls` | boolean | configuration | no | enable TLS |
| `tls_ca` | file | source | yes (secret ref) | CA certificate **reference** resolved server-side, never stored in `connection` JSON |
| `auth_db` | text | configuration | no | authentication database (default `admin`) |

`analysisRequires()` / `extractionRequires()`: `['uri', 'database']`.
`network.local_source` is declared so self-hosted `mongodb://localhost` works
when the operator sets `CONNECTOR_ALLOW_PRIVATE_NETWORKS=true`; Atlas URIs
satisfy the public-HTTPS SSRF path (`mongodb+srv` is TLS by default).

Read-only guarantee (mirrors the Supabase adapter's three layers): connect
with a read-scoped user, never issue write commands, and perform all reads
inside sessions with `readPreference=secondaryPreferred` semantics where
available — the adapter contract (24J.1: never mutate the source) is the
hard rule.

## 3. Normalized inventory mapping (27B.2 target contract)

| MongoDB concept | Normalized inventory section |
|-----------------|------------------------------|
| database | `schemas: [<database>]` |
| collection | one entry in `tables[]` (`schema: <db>`, `name: <collection>`) |
| document fields | `columns[]` (inferred, see §5) |
| indexes (`listIndexes`) | `tables[].indexes` (`name`, `unique`, `columns`; text/geo flags in metadata) |
| `_id` | `primary_key: ['_id']` |
| `$jsonSchema` validators | `tables[].validation` + `rules[]` (see §7) |
| views (`system.views`) | `views[]` |
| GridFS buckets | `storage[]` mapping (see §8) |
| MongoDB users/roles | NOT `auth` — see §9 |
| embedded arrays/objects | `columns[].type: jsonb` (see §6) |

Honest emptiness: `matviews`, `enums`, `functions`, `triggers`, `policies`,
`realtime`, `cron` are reported empty/absent; `auth` reports
`present: false` with `hash_strategy: 'not_supabase_auth'` — the same
all-or-nothing normalized shape `ExampleJsonSourceAdapter` produces.

## 4. Reference model (what already exists to copy)

`ExampleJsonSourceAdapter` is the template: `connect()` validates
configuration, `inventory()` builds the full normalized shape with honest
empty sections, `streamRows()` is batched/deterministic/projection-aware,
`countRows()` exact, `validateSource()` emits counts + integrity issues.
The MongoDB adapter swaps "parse JSON files" for "query collections" — nothing
in the engine changes.

## 5. Schema inference from sampled documents

Documents are schemaless, so the adapter samples (bounded sample size from
`config('connectors.limits')`):

1. Walk sampled documents in field-appearance order (like `inferColumns()`).
2. Map BSON types → normalized types (the engine's PostgreSQL-style
   vocabulary):

| BSON type | Normalized type |
|-----------|-----------------|
| int32 / int64 | `int8` |
| double / decimal128 | `float8` |
| bool | `boolean` |
| date / timestamp | `timestamptz` |
| ObjectId (as string projection) | `uuid`-style text — preserved exactly, see §6 |
| UUID (BSON binary subtype 4) | `uuid` |
| object | `jsonb` |
| array | `jsonb` |
| string | `text` (ISO-datetime/UUID patterns promote to `timestamptz`/`uuid`) |
| null / missing | contributes nullability |

3. Conflicts widen exactly like the Example connector (`int+float → float`,
   anything else → `text`); a column present in some documents is nullable.
4. Sampling limitation documented in the analysis `warnings[]`: rare fields
   may be missed — re-run analysis to refresh.

## 6. Embedded documents, arrays, ObjectId

- **Embedded documents and arrays** map to `jsonb` columns. Migration to
  PostgreSQL stores them as JSONB (target adapter's existing jsonb handling);
  AI-assisted flattening (§10) can propose relational unpacking but never
  changes the normalized inventory contract.
- **ObjectId preservation**: `_id` is the primary key and MUST round-trip
  byte-exact (24J.4 spirit). ObjectIds are carried as their 24-hex canonical
  string; extraction keeps the hex string stable so target rows keep
  referencing the same ids. Cross-collection references stored as ObjectIds
  therefore survive migration.
- **References vs embedding detection** (informational, feeds AI mapping):
  a field named `<singular>_id` / `<singular>Id` whose values match the id
  population of another collection is surfaced as a *reference hint*
  (`metadata.reference_hints` on the table item, e.g. `order_id → orders`).
  This is advisory — it does not fabricate FK constraints in the inventory.

## 7. $jsonSchema validation rules

Collection validators are read via `listCollections` (with `filter`/options)
or the `$jsonSchema` in `db.getCollectionInfos()`. The adapter stores:

```json
"validation": {"validator": "jsonSchema", "level": "moderate", "action": "error"},
"rules": [{"field": "email", "bsonType": "string", "required": true}]
```

The compatibility classifier can then flag target-side check constraints vs
JSONB schema checks; rules are metadata only — the source is never modified.

## 8. GridFS buckets → storage mapping

`fs`-prefixed GridFS buckets (`<bucket>.files` + `<bucket>.chunks`) map to the
normalized `storage` section so the generic UI renders them like Supabase
storage:

```json
"storage": {
    "present": true,
    "buckets": [{"name": "fs", "kind": "gridfs", "files": 1204, "bytes": 8847320}],
    "object_counts": {"fs": {"objects": 1204, "bytes": 8847320}}
}
```

Phase 28 scope: metadata only (`storage_metadata`). Streaming object content
(`storage_content`) is explicitly out of scope and NOT declared.

## 9. MongoDB users are NOT application auth

Supabase's `auth.users` is an application-identity domain. MongoDB
`db.system.users` (SCRAM users) are **database administration principals**.
The design keeps the normalized `auth` section `present: false` and records
admin user metadata, if at all, as engine metadata — no auth domain, no
`streamAuthUsers` override (the `BaseSourceAdapter` default returning 0 stays).
This distinction is contractual: the compatibility engine treats `auth: 0`
counts honestly.

## 10. Incremental export and AI mapping hooks

- **Change streams** (`resume` capability): a tailable change stream cursor is
  the natural fit for `incremental_export` — the first MongoDB capability set
  to declare it. The adapter records a cluster-time/resume token as the
  extraction cursor; `supportsResume()` returns `true` ONLY if token
  persistence lands in Phase 28. Otherwise the connector ships without
  `incremental_export`/`resume`, matching the honest-status rule (27C.1).
- **AI-assisted relational mapping**: reference hints (§6), `$jsonSchema`
  rules (§7) and sampled type statistics are attached to analysis items so
  the AI Copilot can propose relational unpacking of hot embedded documents
  (e.g. `order_items[]` → a child table) as plan suggestions. Proposals are
  operator-approved plan edits; the connector never rewrites the inventory.

## 11. Migration target mapping (PostgreSQL)

| MongoDB | PostgreSQL target |
|---------|-------------------|
| collection | table |
| embedded object/array | `jsonb` column |
| ObjectId `_id` | text/uuid primary key (canonical hex preserved) |
| date | `timestamptz` |
| decimal128 | `numeric` |
| binary (non-UUID) | `bytea` (flagged as a risk by the classifier) |
| GridFS | metadata rows; content migration out of scope |

The existing rehearsal/validate suite (disposable sqlite or PostgreSQL
targets) validates counts and referential integrity exactly as
`ExampleConnectorEndToEndTest` does.

## 12. Contract checklist for Phase 28

- [ ] Passes `ConnectorContractTester` battery (manifest, honesty, canary
      secret leak, read-only profile)
- [ ] Normalized 27B.2 inventory, all sections present (honestly empty where
      not applicable)
- [ ] Secret minimization: discovery/analysis receive only `resolveScoped`
      fields; URI never in `connection` JSON or logs
- [ ] Deterministic extraction ordering (`_id` keyset — offset paging is
      meaningless in MongoDB)
- [ ] `supportsResume()` honest; no faked `incremental_export`
- [ ] No core edits: registry discovery, Migration Center, wizard, validators
      all consumed unchanged
