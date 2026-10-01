# TEMM NEXUS — PHASE 35.6 REAL CDC REPORT

BASELINE:
- Branch — develop/0.3.0
- Version — 0.3.0-dev
- Commit Start — eed7007 (eed70076dac5bd1c3f3f71f4dd779f15f3f5a22f full: eed70076dac5bd1c3f3d71f4dd779f15f3f5a22f)
- Commit End — 87544c0 (develop/0.3.0 tip at report time)
- Doctor — 18 PASS / 0 WARN / 0 FAIL (unchanged before and after; 7/7 containers healthy)

POSTGRESQL CDC:
- Mechanism — WAL logical decoding via streaming replication (pure PHP wire
  client: startup, md5/cleartext/SCRAM-SHA-256 auth, simple query, CopyBoth)
- Plugin/Protocol — pgoutput v1 (text tuples), project+run scoped logical
  slot & publication (created only with explicit setup allowance; dropped on
  completion; WAL-retention health reported)
- Start LSN — slot consistent point (e.g. 0/01E7E0F0 on run 21)
- Final LSN — 0/0274C900 family; final-sync closed with applied_through=TRUE
- Insert — PASS
- Update — PASS
- Delete — PASS (replica-identity key tuple; NULL non-key columns filtered)
- Transactions — PASS (delivered whole per COMMIT, incl. multi-table)
- Events Generated / Captured / Applied — 155,000 / 155,000 / 155,000
  (100k storm) + 9/9 and 7/7 and 5/5 in the restart matrices
- Lost Events — 0
- Duplicate Corruption — 0 (idempotent replay verified across restarts)
- Restart Resume — PASS (reader killed mid-run via SIGKILL; resume from the
  last signed LSN; deltas 0)
- Source Restart — PASS (container restart; slot survived; 7/7 applied)
- Target Restart — PASS (target on a separate PostgreSQL instance; capture
  unaffected; two-drain final-sync proven)
- Reconciliation — PASS (counts AND key-set identity: deltas 0)

MYSQL CDC:
- Mechanism — row-based binlog streaming (pure-PHP
  krowinski/php-mysql-replication inside the connector), transaction
  grouping GTID/BEGIN → rows → Xid, delivered per COMMIT
- GTID Mode — ON (executed GTID set recorded in every checkpoint as
  evidence); durable resume = binlog FILE+POSITION at the transaction end
  (the package's COM_BINLOG_DUMP_GTID packet receives only heartbeats from
  MySQL 8.0 — verified live; file+pos resume is equally crash-safe and
  followed rotation binlog.000003 → binlog.000004)
- Start Position — SHOW MASTER STATUS at establish (binlog.000003:23018 on
  the final round)
- Final Position — binlog.000004:1731661 (GTID …:1-87)
- Insert — PASS · Update — PASS · Delete — PASS (PK-narrowed)
- Transactions — PASS (2-INSERT + UPDATE batch delivered atomically)
- Events Generated / Captured / Applied — 15,000 / 15,000 / 15,000 (storm)
  + 7/7 and 5/5 matrices
- Lost Events — 0 · Duplicate Corruption — 0
- Restart Resume — PASS (incl. full container restart + binlog rotation)
- Source Restart — PASS · Reconciliation — PASS (counts + key sets)

MONGODB CDC:
- Mechanism — Change Streams (aggregate $changeStream, fullDocument
  updateLookup, awaitData getMore) over the Phase 28 pure-PHP wire client
- Topology — single-node replica set (mongo:7); probe reports SUPPORTED
- Resume Token Persisted — PASS (real server `_data` token, JSON-safe round
  trip, HMAC-signed in the checkpoint)
- Insert — PASS · Update — PASS (fullDocument) · Delete — PASS (documentKey)
- Events Generated / Captured / Applied — 15,000 / 15,000 / 15,000 (storm;
  source count == target count == 5005) + 4/4 mutation matrix
- Lost Events — 0 · Duplicate Corruption — 0
- Restart Resume — PASS (reader restart + mongod container restart; token
  survived and replayed exactly the post-restart insert)
- Tamper Rejection — PASS (edited `_data` refused with CdcCheckpointTampered;
  capture does NOT restart "from now")
- Schema-drift pause — PASS (a document missing a NOT NULL target field
  paused the stream with the checkpoint intact; operator widened the column;
  capture resumed with zero loss)
- Reconciliation — PASS

