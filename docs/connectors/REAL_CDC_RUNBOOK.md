# Real log-based CDC (Phase 35.6)

TEMM Nexus captures REAL change streams from PostgreSQL (WAL logical
decoding), MySQL (row-based binlog) and MongoDB (change streams), applies
them idempotently to the target and persists SIGNED positions that survive
crashes, restarts and redeployments. This document is the operator contract:
prerequisites, delivery semantics, failure behaviour and cutover
implications. Everything here was proven against live servers on the test
VPS (Azure D2as_v6, 2 vCPU / 8 GiB) — see the Phase 35.6 report.

## The generic contract

The core never learns provider internals. Connectors optionally implement:

- `CdcCaptureProvider` — builds a `ChangeCaptureConnector` for a source;
- `ChangeCaptureConnector::captureChanges()` — delivers normalized
  `CdcEvent`s (INSERT/UPDATE/DELETE with before/after images, source
  timestamp, transaction identity) in source order and returns the NEW
  position payload;
- `CdcPositionSource` — the freeze-boundary position (final sync) and
  normalized lag metrics.

The generic loop (`CdcCaptureWorker`, artisan `migration:cdc-capture`)
applies every batch through `CdcApplier` and persists the position through
`CdcCheckpointManager` (HMAC-SHA256 over run + source + target + kind +
position). A checkpoint edited or transplanted across runs/connectors is
REFUSED, never trusted.

## Delivery semantics (read this twice)

**At-least-once with an idempotent applier.** The position advances only
after every event it covers has been applied synchronously. A crash
anywhere — mid-apply, between apply and persist, between cycles — replays
from the last signed checkpoint; replayed upserts and deletes converge to
the same target state. Silent at-most-once loss is impossible by
construction: no position is ever persisted for unapplied changes.

**Transaction boundaries.** PostgreSQL changes are delivered per COMMIT
(never partially). MySQL row events are grouped per transaction
(GTID/BEGIN → rows → Xid) and delivered on commit. MongoDB single-document
changes are atomic; events from multi-document transactions are applied as
independent idempotent writes — no cross-document atomicity is claimed.

**Deletes.** A delete removes the row identified by the primary key and is
idempotent (deleting an absent row is a no-op). PostgreSQL deletes carry
the replica-identity tuple; MySQL deletes carry the full old image and are
narrowed to the plan's primary key; MongoDB deletes carry `documentKey._id`.

## PostgreSQL

Prerequisites (the probe reads them; the platform NEVER changes them):

- `wal_level=logical`, `max_replication_slots>=1`, `max_wal_senders>=1`
  (requires a server restart — operator action);
- a REPLICATION-capable role (the platform account owns the slot);
- tables in the publication need `REPLICA IDENTITY DEFAULT` (or FULL) for
  UPDATE/DELETE; `REPLICA IDENTITY NOTHING` is refused honestly.

Mechanism: pure-PHP streaming replication (md5 / cleartext / SCRAM-SHA-256
auth), logical slot + publication scoped to the project and run
(`temm_slot_<project>_<runhash>`), pgoutput v1 (text tuples: bytea stays
hex, numeric keeps full precision, timestamps, arrays, jsonb, Arabic UTF-8
verified). Unchanged-TOAST columns are resolved by key from the source —
never nulled. TRUNCATE on a published table pauses capture with an explicit
error (re-snapshot required).

Position: the end LSN of the last applied commit (+ the drained flag, the
server WAL end and the source committed-transaction count as evidence).
Slot lifecycle: created only when source setup is explicitly allowed
(`CDC_ALLOW_SOURCE_SETUP`, disposable sources), reused only when plugin and
database match, WAL-retention health reported (`wal_status`,
`safe_wal_size`), and dropped when the run completes — never left behind.

## MySQL

Prerequisites:

- `log_bin=ON`, `binlog_format=ROW`, `binlog_row_image=FULL`;
- REPLICATION SLAVE + REPLICATION CLIENT on the migration account;
- GTID mode ON is recommended (the executed GTID set is recorded in every
  checkpoint as supporting evidence);
- the account needs `mysql_native_password` (or TLS) — the binlog client
  does not perform RSA key exchange over plaintext.

Mechanism: the pure-PHP `krowinski/php-mysql-replication` package inside
the connector decodes row events (DECIMAL as text, BIGINT UNSIGNED, JSON,
ENUM, BLOB, utf8mb4). FILE+POSITION streaming is used because the package's
COM_BINLOG_DUMP_GTID packet receives only heartbeats from MySQL 8.0
(verified live); resume is equally crash-safe from the file/offset boundary,
and binlog rotation is followed. Type preservation is exact (no floats for
DECIMAL/BIGINT UNSIGNED).

