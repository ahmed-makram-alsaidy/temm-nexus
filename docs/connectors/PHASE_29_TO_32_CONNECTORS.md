# Phase 29–32: Firebase, PostgreSQL, MySQL connectors and CDC

Status vocabulary is honest by contract: **SUPPORTED** = implemented and
tested, **PARTIAL** = implemented with documented limits, **PLANNED** /
**DEFERRED** = not implemented (never documented as working).

## Firebase connector (`firebase`) — Phase 29

SUPPORTED: project connection via service account (vault-backed secret) or
local emulator; Firestore analysis with INFERRED schema (Firestore has no
authoritative relational schema — all field structure is evidence from
sampled documents); relationship candidates (EXPLICIT = DocumentReference /
subcollection links → proposed foreign keys; HIGH_CONFIDENCE = `*_id` value
overlap; POSSIBLE / UNKNOWN = advisory only); batched extraction in
deterministic `__name__` order with resume; Auth inventory WITHOUT password
material (hashes are never read; password portability is always
NEEDS_REVIEW); Storage inventory with per-object copy strategy; Cloud
Functions inventory and classification (no 1:1 platform mapping is claimed);
client repository scanning for `firebase/*`, `firebase-admin` and Flutter
plugins.

Implemented directly over the Firebase REST APIs with RS256 service-account
JWTs (ext-openssl) — no provider SDK dependency.

## Generic PostgreSQL connector (`postgres`) — Phase 30

SUPPORTED: import of ANY standard PostgreSQL database (Supabase not
required). pg_catalog-native inspection: schemas, tables and partitioned
parents, views and materialized views, indexes, PK/FK/check constraints,
sequences with current state, functions vs procedures vs aggregates,
triggers, extensions, enums, domains, generated and identity columns,
partition bounds, RLS policies, large-object inventory. Read-only sessions
are pinned (default_transaction_read_only) AND verified. Extraction is
keyset-ordered by primary key (composite keys included), deterministic and
resumable.

Type preservation: the exact `format_type()` value is kept per column
(`source_type`); extension types (PostGIS geometry, …) are flagged
NEEDS_REVIEW — never silently approximated.

## MySQL / MariaDB connector (`mysql`) — Phase 31

SUPPORTED: MySQL 8+ import into PostgreSQL-backed projects. Explicit type
mapping with unsigned-safe widening (unsigned bigint → numeric(20,0), …) so
no value can overflow the target; `tinyint(1)`/`bit(1)` → boolean; ENUM
members preserved as PostgreSQL enum types; SET and `bit(n>1)` flagged
NEEDS_REVIEW. AUTO_INCREMENT state captured for sequence restoration.
utf8mb4/utf8mb3 verified UTF-8-safe; other charsets flagged for transcoding
review (never silently transcoded). Views, triggers, routines and scheduled
events inventoried.

MariaDB compatibility: **PARTIAL** by design — validated per rehearsal
against the actual server version; not claimed universally.

## Change capture / incremental migration — Phase 32

Generic CDC contract (32A): connectors may implement `ChangeCaptureConnector`
with checkpoint-kind semantics (LSN / resume token / binlog GTID /
watermark). Checkpoints are stored HMAC-signed and verified on load — a
tampered or transplanted checkpoint is refused (32C/32F). Events apply
through an idempotent upsert-by-PK applier so duplicates, out-of-order
events, restarts and mid-batch crashes converge instead of corrupting the
target (32G).

Provider readiness (probes read configuration, never mutate it):

| Source | Mechanism | Status |
|---|---|---|
| PostgreSQL | logical replication | SUPPORTED_WITH_CONFIGURATION (operator sets `wal_level=logical`; instructions in `CdcProbe::postgres`) |
| MongoDB | change streams | SUPPORTED on replica sets / sharded; NOT_SUPPORTED standalone |
| MySQL/MariaDB | binlog | SUPPORTED_WITH_CONFIGURATION (ROW format + replication privileges; instructions in `CdcProbe::mysql`) |
| Firebase | — | DEFERRED (32E): no robust generic semantics; re-export is the delta step |

Watermark-based INCREMENTAL EXPORT (polling a monotonic marker column) is
available for any table with such a marker — it is NOT log-based CDC and
never claims to be (deleted rows are not captured).

## Security posture (all phases)

- Source credentials live in the vault; never logged, never in artifacts,
  never sent to AI.
- Sources are strictly read-only (verified read-only sessions where the
  provider allows; read-statement allowlists everywhere).
- Secret scanning and private-reference scanning gate every phase.