CUTOVER CENTER:
- Live CDC State — PASS (normalized telemetry: status, position labels, lag)
- Source Position — PASS (live freeze-boundary capture, audited)
- Applied Position — PASS (signed checkpoint telemetry)
- Live Lag — PASS (lag seconds + provider detail; drained = caught-up proof)
- Lag Threshold Gate — PASS (config-driven warn/block thresholds, §18)
- Unhealthy Lag Blocks — PASS (live-observed BLOCK on stale telemetry and
  disconnected stream; WARN between thresholds observed live)
- Catch-Up Recovery — PASS (capture cycles refresh telemetry; BLOCK→PASS)
- Final Sync Gate — PASS (freeze → two consecutive drained cycles →
  DATA_READY_FOR_CUTOVER, audited; observed on all three providers)

DELIVERY SEMANTICS:
At-least-once delivery with an idempotent applier. The position advances
ONLY after every event it covers has been applied synchronously; a crash
anywhere replays from the last signed checkpoint and converges. Silent
at-most-once loss is impossible by construction. PostgreSQL changes are
delivered per COMMIT (never partially); MySQL row events are grouped per
transaction and delivered on commit; MongoDB single-document ops are
atomic and multi-document transactions apply as independent idempotent
writes (no cross-document atomicity claimed). Deletes are idempotent and
keyed by the plan's primary key. Schema drift PAUSES capture with an
explicit error — the target is never silently corrupted. Full contract:
docs/connectors/REAL_CDC_RUNBOOK.md.

PERFORMANCE:
- Environment — Azure D2as_v6 / 2 vCPU / 8 GiB / Sweden Central /
  Ubuntu 24.04 (VPS: 4.225.217.151 — NOT a universal benchmark)
- PG CDC Throughput — 155,000 events captured+applied in ~184s (~840 ev/s
  incl. apply and checkpoint writes)
- MySQL CDC Throughput — 15,000 events in ~23s (~650 ev/s incl. apply)
- Mongo CDC Throughput — 15,000 events in ~23s (~650 ev/s incl. apply)
- Peak RAM — worker process ~69.5 MiB (bounded; no growth across cycles)
- Free Disk — 47–50 GiB before/after (never below the 20 GiB guard)
- VM Capacity Limited — NO (100k storm completed within resource guards:
  5.8 GiB available RAM, providers tested sequentially)

SECURITY:
- Checkpoint Tamper — PASS (position edits refuse via HMAC mismatch —
  verified on PG, MySQL and MongoDB live)
- Position Scope Isolation — PASS (run transplant + connector swap refused
  by signature context; unit-tested)
- Secret Leaks — 0 (secret-scan PASS; credentials only via vault refs;
  error strings UTF-8-scrubbed before telemetry storage)
- Private Refs — 0 (release private-reference scan applies to artifacts;
  no private hosts/refs introduced — VPS IPs appear only in phase docs)

REGRESSION:
- Targeted — PASS (Phase 25/27/28/29/30/31/32/33/34/35 + ExampleJson +
  CDC core: all Phase 35.6 suites green locally and on the VPS)
- Critical — PASS (45 tests / 123 assertions for Phase 32 + 35.6 combined;
  31 new 35.6 tests)
- Unknown Failures — 0: full-suite comparison at BASE (eed7007) vs HEAD
  shows IDENTICAL pre-existing environment-blocked failures (86 errors /
  30 failures from gate-a/gate-b fixture-dependent classes — documented in
  CONTRIBUTING; proven identical at both commits). No failures were
  introduced or masked by Phase 35.6. Additionally fixed during this
  phase (each with a regression guard): comma-separated schema filter
  silently matching nothing, MySQL capture empty username, relation column
  name shadowing, MySQL resume position semantics, delete key narrowing,
  telemetry UTF-8 scrubbing, drained-based caught-up semantics (PG+Mongo).

INTEGRITY:
- Public Main Modified — NO (origin/main = 945f0c3, verified via ls-remote)
- v0.2.0-rc.3 Modified — NO (tag = 2590c6ab, verified via ls-remote)
- Production Systems Touched — NO (disposable containers on the test VPS
  only; the platform stack stayed 7/7 healthy throughout; test artifacts
  removed afterwards — no abandoned slots/publications/containers)

BLOCKERS:
NONE.

NEXT:
All mandatory CDC acceptance gates PASS → READY_FOR_PHASE_36_RELEASE_CLOSURE.
(Not started automatically, per the phase instruction.)