Position: the binlog file + the `log_pos` END of the last applied
transaction (boundary-aligned by the header), plus the accumulated executed
GTID set. The capture starts at SHOW MASTER STATUS (the snapshot boundary
is the operator's snapshot) — establish capture immediately after the
snapshot to avoid a gap.

## MongoDB

Prerequisites:

- a replica set or sharded cluster (standalone deployments cannot provide
  change streams — the probe reports NOT_SUPPORTED honestly);
- changeStream privileges on the source database.

Mechanism: `aggregate $changeStream` (fullDocument `updateLookup`) over the
pure-PHP wire client, awaitData getMore. insert/update/replace become
upserts of the full document (missing fields are honest errors — see schema
drift); delete removes by `_id`. `INVALIDATE` events stop capture with an
explicit error: the resume token can never recover — re-snapshot is
required, and capture never silently restarts "from now".

Position: the server's resume token (JSON-safe round trip), the applied and
source cluster times, and the drained flag. Tokens survive mongod restarts.

## Schema drift (§15)

A source DDL change during capture is handled safely or the capture PAUSES
with an explicit error — the target is never silently corrupted. Proven
live: a document missing a NOT NULL target field stopped the stream with a
constraint violation; the checkpoint did not advance; the operator widened
the target column (`ALTER TABLE ... DROP NOT NULL`) and capture resumed
from the same position with zero loss. Structural changes (new tables,
renames) require re-analysis — `SCHEMA_DRIFT_REQUIRES_REPLAN` semantics.

## Lag, freshness and the cutover gate

Each cycle persists normalized telemetry beside the signed position:
stream status (active / caught_up / idle / disconnected / error), source /
captured / applied position LABELS, lag seconds, last event time and
provider detail (byte lag, GTID set, cluster times).

The Cutover Center's `cdc_lag` gate reads only that telemetry:

- **BLOCK** — stream erroring/disconnected, telemetry stale
  (`cdc.lag.stale_after_seconds`, default 120s), lag beyond
  `cdc.lag.block_seconds` (300s) or backlog beyond `cdc.lag.block_events`;
- **WARN** — lag beyond `cdc.lag.warn_seconds` (60s) or backlog beyond
  `cdc.lag.warn_events` (1000s), or applying-but-not-drained;
- **PASS** — caught up (drained) and fresh;
- **UNVERIFIED** — no real log-based checkpoint for the run. Watermark
  incremental export is NOT log-based CDC and never satisfies this gate.

"Caught up" is proven by DRAINING (a quiet read with nothing pending), not
by clock comparison: instance WAL and cluster clocks advance on their own.
Thresholds are conservative defaults in `config/cdc.php` — tune them per
workload, never assume one threshold fits every source.

## Final sync (the last delta)

`CutoverCenterService::finalDelta()` — after the operator's write freeze
(an approved gate; the platform never freezes anything):

1. capture the CURRENT source position (freeze boundary, audited);
2. run capture cycles until TWO consecutive DRAINED cycles (the second
   proves no straggler committed during the first) or the LSN/file-pos
   comparison covers the frozen position;
3. audit the result. `applied_through=true` ⇒ DATA_READY_FOR_CUTOVER.

The platform never switches DNS/endpoints — that remains an approved,
operator-executed step.

## Security notes

- Checkpoints are HMAC-signed over the full scope (run, source type,
  target key, kind, position); transplanting a position across runs or
  connectors fails signature verification.
- Telemetry is deliberately NOT signed (it is measurement, not position);
  the position payload itself is what tamper protection guards.
- Credentials travel through the vault (`secret_refs`) — never in
  connection strings, logs, or error messages; error strings are UTF-8
  scrubbed before storage.

## Honest limitations

- The MySQL binlog decoding uses a vetted pure-PHP package; the GTID dump
  packet of that package is unusable on MySQL 8.0 (heartbeats only), so
  file+position is the durable resume mode (GTID set recorded as evidence).
- MongoDB multi-document transactions are applied without cross-document
  atomicity (convergent, idempotent — see semantics above).
- TRUNCATE and Mongo INVALIDATE pause capture deliberately — they need an
  operator decision (re-plan / re-snapshot), never an automatic guess.
- Throughput figures in the Phase 35.6 report describe the Azure D2as_v6
  test VM; they are not a universal benchmark.
